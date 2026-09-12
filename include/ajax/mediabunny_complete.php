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

if (!isset($_SESSION['admin_id']) || intval($_SESSION['admin_id']) <= 0) {
Auth::checkAdmin();
}

if (!isset($config['conversion_q']) || $config['conversion_q'] != '1') {
$response['error'] = 'Conversion queue is disabled.';
echo json_encode($response);
die();
}

// Accept POST only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
$response['error'] = 'POST required';
echo json_encode($response);
die();
}

$filter = new VFilter();
$vid = $filter->get('vid', 'INTEGER');
$format_label = $filter->get('format_label');
$duration = $filter->get('duration');
$width = $filter->get('width', 'INTEGER');
$height = $filter->get('height', 'INTEGER');
$audio_codec = $filter->get('audio_codec');

if ($vid <= 0) {
$response['error'] = 'Invalid VID';
echo json_encode($response);
die();
}

// Verify the video exists and is in processing state.
$sql = "SELECT VID, video_name, video_path, formats, lformats FROM video WHERE VID = ".$vid." LIMIT 1";
$rs = $conn->execute($sql);
if (!$rs || $rs->EOF) {
$response['error'] = 'Video not found';
echo json_encode($response);
die();
}

$video = $rs->getrow();

// Check if an MP4 file was uploaded.
$uploaded = false;
$targetPath = '';
if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
$uploaded = true;
$label = $format_label ?: 'converted';
$format = 'mp4';
$targetPath = $config['H264_DIR'].'/'.$vid.'_'.$label.'.'.$format;

// Move uploaded file.
if (!move_uploaded_file($_FILES['video_file']['tmp_name'], $targetPath)) {
$response['error'] = 'Failed to save uploaded file';
echo json_encode($response);
die();
}
} else {
$response['error'] = 'No video file uploaded';
echo json_encode($response);
die();
}

// Verify the saved file.
if (!file_exists($targetPath) || filesize($targetPath) < 100) {
@unlink($targetPath);
$response['error'] = 'Uploaded file invalid';
echo json_encode($response);
die();
}

// Update formats column.
$label = $format_label ?: 'converted';
$format_str = $height.'.'.$label.'.mp4';
$label_str = $label;

$existing_formats = trim((string)(isset($video['formats']) ? $video['formats'] : ''));
$existing_lformats = trim((string)(isset($video['lformats']) ? $video['lformats'] : ''));

if ($existing_formats && strpos($existing_formats, $format_str) !== false) {
// Format already exists.
} else {
$sql = "UPDATE video SET formats = IF(formats IS NULL, '".$format_str."', CONCAT(formats, ',".$format_str."')) WHERE VID = '".intval($vid)."'";
executeQuery($sql);
}

if ($existing_lformats && strpos($existing_lformats, $label_str) !== false) {
// Label already exists.
} else {
$sql = "UPDATE video SET lformats = IF(lformats IS NULL, '".$label_str."', CONCAT(lformats, ', ".$label_str."')) WHERE VID = '".intval($vid)."'";
executeQuery($sql);
}

// Probe the output file for metadata.
$ffp_data = get_ffprobe_data($targetPath);
$sd_vi = ffpInfo($ffp_data);

$sd_dur = isset($sd_vi['duration']) ? floatval($sd_vi['duration']) : 0;
$sd_w = isset($sd_vi['width']) ? intval($sd_vi['width']) : 0;
$sd_h = isset($sd_vi['height']) ? intval($sd_vi['height']) : 0;
$sd_ar = isset($sd_vi['display_aspect_ratio']) ? $sd_vi['display_aspect_ratio'] : '';

// Determine if HD.
$hd = (intval($height) >= 480) ? 1 : 0;
$active = 1;

// Run postConversion to finalize metadata, cleanup, etc.
postConversion($vid, $video['video_path']);

$response['status'] = 1;
$response['VID'] = $vid;
$response['format'] = $format_str;
$response['duration'] = $sd_dur;
$response['width'] = $sd_w;
$response['height'] = $sd_h;
$response['hd'] = $hd;

echo json_encode($response);
die();
?>
