<?php
define('_VALID', 1);
define('_ENTER', true);
define('_CLI', true);

// Argvs
$video_name = $_SERVER['argv'][1];
$vid = (int) $_SERVER['argv'][2];
$video_path = $_SERVER['argv'][3];

// Required
$basedir = dirname(dirname(__FILE__));
require $basedir. '/include/config.php';
require $basedir. '/include/function_video.php';
require $basedir. '/include/function_conversion_fp.php';


// Processor dispatch
$processor = isset($config['processor']) ? $config['processor'] : 'ffmpeg';
if (in_array($processor, array('mediabunny', 'local'), true)) {
	// Client-side processing: skip FFmpeg, mark video for browser/local worker.
	$conn->execute("UPDATE video SET active = '3', last_update = '".time()."' WHERE VID = '".intval($vid)."' LIMIT 1");
	// Delete the fp queue row so the server queue keeps moving.
	$conn->execute("DELETE FROM conversion_queue_fp WHERE VID = '".intval($vid)."' LIMIT 1");
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

$sql 						= "SELECT UID, title FROM video WHERE VID = '".intval($vid)."' LIMIT 1";
$rs							= $conn->execute($sql);
$video_info['UID'] 			= $rs->fields['UID'];
$video_info['title'] 		= $rs->fields['title'];
$video_info['video_name'] 	= $video_name;
$video_info['video_path'] 	= $video_path;

// Get Encoder
$encodings = getEncodings();
foreach($encodings as $encoding) {
	convert($encoding, $vid, $video_name, $video_info);
}
postThumbs($vid,$video_path);

// postConversion intentionally omitted from pass 1: it prematurely activates the
// video (active=1) with only the highest resolution before pass 2 adds the full
// resolution ladder. Pass 2 (convert_videos_sp.php) calls its own postConversion
// which handles final metadata, source cleanup, and queue cleanup after all
// formats are produced.

// Cleanup on failure: a first pass that produced NO formats never reached
// insert_q_sp() (which deletes the fp row). Leaving the row at status='1'
// counts against active_conversions() and FREEZES the whole queue until
// remove_overdue() purges it (q_timeout hours later). Drop it here so the
// queue keeps moving, and park the video as inactive for manual reprocess.
$chk = $conn->execute("SELECT formats FROM video WHERE VID = '".intval($vid)."' LIMIT 1");
$chkFormats = ($chk && !$chk->EOF) ? trim((string)$chk->fields['formats']) : '';
if ($chkFormats === '') {
	$conn->execute("DELETE FROM conversion_queue_fp WHERE VID = '".intval($vid)."' LIMIT 1");
	$conn->execute("UPDATE video SET active = '0', last_update = '".time()."' WHERE VID = '".intval($vid)."' LIMIT 1");
	echo "\n[Cleanup] First pass failed (no formats) - removed stuck queue row for VID $vid\n";
}

// Display :: Encoder Core End
echo "\n<-- End of Script -->\n\n";
exit();
?>
