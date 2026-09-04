<?php
define('_VALID', true);
require 'include/config.php';
require 'include/function_global.php';
require 'include/function_smarty.php';
require $config['BASE_DIR']. '/include/function_video.php';

$vid   = intval($_GET['id']);
$label = intval($_GET['label']);

$sql = "SELECT * FROM video WHERE VID = ".$vid." LIMIT 1";
$rs  = $conn->execute($sql);
if ( $conn->Affected_Rows() != 1 ) {
	VRedirect::go($config['BASE_URL']. '/error');
}
$video   = $rs->fields;
$server  = $video['server'];

// Single source of truth for the file URL (signed URLs for GCS servers)
$sources = get_video_sources($video);
$file    = '';
$height  = 0;

foreach ($sources['files'] as $f) {
	if (intval($f['label']) == $label) {
		$file   = $f['url'];
		$height = intval($f['height']);
		break;
	}
}

if ($file == '') {
	VRedirect::go($config['BASE_URL']. '/error');
}

if ($height >= 480) {
	$condition = $new_permisions['hd_downloads'];
} else {
	$condition = $new_permisions['sd_downloads'];
}

if ($condition == 1) {
	ini_set('memory_limit', '-1');

	// GCS: object is private — just redirect to the short-lived signed URL
	if ($sources['server_type'] == 'gcs') {
		$conn->execute("UPDATE video SET download_num = download_num+1 WHERE VID = ".$vid." LIMIT 1");
		header('Location: '.$file);
		exit();
	}

	if (!$server) {
		if (file_exists($file) && is_file($file) && is_readable($file)) {
			$conn->execute("UPDATE video SET download_num = download_num+1 WHERE VID = ".$vid." LIMIT 1");
			@ob_end_clean();
			if(ini_get('zlib.output_compression')) {
				ini_set('zlib.output_compression', 'Off');
			}	    
			header('Content-Type: application/force-download');
			header('Content-Disposition: attachment; filename="'.basename($file).'"');
			header('Content-Transfer-Encoding: binary');
			header('Accept-Ranges: bytes');
			header('Cache-control: private');
			header('Pragma: private');
			header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
			header('Content-Length: ' .filesize($file));             
			readfile($file);
			exit();
		} else {
			VRedirect::go($config['BASE_URL']. '/error');
		}
	} else {
	$conn->execute("UPDATE video SET download_num = download_num+1 WHERE VID = ".$vid." LIMIT 1");

		$ch = curl_init($file);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);
		$remoteSize = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
		curl_exec($ch);
		curl_close($ch);

		@ob_end_clean();
		if(ini_get('zlib.output_compression')) {
			ini_set('zlib.output_compression', 'Off');
		}
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="'.basename($file).'"');
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		if ($remoteSize > 0) {
			header('Content-Length: ' . $remoteSize);
		}
		header('Accept-Ranges: bytes');

		$stream = fopen('php://output', 'w');
		$ch = curl_init($file);
		curl_setopt($ch, CURLOPT_READFUNCTION, function($ch, $fd, $length) use ($stream) {
			return fwrite($stream, fread($fd, $length));
		});
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
		curl_setopt($ch, CURLOPT_NOPROGRESS, false);
		curl_exec($ch);
		$error = curl_error($ch);
		curl_close($ch);
		fclose($stream);

		if (!empty($error)) {
			error_log("Download proxy error VID=$vid: $error");
		}
	}
}
if ($condition == 0 && !$_SESSION['uid']) {
    $_SESSION['error'] = $lang['download.error'];
    VRedirect::go($config['BASE_URL']. '/signup');
}
if ($condition == 0 && $_SESSION['uid'] && $_SESSION['uid_premium'] == 0) {
	VRedirect::go($config['BASE_URL']. '/notfound/download_free');
}
if ($condition == 0 && $_SESSION['uid_premium']) {
	VRedirect::go($config['BASE_URL']. '/notfound/download_premium');
}
die();
?>