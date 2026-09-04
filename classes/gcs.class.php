<?php
defined('_VALID') or die('Restricted Access!');

/**
 * Google Cloud Storage client using REST API (no PHP SDK required).
 * Authenticates via Service Account JSON key file.
 */
class GCS
{
    /** @var string Path to the service account JSON key file */
    private $keyFilePath;

    /** @var string GCS bucket name */
    private $bucket;

    /** @var string Cached access token */
    private $accessToken;

    /** @var string Token expiry timestamp */
    private $tokenExpiry;

    /** @var string|null Last error message */
    private $errorMsg;

    /**
     * @param string $keyFilePath Absolute path to the service account JSON key file
     * @param string $bucket      GCS bucket name (e.g. "novinhasbr-cdn1")
     */
    public function __construct($keyFilePath, $bucket)
    {
        $this->keyFilePath = $keyFilePath;
        $this->bucket      = $bucket;
    }

    /**
     * Returns the last error message, or null if no error.
     * @return string|null
     */
    public function getError()
    {
        return $this->errorMsg;
    }

    /**
     * Loads the service account key JSON and returns its contents.
     * @return array|false
     */
    private function loadKey()
    {
        if (!file_exists($this->keyFilePath)) {
            $this->errorMsg = 'Arquivo de chave do Service Account não encontrado: ' . $this->keyFilePath;
            return false;
        }

        $json = file_get_contents($this->keyFilePath);
        if ($json === false) {
            $this->errorMsg = 'Falha ao ler o arquivo de chave JSON.';
            return false;
        }

        $key = json_decode($json, true);
        if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            $this->errorMsg = 'Arquivo JSON inválido ou campos obrigatórios ausentes (client_email, private_key).';
            return false;
        }

        return $key;
    }

    /**
     * Generates a JWT, signs it with the service account private key,
     * and exchanges it for an OAuth2 access token via Google's token endpoint.
     *
     * @return string|false Access token string on success, false on failure.
     */
    private function getAccessToken()
    {
        // Return cached token if still valid (with 60s buffer)
        if ($this->accessToken && $this->tokenExpiry && time() < ($this->tokenExpiry - 60)) {
            return $this->accessToken;
        }

        $key = $this->loadKey();
        if (!$key) {
            return false;
        }

        $now   = time();
        $scope = 'https://www.googleapis.com/auth/devstorage.read_write';

        // --- Build Header ---
        $header = array(
            'alg' => 'RS256',
            'typ' => 'JWT'
        );
        $headerB64 = $this->base64url(json_encode($header));

        // --- Build Payload ---
        $payload = array(
            'iss'   => $key['client_email'],
            'scope' => $scope,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600
        );
        $payloadB64 = $this->base64url(json_encode($payload));

        // --- Sign ---
        $signInput = $headerB64 . '.' . $payloadB64;
        $signature = '';
        $pkey      = openssl_pkey_get_private($key['private_key']);
        if (!$pkey) {
            $this->errorMsg = 'Falha ao carregar a chave privada do Service Account.';
            return false;
        }

        $signOk = openssl_sign($signInput, $signature, $pkey, OPENSSL_ALGO_SHA256);
        openssl_pkey_free($pkey);

        if (!$signOk) {
            $this->errorMsg = 'Falha ao assinar o JWT.';
            return false;
        }

        $signatureB64 = $this->base64url($signature);
        $jwt = $signInput . '.' . $signatureB64;

        // --- Exchange JWT for Access Token ---
        $postFields = http_build_query(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt
        ));

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/x-www-form-urlencoded'
            )
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $this->errorMsg = 'Erro cURL ao obter access token: ' . $curlErr;
            return false;
        }

        $result = json_decode($response, true);
        if ($httpCode !== 200 || empty($result['access_token'])) {
            $msg = isset($result['error_description']) ? $result['error_description'] : 'Erro desconhecido';
            $this->errorMsg = 'Falha ao obter access token (HTTP ' . $httpCode . '): ' . $msg;
            return false;
        }

        $this->accessToken  = $result['access_token'];
        $this->tokenExpiry  = $now + (isset($result['expires_in']) ? intval($result['expires_in']) : 3600);

        return $this->accessToken;
    }

    /**
     * Uploads a local file to the GCS bucket.
     *
     * By default objects are uploaded WITHOUT any public ACL (private bucket,
     * access only through V4 signed URLs). Pass ['acl' => 'publicRead'] only
     * for objects that must be publicly readable (e.g. thumbnails).
     *
     * @param string $localPath   Absolute path to the local file
     * @param string $objectName  Destination object name in the bucket (e.g. "18_1080p.mp4")
     * @param string $contentType MIME type (e.g. "video/mp4")
     * @param array  $options     Optional: ['acl' => 'publicRead', 'cacheControl' => '...']
     * @return string|false       The gs:// URI on success, false on failure
     */
    public function upload($localPath, $objectName, $contentType = 'application/octet-stream', $options = array())
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        if (!file_exists($localPath)) {
            $this->errorMsg = 'Arquivo local não encontrado: ' . $localPath;
            return false;
        }

        $fileSize  = filesize($localPath);
        $mimeType  = $contentType;
        $acl       = isset($options['acl']) ? $options['acl'] : null;
        $cacheCtrl = isset($options['cacheControl']) ? $options['cacheControl'] : 'private, max-age=0, no-store';

        // Use multipart upload for files < 5MB, resumable for larger
        if ($fileSize <= 5 * 1024 * 1024) {
            return $this->uploadMultipart($localPath, $objectName, $mimeType, $acl, $cacheCtrl, $token);
        } else {
            return $this->uploadResumable($localPath, $objectName, $mimeType, $acl, $cacheCtrl, $token, $fileSize);
        }
    }

    /**
     * Simple multipart upload for small files.
     */
    private function uploadMultipart($localPath, $objectName, $mimeType, $acl, $cacheCtrl, $token)
    {
        $boundary  = 'avs_gcs_' . md5(microtime(true));
        $body      = '';
        $fileData  = file_get_contents($localPath);

        // Metadata part
        $meta = array(
            'cacheControl' => $cacheCtrl
        );
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: application/json; charset=UTF-8' . "\r\n\r\n";
        $body .= json_encode($meta) . "\r\n";

        // File part
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: ' . $mimeType . "\r\n";
        $body .= 'Content-Transfer-Encoding: binary' . "\r\n\r\n";
        $body .= $fileData . "\r\n";
        $body .= '--' . $boundary . '--';

        $url = 'https://storage.googleapis.com/upload/storage/v1/b/'
             . urlencode($this->bucket) . '/o?uploadType=multipart&name=' . urlencode($objectName);

        $headers = array(
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/related; boundary=' . $boundary
        );
        if ($acl) {
            $headers[] = 'X-Goog-Acl: ' . strtolower($acl);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HTTPHEADER     => $headers
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $this->errorMsg = 'Erro cURL no upload: ' . $curlErr;
            return false;
        }

        $result = json_decode($response, true);
        if ($httpCode !== 200) {
            $msg = isset($result['error']['message']) ? $result['error']['message'] : 'Erro desconhecido';
            $this->errorMsg = 'Falha no upload (HTTP ' . $httpCode . '): ' . $msg;
            return false;
        }

        return 'gs://' . $this->bucket . '/' . $objectName;
    }

    /**
     * Resumable upload for large files (recommended for video files).
     */
    private function uploadResumable($localPath, $objectName, $mimeType, $acl, $cacheCtrl, $token, $fileSize)
    {
        // Step 1: Initiate resumable session
        $url = 'https://storage.googleapis.com/upload/storage/v1/b/'
             . urlencode($this->bucket) . '/o?uploadType=resumable&name=' . urlencode($objectName);

        $meta = array(
            'cacheControl' => $cacheCtrl
        );

        // Capture Location header via callback
        $uploadUri = null;
        $headerCallback = function($ch, $headerLine) use (&$uploadUri) {
            if (stripos($headerLine, 'Location:') === 0) {
                $uploadUri = trim(substr($headerLine, 9));
            }
            return strlen($headerLine);
        };

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => $headerCallback,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Length: ' . $fileSize,
                'X-Upload-Content-Type: ' . $mimeType
            ),
            CURLOPT_POSTFIELDS => json_encode($meta)
        ));

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $this->errorMsg = 'Erro cURL ao iniciar upload resumível: ' . $curlErr;
            return false;
        }

        if (!$uploadUri) {
            $this->errorMsg = 'Não foi possível obter a URI de upload resumível. HTTP ' . $httpCode;
            return false;
        }

        // Step 2: Upload the file
        $fp = fopen($localPath, 'rb');
        if (!$fp) {
            $this->errorMsg = 'Falha ao abrir o arquivo local para leitura.';
            return false;
        }

        $headers = array(
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . $fileSize
        );
        if ($acl) {
            $headers[] = 'X-Goog-Acl: ' . strtolower($acl);
        }

        $ch = curl_init($uploadUri);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_INFILE         => $fp,
            CURLOPT_INFILESIZE     => $fileSize,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => $headers
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        fclose($fp);
        curl_close($ch);

        if ($curlErr) {
            $this->errorMsg = 'Erro cURL durante o upload resumível: ' . $curlErr;
            return false;
        }

        $result = json_decode($response, true);
        if ($httpCode !== 200) {
            $msg = isset($result['error']['message']) ? $result['error']['message'] : 'Erro desconhecido';
            $this->errorMsg = 'Falha no upload resumível (HTTP ' . $httpCode . '): ' . $msg;
            return false;
        }

        return 'gs://' . $this->bucket . '/' . $objectName;
    }

    /**
     * Tests the connection by listing buckets (validates credentials).
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection()
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return array('success' => false, 'message' => $this->errorMsg);
        }

        // Try to get bucket metadata to validate access
        $url = 'https://storage.googleapis.com/storage/v1/b/' . urlencode($this->bucket);

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_HTTPGET         => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_HTTPHEADER      => array(
                'Authorization: Bearer ' . $token
            )
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return array('success' => false, 'message' => 'Erro cURL: ' . $curlErr);
        }

        $result = json_decode($response, true);

        if ($httpCode === 200 && isset($result['name'])) {
            $location = isset($result['location']) ? $result['location'] : 'N/A';
            $storageClass = isset($result['storageClass']) ? $result['storageClass'] : 'N/A';
            return array(
                'success' => true,
                'message' => 'Conexão com Google Cloud Storage estabelecida! Bucket: <b>'
                    . htmlspecialchars($result['name'], ENT_QUOTES, 'UTF-8')
                    . '</b> | Localização: ' . $location
                    . ' | Classe: ' . $storageClass
            );
        }

        $msg = isset($result['error']['message']) ? $result['error']['message'] : 'Erro HTTP ' . $httpCode;
        return array('success' => false, 'message' => 'Falha ao acessar o bucket: ' . $msg);
    }

    /**
     * Tests write permission by uploading a small test file.
     * @return array ['success' => bool, 'message' => string]
     */
    public function testWrite()
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return array('success' => false, 'message' => $this->errorMsg);
        }

        $testContent = 'AVS GCS Test ' . date('Y-m-d H:i:s');
        $testFile    = sys_get_temp_dir() . '/avs_gcs_test_' . time() . '.tmp';
        file_put_contents($testFile, $testContent);

        $testObjectName = '_avs_test/test_' . time() . '.txt';
        // Upload as private to reflect production behavior (signed URLs)
        $result = $this->upload($testFile, $testObjectName, 'text/plain');

        @unlink($testFile);

        if ($result !== false) {
            // Delete the test file from GCS
            $this->deleteObject($testObjectName);
            return array(
                'success' => true,
                'message' => 'Conexão e permissão de escrita verificadas com sucesso! Bucket acessível.'
            );
        }

        return array('success' => false, 'message' => $this->errorMsg);
    }

    /**
     * Deletes an object from the bucket.
     * @param string $objectName
     * @return bool
     */
    public function deleteObject($objectName)
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        $url = 'https://storage.googleapis.com/storage/v1/b/'
             . urlencode($this->bucket) . '/o/' . urlencode($objectName);

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $token
            )
        ));

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 204);
    }

    /**
     * Server-side copy of an object within the same bucket (no download).
     * Used to reorganize objects (e.g. h264/88_720p.mp4 -> h264/88/720p.mp4).
     * @param string $sourceObject
     * @param string $destObject
     * @return bool
     */
    public function copyObject($sourceObject, $destObject)
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        $url = 'https://storage.googleapis.com/storage/v1/b/'
             . urlencode($this->bucket) . '/o/' . urlencode($sourceObject)
             . '/copyTo/b/' . urlencode($this->bucket) . '/o/' . urlencode($destObject);

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $token,
                'Content-Length: 0'
            )
        ));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            $this->errorMsg = 'Falha ao copiar objeto (HTTP ' . $httpCode . '): ' . substr((string)$response, 0, 200);
            return false;
        }

        return true;
    }

    /**
     * Generates the gs:// URI for an object in this bucket.
     * @param string $objectName
     * @return string e.g. "gs://novinhasbr-cdn1/18_1080p.mp4"
     */
    public function getGsUri($objectName)
    {
        return 'gs://' . $this->bucket . '/' . ltrim($objectName, '/');
    }

    /**
     * Generates the public HTTPS URL for an object (only works if the bucket
     * or object is publicly readable).
     * @param string $objectName
     * @return string e.g. "https://storage.googleapis.com/novinhasbr-cdn1/18_1080p.mp4"
     */
    public function getPublicUrl($objectName)
    {
        return 'https://storage.googleapis.com/' . $this->bucket . '/' . ltrim($objectName, '/');
    }

    /**
     * Generates a short-lived V4 signed URL for an object in a PRIVATE bucket.
     *
     * The bucket stays private; every page view issues fresh expiring URLs, so
     * players never touch a public, guessable .mp4 endpoint. Supports standard
     * HTTP Range requests, so seeking/streaming work out of the box.
     *
     * @param string $objectName Object name in the bucket (e.g. "h264/18/1080p.mp4")
     * @param int    $ttl        Lifetime in seconds (default 21600 = 6h)
     * @return string|false      The https://... signed URL, or false on failure
     */
    public function getSignedUrl($objectName, $ttl = 21600)
    {
        $key = $this->loadKey();
        if (!$key) {
            return false;
        }

        $ttl = intval($ttl);
        if ($ttl <= 0 || $ttl > 604800) {
            $ttl = 21600; // GCS allows 1..604800 seconds
        }

        $host          = 'storage.googleapis.com';
        $now           = time();
        $dateStamp     = gmdate('Ymd', $now);
        $requestTs     = gmdate('Ymd\THis\Z', $now);
        $scope         = $dateStamp . '/auto/storage/goog4_request';
        $clientEmail   = $key['client_email'];
        $objectName    = '/' . ltrim($objectName, '/');
        $canonicalUri  = $this->canonicalizePath('/' . $this->bucket . $objectName);
        $credential    = $clientEmail . '/' . $scope;

        $query = array(
            'X-Goog-Algorithm'     => 'GOOG4-RSA-SHA256',
            'X-Goog-Credential'    => $credential,
            'X-Goog-Date'          => $requestTs,
            'X-Goog-Expires'       => $ttl,
            'X-Goog-SignedHeaders' => 'host'
        );

        // Canonical query string must be sorted by key and RFC 3986 encoded
        ksort($query);
        $canonicalQuery = '';
        foreach ($query as $k => $v) {
            $canonicalQuery .= ($canonicalQuery === '' ? '' : '&')
                             . $this->urlEncodeRFC3986($k) . '=' . $this->urlEncodeRFC3986($v);
        }

        $canonicalRequest = "GET\n" . $canonicalUri . "\n" . $canonicalQuery
                          . "\nhost:" . $host . "\n\nhost\nUNSIGNED-PAYLOAD";

        $stringToSign = "GOOG4-RSA-SHA256\n" . $requestTs . "\n" . $scope . "\n"
                      . hash('sha256', $canonicalRequest);

        $signature = '';
        $pkey      = openssl_pkey_get_private($key['private_key']);
        if (!$pkey) {
            $this->errorMsg = 'Falha ao carregar a chave privada do Service Account para assinatura V4.';
            return false;
        }
        // openssl_pkey_free() was removed/soft-deprecated on PHP 8.x — the key
        // object is freed automatically when it goes out of scope.
        $signOk = openssl_sign($stringToSign, $signature, $pkey, OPENSSL_ALGO_SHA256);

        if (!$signOk) {
            $this->errorMsg = 'Falha ao assinar a URL (V4).';
            return false;
        }

        // GCS V4 expects the RSA signature HEX-encoded (verified against the
        // official google-cloud-storage lib: base64 signatures get rejected
        // with SignatureDoesNotMatch).
        $signedQuery = $canonicalQuery
                     . '&X-Goog-Signature=' . bin2hex($signature);

        return 'https://' . $host . $canonicalUri . '?' . $signedQuery;
    }

    /**
     * Returns the current CORS configuration of the bucket.
     * @return array|false Array of CORS rules, or false on failure
     */
    public function getCors()
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        $url = 'https://storage.googleapis.com/storage/v1/b/' . urlencode($this->bucket)
             . '?fields=cors,name';

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_HTTPGET         => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_TIMEOUT         => 15,
            CURLOPT_HTTPHEADER      => array('Authorization: Bearer ' . $token)
        ));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            $this->errorMsg = 'Falha ao ler CORS do bucket (HTTP ' . $httpCode . '): ' . $curlErr;
            return false;
        }

        $result = json_decode($response, true);
        return isset($result['cors']) ? $result['cors'] : array();
    }

    /**
     * Configures the bucket CORS rules. Media Bunny reads objects via
     * cross-origin requests, so the site origin MUST be allowed.
     *
     * @param array $origins e.g. ['https://novinhasbr.net']
     * @return bool
     */
    public function setCors($origins)
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        $origins = array_values(array_unique(array_filter(array_map('trim', (array) $origins))));
        if (empty($origins)) {
            $this->errorMsg = 'Nenhuma origem informada para a regra CORS.';
            return false;
        }

        $body = json_encode(array(
            'cors' => array(
                array(
                    'origin'         => $origins,
                    'method'         => array('GET', 'HEAD', 'OPTIONS'),
                    'responseHeader' => array('Content-Type', 'Content-Range', 'Accept-Ranges', 'Range', 'Content-Length'),
                    'maxAgeSeconds'  => 3600
                )
            )
        ));

        $url = 'https://storage.googleapis.com/storage/v1/b/' . urlencode($this->bucket);
        $ch  = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            )
        ));
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->errorMsg = 'Falha ao configurar CORS do bucket (HTTP ' . $httpCode . ').';
            return false;
        }
        return true;
    }

    /**
     * Checks whether a given origin is allowed by the bucket CORS config.
     * @param string $origin e.g. "https://novinhasbr.net"
     * @return bool
     */
    public function testCors($origin)
    {
        $cors = $this->getCors();
        if (!is_array($cors)) {
            return false;
        }
        $origin = rtrim(trim($origin), '/');
        foreach ($cors as $rule) {
            if (empty($rule['origin']) || !is_array($rule['origin'])) {
                continue;
            }
            if (in_array($origin, $rule['origin']) || in_array('*', $rule['origin'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lists object names in the bucket, optionally filtered by prefix.
     * @param string $prefix e.g. "h264/"
     * @return array|false List of object names, or false on failure
     */
    public function listObjects($prefix = '')
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        $names = array();
        $pageToken = '';
        do {
            $url = 'https://storage.googleapis.com/storage/v1/b/' . urlencode($this->bucket)
                 . '/o?fields=items%2Fname%2CnextPageToken&maxResults=1000';
            if ($prefix !== '') {
                $url .= '&prefix=' . urlencode($prefix);
            }
            if ($pageToken !== '') {
                $url .= '&pageToken=' . urlencode($pageToken);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_HTTPGET         => true,
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_TIMEOUT         => 60,
                CURLOPT_HTTPHEADER      => array('Authorization: Bearer ' . $token)
            ));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->errorMsg = 'Falha ao listar objetos (HTTP ' . $httpCode . ').';
                return false;
            }
            $result = json_decode($response, true);
            if (!empty($result['items'])) {
                foreach ($result['items'] as $item) {
                    $names[] = $item['name'];
                }
            }
            $pageToken = isset($result['nextPageToken']) ? $result['nextPageToken'] : '';
        } while ($pageToken !== '');

        return $names;
    }

    /**
     * Removes the public (allUsers) read ACL from an object, making it private.
     * @param string $objectName
     * @return bool
     */
    public function removePublicAcl($objectName)
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        $url = 'https://storage.googleapis.com/storage/v1/b/'
             . urlencode($this->bucket) . '/o/' . urlencode($objectName) . '/acl/allUsers';

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token)
        ));
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 204 = removed, 404 = no public ACL (already private)
        return ($httpCode === 204 || $httpCode === 404);
    }

    /**
     * Percent-encodes a URI path while preserving '/' separators.
     * @param string $path
     * @return string
     */
    private function canonicalizePath($path)
    {
        $segments = explode('/', $path);
        foreach ($segments as $k => $seg) {
            if ($seg !== '') {
                $segments[$k] = $this->urlEncodeRFC3986($seg);
            }
        }
        return implode('/', $segments);
    }

    /**
     * RFC 3986 percent-encoding (GCS V4 signing requires this, not rawurlencode).
     * @param string $str
     * @return string
     */
    private function urlEncodeRFC3986($str)
    {
        return str_replace('%7E', '~', rawurlencode($str));
    }

    /**
     * Base64url encode (RFC 4648).
     * @param string $data
     * @return string
     */
    private function base64url($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
?>
