<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR']. '/classes/filter.class.php';
require $config['BASE_DIR']. '/include/compat/json.php';
require $config['BASE_DIR']. '/include/adodb/adodb.inc.php';
require $config['BASE_DIR']. '/include/dbconn.php';
require $config['BASE_DIR']. '/include/function_video.php';
require $config['BASE_DIR']. '/include/function_conversion.php';
require $config['BASE_DIR']. '/classes/auth.class.php';

$response = array('status' => 0);

// Must be admin or have a valid session (browser worker).
$isAdmin = false;
if (isset($_SESSION['admin_id']) && intval($_SESSION['admin_id']) > 0) {
	$isAdmin = true;
} else {
	Auth::checkAdmin();
	$isAdmin = true;
}

if (!$isAdmin) {
	$response['error'] = 'Unauthorized';
	echo json_encode($response);
	die();
}

// Check processor mode.
$processor = isset($config['processor']) ? $config['processor'] : 'ffmpeg';
if ($processor === 'ffmpeg') {
	$response['pending'] = 0;
	$response['error'] = 'Processor is set to ffmpeg — no client-side jobs.';
	echo json_encode($response);
	die();
}

// Find one video that is queued (active=3) with a valid vdoname.
$sql = "SELECT VID, UID, title, vdoname, watermark_cfg, cut, cut_out
		FROM video
		WHERE active = '3'
		  AND vdoname IS NOT NULL AND vdoname != ''
		ORDER BY last_update ASC
		LIMIT 1";
$rs = $conn->execute($sql);

if (!$rs || $rs->EOF) {
	$response['pending'] = 0;
	echo json_encode($response);
	die();
}

$video = $rs->getrow();
$vid = intval($video['VID']);
$vdoname = $video['vdoname'];

// Construct the full path.
$videoPath = $config['VDO_DIR'].'/'.$vdoname;

// Verify the file exists on disk.
if (!file_exists($videoPath) || !is_file($videoPath) || filesize($videoPath) < 100) {
	// File missing — release the video.
	$conn->execute("UPDATE video SET active = '0', last_update = '".time()."' WHERE VID = '".$vid."' LIMIT 1");
	$response['pending'] = 0;
	$response['error'] = 'Video file missing for VID '.$vid;
	echo json_encode($response);
	die();
}

// Mark as processing (active=2) to prevent double-pickup.
$conn->execute("UPDATE video SET active = '2', last_update = '".time()."' WHERE VID = '".$vid."' LIMIT 1");

// Get encoding ladder.
$encodings = getEncodings();

// Resolve watermark config.
$wmCfg = wm_video_config($vid);

// Build response.
$response['status'] = 1;
$response['pending'] = 1;
$response['video'] = array(
	'VID'        => $vid,
	'vdoname'    => $vdoname,
	'video_path' => $videoPath,
	'title'      => $video['title'],
	'UID'        => intval($video['UID']),
	'cut'        => floatval($video['cut']),
	'cut_out'    => floatval($video['cut_out']),
);
$response['encodings'] = $encodings;
$response['watermark'] = $wmCfg;
$response['video_url'] = $config['BASE_URL'].'/ajax.php?module=mediabunny_serve&vid='.$vid;

echo json_encode($response);
die();
?>
