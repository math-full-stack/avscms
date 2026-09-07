<?php
/**
 * bucereteiro_proxy.php - Proxy para o player playernc.com
 *
 * Busca o HTML do player server-side com referrer correto (buceteiro.com)
 * e reescreve URLs relativas para absolutas, permitindo que o player
 * funcione quando carregado via iframe no admin.
 *
 * Uso: bucereteiro_proxy.php?uuid={UUID}
 */
define('_VALID', true);
define('_ADMIN', true);
require dirname(__FILE__) . '/../include/config.php';
require dirname(__FILE__) . '/../include/function_global.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$uuid = isset($_GET['uuid']) ? preg_replace('/[^a-f0-9\-]/i', '', trim($_GET['uuid'])) : '';
if (empty($uuid)) {
    http_response_code(400);
    echo 'Missing uuid parameter';
    exit;
}

$playerUrl = 'https://playernc.com/player/player.php?uuid=' . urlencode($uuid);

$ch = curl_init($playerUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
curl_setopt($ch, CURLOPT_REFERER, 'https://buceteiro.com/');
curl_setopt($ch, CURLOPT_ENCODING, '');
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || empty($html)) {
    http_response_code(502);
    echo 'Failed to fetch player from playernc.com (HTTP ' . $httpCode . ')';
    exit;
}

$playerBase = 'https://playernc.com/player/';

// Reescrever src relativos para absolutos
$html = preg_replace(
    '/src="(video\.php\?)/i',
    'src="' . $playerBase . '$1',
    $html
);

// Reescrever src relativos de imagens/thumbs
$html = preg_replace(
    '/src="((?:\.\.\/)*storage\.thumbs\/)/i',
    'src="' . $playerBase . '$1',
    $html
);

// Reescrever href/JS que usam paths relativos ao player
$html = preg_replace(
    '/(?:href|src)=["\']((?:\.\.\/)*(?!https?:\/\/|data:|javascript:|#)[^"\']+)["\']',
    function ($m) use ($playerBase) {
        $path = ltrim($m[1], './');
        return 'src="' . $playerBase . $path . '"';
    },
    $html
);

// Content-Type para o browser renderizar como página completa
header('Content-Type: text/html; charset=utf-8');
echo $html;
