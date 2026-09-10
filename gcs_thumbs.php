<?php
define('_VALID', true);
require 'include/config.php';
require 'include/function_server.php';
require 'include/function_thumbs.php';

// Proxy de thumbnails/miniclips: o bucket GCS é HNS/UBLA com acesso por IAM,
// então nada é público. O V4 signed-URL está quebrado para esta Service Account
// (até o gcloud oficial gera SignatureDoesNotMatch), então entregamos a mídia
// via streaming server-side com OAuth2 Bearer + Cache-Control. Os callers
// (templates/JS) continuam montando a mesma URL base concatenando o arquivo.

$vidFile = ltrim(isset($_GET['v']) ? trim((string)$_GET['v']) : '', '/');
$file    = isset($_GET['f']) ? trim((string)$_GET['f']) : '';

if (strpos($vidFile, '/') !== false) {
    list($vidFile, $file) = explode('/', $vidFile, 2);
    $file = isset($file) ? trim((string)$file) : '';
}

$vid = intval($vidFile);
if ($vid <= 0 || $file === '') {
    http_response_code(404);
    exit;
}

$file = basename($file);
if (!preg_match('/^[A-Za-z0-9._-]+$/', $file)
    || !preg_match('/\.(jpe?g|png|gif|webp|webm|mp4)$/i', $file)) {
    http_response_code(404);
    exit;
}

$sql = "SELECT server FROM video WHERE VID = " . $vid . " LIMIT 1";
$rs  = $conn->execute($sql);
if ($conn->Affected_Rows() != 1 || empty($rs->fields['server'])) {
    $local = get_thumb_dir($vid) . '/' . $file;
    if (file_exists($local)) {
        header('Location: ' . get_thumb_url_local($vid) . '/' . $file, true, 302);
        exit;
    }
    http_response_code(404);
    exit;
}

$server = get_server_by_video_url($rs->fields['server']);
if (!$server || !isset($server['server_type']) || $server['server_type'] !== 'gcs') {
    http_response_code(404);
    exit;
}

$gcs = gcs_get_client($server);
if (!$gcs) {
    http_response_code(404);
    exit;
}

$token = $gcs->getReadAccessToken();
if ($token === false) {
    http_response_code(404);
    exit;
}

$object = 'thumbs/' . $vid . '/' . $file;
$url    = 'https://storage.googleapis.com/storage/v1/b/' . urlencode($server['gcs_bucket'])
        . '/o/' . rawurlencode($object) . '?alt=media';

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30
));
$body    = curl_exec($ch);
$code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($code !== 200 || $body === false) {
    http_response_code(404);
    exit;
}

// Mídia derivada é imutável por vídeo: cache longo em browser.
header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
header('Content-Length: ' . strlen($body));
header('Cache-Control: public, max-age=86400');
echo $body;
exit;