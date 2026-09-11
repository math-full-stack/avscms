<?php
define('_VALID', true);
require 'include/config.php';
require 'include/function_server.php';

// Streaming longo: evita corte por max_execution_time.
@set_time_limit(0);

// Proxy de vídeo GCS: o bucket é privado (UBLA/IAM) e o V4 signed-URL está
// quebrado para esta Service Account (SignatureDoesNotMatch), então a mídia
// é entregue via streaming server-side com OAuth2 Bearer + Range. O transporte
// vive em gcs_stream_object() (include/function_server.php), usado também por
// gcs_thumbs.php.
//
// Dois formatos de URL aceitos:
//   /gcs_video.php?v={vid}/{objeto}      (slash-style, gerado pelo app)
//   /gcs_video.php?v={vid}&f={objeto}    (legado)
// 'objeto' é a chave no bucket, ex.: h264/109/720p.mp4 | iphone/109.mp4.
// O parsing é unificado em gcs_parse_proxy_request() (function_server.php).

$parsed = gcs_parse_proxy_request();
if ($parsed === false) {
    http_response_code(404);
    exit;
}
list($vid, $object) = $parsed;

// Objeto tem que ser mídia de vídeo e referenciar o próprio VID, ex.:
//   h264/109/720p.mp4 | iphone/109.mp4 | hd/109.mp4
$parts = explode('/', $object);
$prefix = array_shift($parts);
if (!in_array($prefix, array('h264', 'iphone', 'hd'), true)) {
    http_response_code(404);
    exit;
}

$file = array_pop($parts);
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $file)
    || !preg_match('/\.(mp4|webm)$/i', $file)) {
    http_response_code(404);
    exit;
}

if (!empty($parts)) {
    $dirVid = array_shift($parts);
    if (!ctype_digit($dirVid) || intval($dirVid) !== $vid) {
        http_response_code(404);
        exit;
    }
} else {
    $fileBase = strtolower(pathinfo($file, PATHINFO_FILENAME));
    if (!ctype_digit($fileBase) || intval($fileBase) !== $vid) {
        http_response_code(404);
        exit;
    }
}

$server = gcs_get_server_by_vid($vid);
if (!$server) {
    // Fallback local quando vídeo está no servidor local/FTP
    $localPath = get_local_video_path($vid, $object);
    if ($localPath && file_exists($localPath)) {
        header('Location: ' . get_local_video_url($vid, $object), true, 302);
        exit;
    }
    http_response_code(404);
    exit;
}

if (!gcs_stream_object($server, $object)) {
    // Fallback local quando bucket não tem o objeto
    $localPath = get_local_video_path($vid, $object);
    if ($localPath && file_exists($localPath)) {
        header('Location: ' . get_local_video_url($vid, $object), true, 302);
        exit;
    }
    http_response_code(404);
    exit;
}

exit;