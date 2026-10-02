<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR'] . '/classes/filter.class.php';
require $config['BASE_DIR'] . '/classes/auth.class.php';
require $config['BASE_DIR'] . '/include/adodb/adodb.inc.php';
require $config['BASE_DIR'] . '/include/dbconn.php';
Auth::checkAdmin();

header('Content-Type: application/json; charset=utf-8');

$endpoint = isset($_POST['s3_endpoint']) ? trim($_POST['s3_endpoint']) : '';
$bucket   = isset($_POST['s3_bucket']) ? trim($_POST['s3_bucket']) : '';
$access   = isset($_POST['s3_access_key']) ? trim($_POST['s3_access_key']) : '';
$secret   = isset($_POST['s3_secret_key']) ? trim($_POST['s3_secret_key']) : '';
$region   = isset($_POST['s3_region']) ? trim($_POST['s3_region']) : 'auto';
$public   = isset($_POST['video_url']) ? trim($_POST['video_url']) : '';

// Teste a partir da listagem de servidores: as credenciais nunca vão ao
// browser, o endpoint resolve tudo da própria linha do banco.
$sid = isset($_POST['server_id']) ? intval($_POST['server_id']) : 0;
if ($sid > 0) {
    $rs = $conn->execute("SELECT * FROM servers WHERE server_id = " . $sid . " LIMIT 1");
    if ($conn->Affected_Rows() != 1) {
        echo json_encode(array('status' => 0, 'message' => 'Servidor não encontrado.'));
        exit();
    }
    $srv = $rs->fields;
    // Campos digitados têm prioridade; o que vier vazio cai para o valor salvo
    // (ex.: Secret Key em branco numa edição parcial).
    if ($endpoint === '') { $endpoint = trim((string)$srv['s3_endpoint']); }
    if ($bucket === '')   { $bucket   = trim((string)$srv['s3_bucket']); }
    if ($access === '')   { $access   = trim((string)$srv['s3_access_key']); }
    if ($secret === '')   { $secret   = trim((string)$srv['s3_secret_key']); }
    if ($region === '')   { $region   = trim((string)$srv['s3_region']); }
    if ($public === '')   { $public   = trim((string)$srv['video_url']); }
}

if ($bucket === '' || $access === '' || $secret === '') {
    echo json_encode(array(
        'status'  => 0,
        'message' => 'Por favor, preencha o Bucket, a Access Key e a Secret Key.'
    ));
    exit();
}

// Sem endpoint informado, assume o padrão do R2 a partir do account id.
if ($endpoint === '') {
    echo json_encode(array(
        'status'  => 0,
        'message' => 'Informe o endpoint (https://<account_id>.r2.cloudflarestorage.com) ou só o account id.'
    ));
    exit();
}

require_once $config['BASE_DIR'] . '/classes/s3.class.php';

if (strpos($endpoint, '://') === false) {
    $endpoint = 'https://' . trim($endpoint, '.') . '.r2.cloudflarestorage.com';
}

$s3 = new S3($endpoint, $bucket, $access, $secret, ($region !== '' ? $region : 'auto'), $public);

$result = $s3->testConnection();

if (!$result['success']) {
    echo json_encode($result);
    exit();
}

$msg = $result['message'];

// Escrita (PutObject + Delete).
$write = $s3->testWrite();
if ($write['success']) {
    $msg .= '<br>' . $write['message'];
} else {
    $msg .= '<br><span class="text-warning"><i class="fa fa-exclamation-triangle"></i> Aviso: ' . $write['message'] . '</span>';
}

// CORS: o player Media Bunny lê o objeto por fetch cross-origin, então o
// bucket precisa devolver Access-Control-Allow-Origin para a origem do site
// (no R2 isso é a CORS Policy do bucket / domínio público).
if ($public !== '') {
    $origin = '';
    if (!empty($config['BASE_URL'])) {
        $parts  = parse_url($config['BASE_URL']);
        $origin = (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . (isset($parts['host']) ? $parts['host'] : '');
    }

    if ($origin !== '') {
        $code = 0;
        $ch = curl_init(rtrim($public, '/') . '/robots.txt');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array('Origin: ' . $origin),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADER         => true
        ));
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (stripos((string)$resp, 'access-control-allow-origin') !== false) {
            $msg .= '<br><span class="text-success"><i class="fa fa-check-circle"></i> Base pública responde e envia CORS para <b>' . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') . '</b></span>';
        } else {
            $msg .= '<br><span class="text-warning"><i class="fa fa-exclamation-triangle"></i> A base pública não devolveu Access-Control-Allow-Origin para <b>'
                 . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') . '</b> (HTTP ' . $code . '). O player Media Bunny usa fetch cross-origin: configure a CORS Policy do bucket no R2.</span>';
        }
    }
}

echo json_encode(array('status' => 1, 'message' => $msg));
?>
