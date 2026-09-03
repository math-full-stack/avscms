<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR'] . '/classes/filter.class.php';
require $config['BASE_DIR'] . '/include/adodb/adodb.inc.php';
require $config['BASE_DIR'] . '/include/dbconn.php';
require $config['BASE_DIR'] . '/classes/auth.class.php';
Auth::checkAdmin();

$response = array('status' => 0);

$filter = new VFilter();
$vid    = $filter->get('video_id', 'INTEGER');

if ($vid <= 0) {
    echo json_encode($response);
    die();
}

// Buscar vídeo e source_url
$sql = "SELECT VID, source_url, active, last_update FROM video WHERE VID = " . intval($vid) . " LIMIT 1";
$rs  = $conn->execute($sql);

if ($conn->Affected_Rows() != 1) {
    echo json_encode($response);
    die();
}

$row          = $rs->fields;
$sourceUrl    = trim($row['source_url'] ?? '');
$currentState = $row['active'];
$lastUpdate   = intval($row['last_update'] ?? 0);

if (empty($sourceUrl)) {
    $response['error'] = 'Este vídeo não possui URL de origem salva.';
    echo json_encode($response);
    die();
}

// Permitir reprocessamento imediato (remove bloqueio de 2 horas)
if ($currentState == '2' || $currentState == '3') {
    // Apenas log informativo, não bloqueia
}

// BLOQUEIO: não reprocessar vídeo que está convertendo ou aguardando
// conversão. Re-baixar por cima do vid/*.mp4 no meio do ffmpeg corrompe
// a saída, desperdiça horas de CPU e deixa a fila em loop.
$qfp = $conn->execute("SELECT status FROM conversion_queue_fp WHERE VID = " . intval($vid) . " LIMIT 1");
if ($qfp && $conn->Affected_Rows() == 1) {
    $response['error'] = 'Vídeo está na fila de conversão (1ª passagem). Aguarde terminar antes de reprocessar.';
    echo json_encode($response);
    die();
}
$qsp = $conn->execute("SELECT status FROM conversion_queue_sp WHERE VID = " . intval($vid) . " LIMIT 1");
if ($qsp && $conn->Affected_Rows() == 1) {
    $response['error'] = 'Vídeo está na fila de conversão (2ª passagem). Aguarde terminar antes de reprocessar.';
    echo json_encode($response);
    die();
}

// Marcar como Baixando e disparar worker
$conn->execute("UPDATE video SET active = '2', last_update = " . time() . " WHERE VID = " . intval($vid) . " LIMIT 1");

$encodedUrl   = base64_encode($sourceUrl);
$encodedThumb = base64_encode('');
$worker       = $config['BASE_DIR'] . '/scripts/grabber_worker.php';

if (file_exists($worker)) {
    $cmd = sprintf('%s %s %d %s %s %s > /dev/null 2>&1 & echo $!',
        escapeshellarg($config['phppath']),
        escapeshellarg($worker),
        intval($vid),
        escapeshellarg($encodedUrl),
        escapeshellarg('best'),
        escapeshellarg($encodedThumb)
    );

    $logFile = $config['LOG_DIR'] . '/' . intval($vid) . '.grabber.log';
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - Reprocessamento via admin. URL: $sourceUrl\n", FILE_APPEND);

    @shell_exec($cmd);

    $response['status'] = 1;
} else {
    $conn->execute("UPDATE video SET active = '" . intval($currentState) . "' WHERE VID = " . intval($vid) . " LIMIT 1");
    $response['error'] = 'grabber_worker.php não encontrado.';
}

echo json_encode($response);
die();
?>
