<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR']. '/classes/filter.class.php';
require $config['BASE_DIR']. '/include/compat/json.php';
require $config['BASE_DIR']. '/include/adodb/adodb.inc.php';
require $config['BASE_DIR']. '/include/dbconn.php';
require $config['BASE_DIR']. '/include/function_video.php';
require $config['BASE_DIR']. '/classes/auth.class.php';
Auth::checkAdmin();

$response = array('status' => 0, 'thumbnails' => array(), 'player' => false, 'thumb' => 1, 'count' => 20, 'opt' => array());

$filter     = new VFilter();
$vid        = $filter->get('video_id', 'INTEGER');
$target     = $filter->get('target', 'STRING');
$black_bars = $filter->get('black_bars', 'INTEGER');
$keep_ar = $filter->get('keep_ar', 'INTEGER');

// V4 signed URLs estão quebradas para esta Service Account (SignatureDoesNotMatch
// mesmo com o gcloud oficial), então regenerar thumbs de vídeo GCS não pode depender
// de file_url_exists() sobre o URL assinado. Baixamos o h264 do bucket via OAuth2
// Bearer (mesmo transporte do gcs_thumbs.php) e extraímos de um arquivo local.
if (!function_exists('gcs_download_h264_source')) {
function gcs_download_h264_source($vid)
{
	global $config, $conn;

	$sql = "SELECT server, formats FROM video WHERE VID = " .$conn->qStr($vid). " LIMIT 1";
	$rs  = $conn->execute($sql);
	if ($conn->Affected_Rows() != 1) {
		return false;
	}
	$serverUrl = trim($rs->fields['server'] ?? '');
	$formats   = trim($rs->fields['formats'] ?? '');
	if ($serverUrl == '' || $formats == '') {
		return false;
	}

	require_once $config['BASE_DIR']. '/include/function_server.php';
	$server = get_server_by_video_url($serverUrl);
	if (!$server || !isset($server['server_type']) || $server['server_type'] !== 'gcs') {
		return false;
	}

	// formats = "720.720p.mp4,480.480p.mp4" -> prefere o maior primeiro.
	$suffixes = array();
	foreach (explode(',', $formats) as $f) {
		$parts = explode('.', trim($f));
		if (count($parts) >= 3) {
			$suffixes[] = $parts[1].'.'.$parts[2];
		}
	}
	if (!$suffixes) {
		$suffixes[] = '480p.mp4';
	}

	$gcs   = gcs_get_client($server);
	$token = ($gcs) ? $gcs->getReadAccessToken() : false;
	if (!$gcs || $token === false) {
		return false;
	}

	$tmp = $config['TMP_DIR']. '/vidsrc_'.$vid.'.mp4';
	foreach ($suffixes as $suffix) {
		$object = 'h264/'.$vid.'/'.$suffix;
		$url    = 'https://storage.googleapis.com/storage/v1/b/'.urlencode($server['gcs_bucket'])
		        . '/o/'.rawurlencode($object).'?alt=media';
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => array('Authorization: Bearer '.$token),
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 120
		));
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($code === 200 && $body !== false) {
			@file_put_contents($tmp, $body);
			return (file_exists($tmp) && filesize($tmp) > 0) ? $tmp : false;
		}
	}

	return false;
}
}

$thumb_dir = get_thumb_dir($vid);
$thumb_url = get_thumb_url($vid);

$tmp_thumb_dir = $config['TMP_DIR'].'/thumbs/'.$vid.'_adm';
$tmp_thumb_url = $config['TMP_URL'].'/thumbs/'.$vid.'_adm';

$files 		= video_files($vid);
$found      = false;

foreach ($files['dir'] as $file) {
	if (file_exists($file) && filesize($file) > 100) {
		extract_video_thumbs($file, $vid, $target, $black_bars, $keep_ar, true);
		$found = true;
		break;		
	}
}

if (!$found) {
	// Vídeo GCS sem cópia local (del_original_video=1): baixa o h264 do bucket
	// via Bearer — file_url_exists() falharia nos V4 signed URLs quebrados.
	$src = gcs_download_h264_source($vid);
	if ($src) {
		extract_video_thumbs($src, $vid, $target, $black_bars, $keep_ar, true);
		@unlink($src);
		$found = true;
	}
}

if (!$found) {
	foreach ($files['url'] as $file) {
		if (file_url_exists($file)) {
			extract_video_thumbs($file, $vid, $target, $black_bars, $keep_ar, true);
			$found = true;
			break;
		}
	}
}

if (!$found) {
	$response['status'] = 0;
	echo json_encode($response);
	die();	
}

$sql = "SELECT thumb, thumbs, thumbnails_opt from video WHERE VID = " .$conn->qStr($vid). " LIMIT 1";
$rs = $conn->execute($sql);
if ( $conn->Affected_Rows() == 1 ) {
	$response['thumb'] = $rs->fields('thumb');
	$count = $rs->fields('thumbs');	
	$response['count'] = $count;
	$response['opt']   = array_values(array_filter(array_map('intval', explode(',', (string)$rs->fields['thumbnails_opt']))));
}

for ($i = 1; $i <= $count; $i++) {
	if (file_exists($tmp_thumb_dir.'/'.$i.'.jpg')) {
		$response['thumbnails'][$i] = $tmp_thumb_url.'/'.$i.'.jpg';
	} elseif (file_exists($thumb_dir.'/'.$i.'.jpg')) {
		$response['thumbnails'][$i] = $thumb_url.'/'.$i.'.jpg';
	} else {		
		$response['thumbnails'][$i] = $config['TMB_URL'].'/default.jpg';
	}
}


if (file_exists($tmp_thumb_dir.'/default.jpg')) {
	$response['player'] = $tmp_thumb_url.'/default.jpg';	
} elseif (file_exists($thumb_dir.'/default.jpg')) {
	$response['player'] = $thumb_url.'/default.jpg';	
}
$response['source'] = $thumb_url.'/'.$response['thumb'].'.jpg';
$response['status'] = 1;

echo json_encode($response);
die();
?>

