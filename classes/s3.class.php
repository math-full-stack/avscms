<?php
defined('_VALID') or die('Restricted Access!');

/**
 * Cliente S3-compatível — usado para Cloudflare R2 (egress zero) sem SDK.
 *
 * Espelha a superfície pública de `classes/gcs.class.php`
 * (upload / listObjects / deleteObject / deleteFolder / testConnection /
 * testWrite / getPublicUrl / getError) para que os helpers de mídia de
 * include/function_server.php atendam os dois backends sem branch duplicado.
 *
 * Diferenças relevantes em relação ao GCS:
 *   - autenticação por HMAC (AWS SigV4) com Access Key/Secret, não OAuth2;
 *   - objetos são servidos por uma base pública própria ($publicBase), então
 *     a LEITURA não precisa de assinatura (ao contrário do GCS privado);
 *   - o endpoint é path-style: https://<account>.r2.cloudflarestorage.com/<bucket>/<key>.
 *
 * Referência: https://developers.cloudflare.com/r2/api/s3/api/
 */
class S3
{
    /** @var string Endpoint da API S3 (sem barra final), ex: https://<account>.r2.cloudflarestorage.com */
    private $endpoint;

    /** @var string Nome do bucket */
    private $bucket;

    /** @var string Access Key ID */
    private $accessKey;

    /** @var string Secret Access Key */
    private $secretKey;

    /** @var string Região de assinatura (R2 usa "auto") */
    private $region;

    /** @var string Base pública de leitura (ex: https://pub-xxxx.r2.dev) */
    private $publicBase;

    /** @var string|null Última mensagem de erro */
    private $errorMsg;

    /**
     * @param string $endpoint   Endpoint S3 (sem barra final)
     * @param string $bucket     Nome do bucket
     * @param string $accessKey  Access Key ID
     * @param string $secretKey  Secret Access Key
     * @param string $region     Região (R2: "auto")
     * @param string $publicBase Base pública para montar URLs de leitura
     */
    public function __construct($endpoint, $bucket, $accessKey, $secretKey, $region = 'auto', $publicBase = '')
    {
        $this->endpoint   = rtrim((string)$endpoint, '/');
        $this->bucket     = (string)$bucket;
        $this->accessKey  = (string)$accessKey;
        $this->secretKey  = (string)$secretKey;
        $this->region     = ($region !== '' ? (string)$region : 'auto');
        $this->publicBase = rtrim((string)$publicBase, '/');
    }

    /**
     * Última mensagem de erro (null quando não houve).
     * @return string|null
     */
    public function getError()
    {
        return $this->errorMsg;
    }

    /**
     * URL pública de leitura de um objeto (sem assinatura — R2 não cobra egress).
     *
     * @param string $objectName Caminho do objeto no bucket
     * @return string
     */
    public function getPublicUrl($objectName)
    {
        return $this->publicBase . '/' . ltrim($objectName, '/');
    }

    /**
     * URI interna no formato do app (equivalente ao gs:// do GCS).
     *
     * @param string $objectName
     * @return string
     */
    public function getS3Uri($objectName)
    {
        return 's3://' . $this->bucket . '/' . ltrim($objectName, '/');
    }

    /**
     * Envia um arquivo local para o bucket (PutObject).
     *
     * @param string $localPath  Caminho do arquivo local
     * @param string $objectName Chave de destino (ex: h264/109/720p.mp4)
     * @param string $contentType
     * @param array  $options    ['cacheControl' => '...']
     * @return string|false URI s3://... em sucesso; false em falha
     */
    public function upload($localPath, $objectName, $contentType = 'application/octet-stream', $options = array())
    {
        if (!is_file($localPath)) {
            $this->errorMsg = 'Arquivo local não encontrado: ' . $localPath;
            return false;
        }

        $cacheControl = isset($options['cacheControl'])
            ? $options['cacheControl']
            : 'public, max-age=31536000';

        $status = 0;
        $ok = $this->request('PUT', $objectName, array(), $localPath, $contentType, $cacheControl, $status);

        if (!$ok) {
            if ($this->errorMsg === null) {
                $this->errorMsg = 'Falha no upload (HTTP ' . $status . ').';
            }
            return false;
        }

        return $this->getS3Uri($objectName);
    }

    /**
     * Lista as chaves do bucket (paginado, ListObjectsV2).
     *
     * Mesmo formato de retorno do GCS::listObjects(): array de nomes.
     *
     * @param string $prefix
     * @return array|false
     */
    public function listObjects($prefix = '')
    {
        $names  = array();
        $token  = '';
        $page   = 0;

        do {
            $query = array('list-type' => '2', 'max-keys' => '1000');
            if ($prefix !== '') {
                $query['prefix'] = $prefix;
            }
            if ($token !== '') {
                $query['continuation-token'] = $token;
            }

            $status   = 0;
            $body     = '';
            $ok = $this->request('GET', '', $query, null, '', '', $status, $body);

            if (!$ok || $status !== 200) {
                $this->errorMsg = 'Falha ao listar objetos (HTTP ' . $status . ').';
                return false;
            }

            $xml = @simplexml_load_string($body);
            if ($xml === false) {
                $this->errorMsg = 'Resposta inválida do ListObjectsV2.';
                return false;
            }

            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $obj) {
                    $names[] = (string)$obj->Key;
                }
            }

            $token = isset($xml->NextContinuationToken) ? (string)$xml->NextContinuationToken : '';
            $page++;
        } while ($token !== '' && $page < 100);

        return $names;
    }

    /**
     * Remove um objeto.
     *
     * @param string $objectName
     * @return bool
     */
    public function deleteObject($objectName)
    {
        $status = 0;
        $ok = $this->request('DELETE', $objectName, array(), null, '', '', $status);

        if (!$ok || ($status !== 204 && $status !== 200)) {
            $this->errorMsg = 'Falha ao remover objeto (HTTP ' . $status . ').';
            return false;
        }

        return true;
    }

    /**
     * Remove todos os objetos de um "diretório" (todos os que têm o prefixo).
     * Espelha GCS::deleteFolder().
     *
     * @param string $folderName Prefixo (ex: thumbs/109/)
     * @return bool
     */
    public function deleteFolder($folderName)
    {
        $list = $this->listObjects($folderName);
        if ($list === false) {
            return false;
        }

        foreach ($list as $name) {
            if (!$this->deleteObject($name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Testa credenciais/acesso (listagem de 1 chave).
     *
     * Mesmo formato de retorno de GCS::testConnection() para o botão
     * "Testar Conexão" do siteadmin funcionar sem branch.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function testConnection()
    {
        $status = 0;
        $body   = '';
        $ok = $this->request('GET', '', array('list-type' => '2', 'max-keys' => '1'), null, '', '', $status, $body);

        if ($ok && $status === 200) {
            return array(
                'success' => true,
                'message' => 'Conexão OK com o bucket <b>' . htmlspecialchars($this->bucket, ENT_QUOTES, 'UTF-8') . '</b> (R2).'
            );
        }

        return array(
            'success' => false,
            'message' => 'Falha ao conectar no bucket: ' . htmlspecialchars((string)$this->errorMsg, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Testa escrita real (PUT + DELETE de um objeto pequeno).
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function testWrite()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'avs_s3_');
        if ($tmp === false) {
            return array('success' => false, 'message' => 'Não foi possível criar arquivo temporário.');
        }

        file_put_contents($tmp, 'avscms-write-test');
        $key = '.avscms-write-test';
        $ok  = $this->upload($tmp, $key, 'text/plain', array('cacheControl' => 'no-store'));
        @unlink($tmp);

        if ($ok === false) {
            return array(
                'success' => false,
                'message' => 'Falha ao escrever no bucket: ' . htmlspecialchars((string)$this->errorMsg, ENT_QUOTES, 'UTF-8')
            );
        }

        $this->deleteObject($key);

        return array('success' => true, 'message' => 'Escrita OK (upload + delete de teste).');
    }

    /**
     * Executa uma requisição assinada (SigV4).
     *
     * @param string      $method
     * @param string      $objectName    Chave do objeto ('' para operações no bucket)
     * @param array       $query         Query string (sem assinatura/ordem: ordenada aqui)
     * @param string|null $payloadFile   Arquivo local como corpo (PUT)
     * @param string      $contentType
     * @param string      $cacheControl
     * @param int         $statusOut     HTTP status de saída
     * @param string      $bodyOut       Corpo da resposta (para listagem)
     * @return bool
     */
    private function request($method, $objectName, $query, $payloadFile, $contentType, $cacheControl, &$statusOut, &$bodyOut = null)
    {
        $statusOut = 0;
        $bodyOut   = '';

        if ($this->endpoint === '' || $this->bucket === '' || $this->accessKey === '' || $this->secretKey === '') {
            $this->errorMsg = 'Configuração S3 incompleta (endpoint, bucket, access key ou secret).';
            return false;
        }

        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME);
        if (!$host || !$scheme) {
            $this->errorMsg = 'Endpoint S3 inválido: ' . $this->endpoint;
            return false;
        }

        // --- Payload hash ---
        if ($payloadFile !== null) {
            $payloadHash = hash_file('sha256', $payloadFile);
            if ($payloadHash === false) {
                $this->errorMsg = 'Falha ao calcular o hash do arquivo.';
                return false;
            }
        } else {
            $payloadHash = hash('sha256', '');
        }

        // --- Canonical URI (path-style: /bucket/key com cada segmento encoded) ---
        $uri = '/' . $this->uriEncode($this->bucket, true);
        if ($objectName !== '') {
            $uri .= '/' . ltrim($this->uriEncodePath($objectName), '/');
        }

        // --- Canonical query ---
        ksort($query);
        $queryParts = array();
        foreach ($query as $k => $v) {
            $queryParts[] = $this->uriEncode($k, true) . '=' . $this->uriEncode((string)$v, true);
        }
        $canonicalQuery = implode('&', $queryParts);

        // --- Headers ---
        $amzDate = gmdate('Ymd\THis\Z');
        $datestamp = substr($amzDate, 0, 8);

        $headers = array(
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
        );
        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }
        if ($cacheControl !== '') {
            $headers['cache-control'] = $cacheControl;
        }

        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim($v) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = $method . "\n"
            . $uri . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $scope       = $datestamp . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = $this->signingKey($datestamp);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        // --- Monta e dispara ---
        $url = $scheme . '://' . $host . $uri;
        if ($canonicalQuery !== '') {
            $url .= '?' . $canonicalQuery;
        }

        $curlHeaders = array('Authorization: ' . $authorization);
        foreach ($headers as $k => $v) {
            if ($k === 'host') {
                continue;
            }
            $curlHeaders[] = $k . ': ' . trim($v);
        }

        $ch = curl_init($url);
        $opts = array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 0,
        );

        $fh = null;
        if ($payloadFile !== null) {
            $fh = @fopen($payloadFile, 'rb');
            if (!$fh) {
                $this->errorMsg = 'Não foi possível abrir o arquivo para envio: ' . $payloadFile;
                curl_close($ch);
                return false;
            }
            $opts[CURLOPT_UPLOAD]      = true;
            $opts[CURLOPT_INFILE]      = $fh;
            $opts[CURLOPT_INFILESIZE]  = filesize($payloadFile);
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $statusOut = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($fh) {
            fclose($fh);
        }

        if ($body === false || $curlErr !== '') {
            $this->errorMsg = 'Erro de rede: ' . $curlErr;
            return false;
        }

        $bodyOut = (string)$body;

        if ($statusOut >= 200 && $statusOut < 300) {
            return true;
        }

        // Guarda o corpo do erro (R2 devolve XML com o motivo) para diagnóstico.
        $this->errorMsg = 'HTTP ' . $statusOut . ': ' . trim(substr($bodyOut, 0, 300));

        return false;
    }

    /**
     * Chave de assinatura derivada (HMAC em cadeia do SigV4).
     *
     * @param string $datestamp YYYYMMDD
     * @return string
     */
    private function signingKey($datestamp)
    {
        $kDate    = hash_hmac('sha256', $datestamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * Codificação RFC3986 (a mesma exigida pelo SigV4).
     *
     * @param string $value
     * @param bool   $encodeSlash false preserva "/" (para o path do objeto)
     * @return string
     */
    private function uriEncode($value, $encodeSlash = false)
    {
        $result = '';
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $ch = $value[$i];
            if (ctype_alnum($ch) || $ch === '-' || $ch === '_' || $ch === '.' || $ch === '~') {
                $result .= $ch;
            } elseif ($ch === '/' && !$encodeSlash) {
                $result .= '/';
            } else {
                $result .= '%' . strtoupper(bin2hex($ch));
            }
        }

        return $result;
    }

    /**
     * Encode de um path de objeto preservando as barras.
     *
     * @param string $path
     * @return string
     */
    private function uriEncodePath($path)
    {
        return $this->uriEncode(ltrim($path, '/'), false);
    }
}
