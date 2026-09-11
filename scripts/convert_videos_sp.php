<?php
define('_VALID', 1);
define('_ENTER', true);
define('_CLI', true);
define('_CONSOLE', true); // CLI: skip web sessions (and their extra DB connection)

// Argvs
$video_name = $_SERVER['argv'][1];
$vid = (int) $_SERVER['argv'][2];
$video_path = $_SERVER['argv'][3];
$skip = $_SERVER['argv'][4];

// Required
$basedir = dirname(dirname(__FILE__));
require $basedir. '/include/config.php';
require $basedir. '/include/function_video.php';
require $basedir. '/include/function_conversion_sp.php';
require_once $basedir. '/include/function_server.php';


// Host role gate (fail-closed): conversion/FFmpeg runs ONLY on the converter
// host (the PC). A 'web' host exits immediately even if a legacy path spawns
// this script directly.
$workerRole = isset($config['worker_role']) ? $config['worker_role'] : 'web';
if ($workerRole !== 'converter') {
    $logDir = $config['LOG_DIR'];
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir.'/'.intval($vid).'.log', "[".date('Y-m-d H:i:s')."] worker_role='$workerRole' - conversion skipped (not the converter host).\n", FILE_APPEND);
    echo "worker_role='$workerRole' - not the converter host, exiting.\n";
    exit(0);
}

// Processor dispatch: on the converter host, always run FFmpeg regardless of
// processor setting. The processor gate only applies on web/VM hosts.
$processor = isset($config['processor']) ? $config['processor'] : 'ffmpeg';
if ($config['worker_role'] !== 'converter' && in_array($processor, array('mediabunny', 'local'), true)) {
	// Client-side processing: skip FFmpeg, mark video for browser/local worker.
	$conn->execute("UPDATE video SET active = '3', last_update = '".time()."' WHERE VID = '".intval($vid)."' LIMIT 1");
	// Delete the sp queue row so the server queue keeps moving.
	$conn->execute("DELETE FROM conversion_queue_sp WHERE VID = '".intval($vid)."' LIMIT 1");
	// Audit log.
	$logDir = $config['LOG_DIR'];
	if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
	$logFile = $logDir.'/'.$vid.'.log';
	$logMsg = "[".date('Y-m-d H:i:s')."] [".ucfirst($processor)."] Processor set to $processor — skipped server FFmpeg, VID $vid marked for client-side processing.\n";
	@file_put_contents($logFile, $logMsg, FILE_APPEND);
	echo $logMsg;
	echo "\n<-- End of Script -->\n\n";
	exit();
}

$vi = array();
$video_info = array();
$nl = "=========================================================\n";

echo "\n".$nl."Video Details:\n".$nl;
echo "\n".$nl."-----------------:\n".$nl;
echo "Parameters:\n";
echo "Video Name: $video_name\n";
echo "Vidoe ID: $vid\n";
echo "Video Path: $video_path\n\n";

// Error Checks
if (!preg_match("/^[0-9]{1,5}\.[a-z0-9]{2,4}$/i", $video_name)) {
	echo "Video Name: $video_name is invalid. Err #1. Exiting ..."; exit();
} else {
	$ffp_data = get_ffprobe_data($video_path);
	$video_info = ffpInfo($ffp_data);
}

// Get Encoder
$encodings = getEncodings();

foreach($encodings as $encoding) {
	convert($encoding, $vid, $video_name, $video_info, $skip);	
}

// Cleanup do 1º passe: o FP já apagou a própria linha no sucesso
// (insert_q_sp, function_conversion_fp.php); este DELETE é rede de segurança.
executeQuery("DELETE FROM conversion_queue_fp WHERE VID = '".$vid."' LIMIT 1");
// A linha SP NÃO pode ser apagada aqui: postThumbs/postConversion abaixo são
// longos e toda interrupção entre este ponto e o postConversion orfana o vídeo
// (active=3 sem fila = 'Processamento local pendente' para sempre). O
// postConversion apaga a linha SP no sucesso (function_conversion_sp.php:432)
// e, se morrermos no meio, remove_overdue() resetta o status para re-pump.

// Thumbs + vthumbs MUST be generated HERE (second pass), not in the first
// pass: this pass owns postConversion, which uploads to GCS and then removes
// the local h264/{VID}_*.mp4 ladder (del_original_video=1). Running postThumbs
// in the FP pass raced that cleanup — the SP pass started concurrently
// (q_limit >= 2) and deleted the file the FP pass was still extracting from,
// leaving videos active with no thumbs/video.mp4/video.webm and duration=0.
postThumbs($vid, $video_path);
postConversion($vid, $video_path);

// Display :: Encoder Core End
echo "\n<-- End of Script -->\n\n";
exit();
?>
