<?php
define('_VALID', true);
require 'include/config.php';
require_once 'include/function_server.php';
require_once 'include/function_thumbs.php';

// Proxy de thumbnails/miniclips: o bucket GCS é HNS/UBLA com acesso por IAM,
// então nada é público. O V4 signed-URL está quebrado para esta Service Account
// (até o gcloud oficial gera SignatureDoesNotMatch), então entregamos a mídia
// via streaming server-side com OAuth2 Bearer + Cache-Control. Os callers
// (templates/JS) continuam montando a mesma URL base concatenando o arquivo.
// O parsing de {v}/{arquivo} é unificado em gcs_parse_proxy_request().

$parsed = gcs_parse_proxy_request();
if ($parsed === false) {
    http_response_code(404);
    exit;
}
list($vid, $file) = $parsed;

$file = basename($file);
// Cache-buster de query-string ({url}?{ts}) não faz parte do objeto no bucket.
$file = preg_replace('/\?.*$/', '', $file);
if (!preg_match('/^[A-Za-z0-9._-]+$/', $file)
    || !preg_match('/\.(jpe?g|png|gif|webp|webm|mp4)$/i', $file)) {
    http_response_code(404);
    exit;
}

// Detecta preferência do client por formatos modernos
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$wantsWebP = strpos($accept, 'image/webp') !== false;
$wantsAvif = strpos($accept, 'image/avif') !== false;

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$baseName = basename($file, '.' . $ext);

// Tentar WebP/AVIF se o client aceita e a extensão original é jpg/png
$tryFormats = [];
if (in_array($ext, ['jpg', 'jpeg', 'png']) && ($wantsWebP || $wantsAvif)) {
    if ($wantsAvif) {
        $tryFormats[] = $baseName . '.avif';
    }
    if ($wantsWebP) {
        $tryFormats[] = $baseName . '.webp';
    }
}
$tryFormats[] = $file; // fallback original

$server = gcs_get_server_by_vid($vid);
if (!$server) {
    // Sem servidor GCS vinculado: cai no fallback das thumbs locais.
    $local = get_thumb_dir($vid) . '/' . $file;
    if (file_exists($local)) {
        header('Location: ' . get_thumb_url_local($vid) . '/' . $file, true, 302);
        exit;
    }
    http_response_code(404);
    exit;
}

$object = null;
$contentType = null;
$body = null;
$code = 0;

foreach ($tryFormats as $fmt) {
    $object = 'thumbs/' . $vid . '/' . $fmt;
    list($code, $body, $contentType) = gcs_fetch_object($server, $object);
    if ($code === 200 && $body !== false) {
        break;
    }
}

if ($code !== 200 || $body === false) {
    // Fallback local quando bucket não tem o objeto
    $local = get_thumb_dir($vid) . '/' . $file;
    if (file_exists($local)) {
        header('Location: ' . get_thumb_url_local($vid) . '/' . $file, true, 302);
        exit;
    }
    http_response_code(404);
    exit;
}

// Mini-clipes do hover-preview (video.mp4/webm) são entregues via streaming
// server-side com suporte a Range: gcs_fetch_object carrega o arquivo inteiro
// na memória (CURLOPT_RETURNTRANSFER=true) e estoura timeout/memória para
// vídeo. gcs_stream_object espelha status e headers e faz Range passthrough.
if (preg_match('/\.(mp4|webm)$/i', $file)) {
    if (!gcs_stream_object($server, $object)) {
        // Fallback local quando bucket não tem o objeto
        $local = get_thumb_dir($vid) . '/' . $file;
        if (file_exists($local)) {
            header('Location: ' . get_thumb_url_local($vid) . '/' . $file, true, 302);
            exit;
        }
        http_response_code(404);
    }
    exit;
}

// Mídia derivada é imutável por vídeo: cache longo em browser.
header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
header('Content-Length: ' . strlen($body));
header('Cache-Control: public, max-age=86400');
// Vary: Accept para que proxies/CDN façam cache separado por formato
header('Vary: Accept');
echo $body;
exit;