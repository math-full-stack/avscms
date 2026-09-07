<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR']. '/include/compat/json.php';
require $config['BASE_DIR']. '/include/adodb/adodb.inc.php';
require $config['BASE_DIR']. '/include/dbconn.php';
require $config['BASE_DIR']. '/classes/auth.class.php';

// Must be authenticated.
if (!isset($_SESSION['admin_id']) || intval($_SESSION['admin_id']) <= 0) {
	Auth::checkAdmin();
}

$vid = isset($_GET['vid']) ? intval($_GET['vid']) : 0;
if ($vid <= 0) {
	header('HTTP/1.1 400 Bad Request');
	die('Invalid VID');
}

// Get video path from DB.
$sql = "SELECT vdoname FROM video WHERE VID = ".$vid." LIMIT 1";
$rs = $conn->execute($sql);
if (!$rs || $rs->EOF) {
	header('HTTP/1.1 404 Not Found');
	die('Video not found');
}

$video = $rs->getrow();
$vdoname = $video['vdoname'];
$filePath = $config['VDO_DIR'].'/'.$vdoname;

if (!file_exists($filePath) || !is_file($filePath)) {
	header('HTTP/1.1 404 Not Found');
	die('Video file not found on disk');
}

// Serve the file with proper headers.
$fileSize = filesize($filePath);
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeTypes = array(
	'mp4'  => 'video/mp4',
	'mkv'  => 'video/x-matroska',
	'mov'  => 'video/quicktime',
	'webm' => 'video/webm',
	'avi'  => 'video/x-msvideo',
	'flv'  => 'video/x-flv',
);
$mime = isset($mimeTypes[$ext]) ? $mimeTypes[$ext] : 'application/octet-stream';

header('Content-Type: '.$mime);
header('Content-Length: '.$fileSize);
header('Accept-Ranges: bytes');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

readfile($filePath);
die();
?>
