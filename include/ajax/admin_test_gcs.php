<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR'] . '/classes/filter.class.php';
require $config['BASE_DIR'] . '/classes/auth.class.php';
Auth::checkAdmin();

header('Content-Type: application/json; charset=utf-8');

$bucket  = isset($_POST['gcs_bucket']) ? trim($_POST['gcs_bucket']) : '';
$keyPath = isset($_POST['gcs_key_path']) ? trim($_POST['gcs_key_path']) : '';

if (empty($bucket) || empty($keyPath)) {
    echo json_encode(array(
        'status'  => 0,
        'message' => 'Por favor, preencha o nome do Bucket e o caminho da Chave JSON.'
    ));
    exit();
}

// Resolver caminho da chave
if (!file_exists($keyPath)) {
    $keyPathRelative = $config['BASE_DIR'] . '/' . $keyPath;
    if (file_exists($keyPathRelative)) {
        $keyPath = $keyPathRelative;
    } else {
        echo json_encode(array(
            'status'  => 0,
            'message' => 'Arquivo de chave não encontrado: ' . htmlspecialchars($keyPath, ENT_QUOTES, 'UTF-8')
                . '<br>Tente usar o caminho absoluto ou relativo ao projeto.'
        ));
        exit();
    }
}

// Validar JSON
$key = @json_decode(file_get_contents($keyPath), true);
if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
    echo json_encode(array(
        'status'  => 0,
        'message' => 'Arquivo JSON inválido! Certifique-se de que é uma Service Account Key válida do Google Cloud.'
    ));
    exit();
}

require_once $config['BASE_DIR'] . '/classes/gcs.class.php';

$gcs    = new GCS($keyPath, $bucket);
$result = $gcs->testConnection();

if ($result['success']) {
    // Verificar CORS: o player Media Bunny lê o objeto via requisição
    // cross-origin e o bucket PRECISA permitir a origem do site.
    $corsMsg = '';
    $origin  = '';
    if (!empty($config['BASE_URL'])) {
        $parts  = parse_url($config['BASE_URL']);
        $origin = (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . (isset($parts['host']) ? $parts['host'] : '');
    }
    if ($origin !== '') {
        if ($gcs->testCors($origin)) {
            $corsMsg = '<br><span class="text-success"><i class="fa fa-check-circle"></i> CORS OK para <b>' . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') . '</b> (Media Bunny)</span>';
        } else {
            $corsMsg = '<br><span class="text-warning"><i class="fa fa-exclamation-triangle"></i> Aviso: CORS do bucket não permite <b>' . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') . '</b>. O player Media Bunny não conseguirá ler os vídeos. Rode: <code>php scripts/gcs_secure_bucket.php ' . intval(isset($_POST['server_id']) ? $_POST['server_id'] : 0) . '</code></span>';
        }
    }

    // Testar escrita também
    $writeResult = $gcs->testWrite();
    if ($writeResult['success']) {
        echo json_encode(array(
            'status'  => 1,
            'message' => $result['message'] . '<br>' . $writeResult['message'] . $corsMsg
        ));
    } else {
        echo json_encode(array(
            'status'  => 1,
            'message' => $result['message'] . '<br><span class="text-warning"><i class="fa fa-exclamation-triangle"></i> Aviso: ' . $writeResult['message'] . '</span>' . $corsMsg
        ));
    }
} else {
    echo json_encode(array(
        'status'  => 0,
        'message' => $result['message']
    ));
}
?>
