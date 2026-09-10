<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR']. '/classes/filter.class.php';
require $config['BASE_DIR']. '/include/compat/json.php';
require $config['BASE_DIR']. '/include/adodb/adodb.inc.php';
require $config['BASE_DIR']. '/include/dbconn.php';
require $config['BASE_DIR']. '/include/function_video.php';
require $config['BASE_DIR']. '/classes/auth.class.php';
Auth::checkAdmin();

$response = array('status' => 0, 'msg' => '', 'duration' => '00:00', 'debug' => '');

$filter     = new VFilter();
$vid        = $filter->get('video_id', 'INTEGER');

$files 		= video_files($vid);
$found      = false;

foreach ($files['dir'] as $file) {
	if (file_exists($file) && filesize($file) > 100) {
		$duration = get_video_duration($file, $vid);
		$found = true;
		break;		
	}
}

if (!$found) {
	// Reprocess pós-sincronização deixa a fonte original em VDO_DIR (media/videos/vid/<vid>.mp4),
	// que o video_files() (all=false) não lista — checar antes de cair no GCS.
	$vdo_src = $config['VDO_DIR'].'/'.$vid.'.mp4';
	if (file_exists($vdo_src) && filesize($vdo_src) > 100) {
		$duration = get_video_duration($vdo_src, $vid);
		$found = true;
	}
}

if (!$found) {
	// Vídeo GCS sem cópia local: baixa o h264 do bucket via
	// gcs_download_h264_source() (include/function_server.php, transporte
	// OAuth2 Bearer compartilhado — file_url_exists() falharia nos signed URLs).
	$src = gcs_download_h264_source($vid, $config['TMP_DIR'].'/vidsrc_'.$vid.'.mp4');
	if ($src) {
		$duration = get_video_duration($src, $vid);
		@unlink($src);
		$found = true;
	}
}

if (!$found) {
	foreach ($files['url'] as $file) {
		if (file_url_exists($file)) {
			$duration = get_video_duration($file, $vid);
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
			
$sql = "UPDATE video SET duration = ".$duration." WHERE VID = ".$vid." LIMIT 1";
$conn->execute($sql);
$response['status'] = 1;

$duration_formated  = NULL;
$duration           = round($duration);
if ( $duration > 3600 ) {
	$hours              = floor($duration/3600);
	$duration_formated .= sprintf('%02d',$hours). ':';
	$duration           = round($duration-($hours*3600));
}
if ( $duration > 60 ) {
	$minutes            = floor($duration/60);
	$duration_formated .= sprintf('%02d', $minutes). ':';
	$duration           = round($duration-($minutes*60));
} else {
	$duration_formated .= '00:';
}
$response['duration'] = $duration_formated . sprintf('%02d', $duration);

echo json_encode($response);
die();
?>
