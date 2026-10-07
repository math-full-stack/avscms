<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR'] . '/classes/filter.class.php';
require_once $config['BASE_DIR'] . '/include/adodb/adodb.inc.php';
require_once $config['BASE_DIR'] . '/include/dbconn.php';
require_once $config['BASE_DIR'] . '/include/function_queue.php';
require_once $config['BASE_DIR'] . '/include/function_server.php';
require $config['BASE_DIR'] . '/classes/auth.class.php';
Auth::checkAdmin();

$response = array('status' => 0);

if (!isset($config['conversion_q']) || $config['conversion_q'] != '1') {
    $response['error'] = 'Conversion queue is disabled. Reprocessamento bloqueado.';
    echo json_encode($response);
    die();
}

$filter = new VFilter();
$vid    = $filter->get('video_id', 'INTEGER');

// auto       -> source_url ? re-download : re-encode local
// local      -> re-encode a partir da fonte já adquirida (sem baixar de novo)
// redownload -> comportamento antigo (re-baixa da source_url)
$mode = isset($_POST['mode']) ? strtolower(trim((string)$_POST['mode'])) : 'auto';
if (!in_array($mode, array('auto', 'local', 'redownload'), true)) {
    $mode = 'auto';
}

if ($vid <= 0) {
    echo json_encode($response);
    die();
}

// Buscar vídeo
$sql = "SELECT VID, source_url, vdoname, active, last_update FROM video WHERE VID = " . intval($vid) . " LIMIT 1";
$rs  = $conn->execute($sql);

if ($conn->Affected_Rows() != 1) {
    echo json_encode($response);
    die();
}

$row          = $rs->fields;
$sourceUrl    = trim($row['source_url'] ?? '');
$vdoname      = trim($row['vdoname'] ?? '');
$currentState = $row['active'];

if ($mode === 'auto') {
    $mode = ($sourceUrl !== '') ? 'redownload' : 'local';
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

// Pré-checagem do modo local: sem fonte acessível não adianta deixar
// active='3' preso na fila. Com source_url cai para re-download avisando no
// message; sem source_url, erro claro (plano §6).
$fallbackNote = '';
$vdoDir       = !empty($config['VDO_DIR']) ? rtrim($config['VDO_DIR'], '/') : $config['BASE_DIR'] . '/media/videos/vid';
if ($mode === 'local') {
    $available = ($vdoname !== '') && video_source_available($vid, $vdoname, $vdoDir);
    if (!$available) {
        if ($sourceUrl === '') {
            $response['error'] = ($vdoname === '')
                ? 'Vídeo sem arquivo de origem registrado (vdoname) para re-encode local.'
                : 'Origem indisponível: arquivo não está no disco nem em src/' . intval($vid) . '/' . $vdoname . ' no bucket, e não há URL de origem para re-baixar.';
            echo json_encode($response);
            die();
        }
        $mode        = 'redownload';
        $fallbackNote = ' Fonte local/bucket indisponível — usando re-download da origem.';
    }
}

if ($mode === 'redownload') {
    if ($sourceUrl === '') {
        $response['error'] = 'Este vídeo não possui URL de origem salva. Use o re-encode local.';
        echo json_encode($response);
        die();
    }

    // Marcar como Baixando e resetar qualidades antigas para não duplicar
    $conn->execute("UPDATE video SET active = '2', formats = NULL, lformats = NULL, last_update = " . time() . " WHERE VID = " . intval($vid) . " LIMIT 1");

    $encodedUrl   = base64_encode($sourceUrl);
    $encodedThumb = base64_encode('');
    $worker       = $config['BASE_DIR'] . '/scripts/grabber_worker.php';

    if (file_exists($worker)) {
        $logFile = $config['LOG_DIR'] . '/' . intval($vid) . '.grabber.log';
        // Log pode pertencer a outro usuário (Apache=daemon × CLI=math) com 644:
        // file_put_contents e o ">>" do redirect falham em silêncio e o worker
        // nem sobe (vídeo fica preso em active=2 sem traço no log). Dir é 777
        // sem sticky bit, então recriar resolve; 0666 libera os dois usuários.
        if (file_exists($logFile) && !is_writable($logFile)) {
            @unlink($logFile);
        }
        if (!file_exists($logFile)) {
            @touch($logFile);
            @chmod($logFile, 0666);
        }
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " - Reprocessamento via admin (re-download). URL: $sourceUrl\n", FILE_APPEND);

        // Worker stderr/stdout goes to the same grabber log: boot failures (e.g.
        // "Too many connections") used to vanish into /dev/null, leaving the video
        // stuck at active=2 (downloading) with no trace in the log.
        $cmd = sprintf('%s %s %d %s %s %s >> %s 2>&1 & echo $!',
            escapeshellarg($config['phppath']),
            escapeshellarg($worker),
            intval($vid),
            escapeshellarg($encodedUrl),
            escapeshellarg('best'),
            escapeshellarg($encodedThumb),
            escapeshellarg($logFile)
        );

        @shell_exec($cmd);

        $response['status']  = 1;
        $response['mode']    = 'redownload';
        $response['message'] = 'Reprocessamento (re-download) iniciado!' . $fallbackNote;
    } else {
        $conn->execute("UPDATE video SET active = '" . intval($currentState) . "' WHERE VID = " . intval($vid) . " LIMIT 1");
        $response['error'] = 'grabber_worker.php não encontrado.';
    }
} else {
    // Re-encode local: reusa o arquivo já adquirido (VDO_DIR/{vdoname} no host
    // converter, ou src/{VID}/{vdoname} no bucket — queue_resolve_source decide).
    // Pré-checagem acima garante que a fonte existe; não baixa nada: corta/marca
    // da config do vídeo são reaplicadas na fila FP.
    $conn->execute("UPDATE video SET active = '3', formats = NULL, lformats = NULL, last_update = " . time() . " WHERE VID = " . intval($vid) . " LIMIT 1");

    insert_into_q_fp($vid, $vdoname, $vdoDir . '/' . $vdoname);

    $logFile = $config['LOG_DIR'] . '/' . intval($vid) . '.log';
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - Re-encode local via admin (sem re-download). Arquivo: $vdoname\n", FILE_APPEND);

    $response['status']  = 1;
    $response['mode']    = 'local';
    $response['message'] = 'Re-encode local enfileirado (fonte resolvida pelo worker: disco ou bucket src/).';
}

echo json_encode($response);
die();
?>
