<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR']. '/classes/filter.class.php';
require $config['BASE_DIR']. '/include/compat/json.php';
require $config['BASE_DIR']. '/include/adodb/adodb.inc.php';
require $config['BASE_DIR']. '/include/dbconn.php';
require $config['BASE_DIR']. '/include/function_video.php';
require $config['BASE_DIR']. '/classes/auth.class.php';
Auth::checkAdmin();

$response = array('status' => 0, 'src' => '');

$filter = new VFilter();
$vid    = $filter->get('video_id', 'INTEGER');
$thumb  = $filter->get('thumbnails_default', 'INTEGER');

// Capas selecionadas para rotação nos cards (frames 1..thumbs, separados por vírgula)
$opt_raw = (string)$filter->get('thumbnails_opt', 'STRING');

$sql_max = "SELECT thumbs FROM video WHERE VID = " .$conn->qStr($vid). " LIMIT 1";
$rs_max  = $conn->execute($sql_max);
$max     = ( $conn->Affected_Rows() == 1 ) ? (int)$rs_max->fields['thumbs'] : 20;
$max     = ( $max > 0 ) ? $max : 20;

$covers = array();
foreach ( explode(',', $opt_raw) as $c ) {
	$c = (int)$c;
	if ( $c >= 1 && $c <= $max && !in_array($c, $covers, true) ) {
		$covers[] = $c;
	}
}

$opt_str = implode(',', $covers);
$thumb   = ( $thumb >= 1 && $thumb <= $max ) ? $thumb : ( count($covers) > 0 ? $covers[0] : 1 );

$sql = "UPDATE video SET thumb = " .$conn->qStr($thumb). ", thumbnails_opt = " .$conn->qStr($opt_str). "
		WHERE VID = " .$conn->qStr($vid). " LIMIT 1";
$conn->execute($sql);
$response['status'] = 1;

$tmp_thumb_dir = $config['TMP_DIR'].'/thumbs/'.$vid.'_adm';

// Pasta local pode ter sido removida pela limpeza automática (GCS + del_original_video)
$final_thumb_dir = get_thumb_dir($vid);
if (!is_dir($final_thumb_dir)) {
	@mkdir($final_thumb_dir, 0777, true);
}

for ($i = 1; $i <= 20; $i++) {
	$temp_thumb_file  = $tmp_thumb_dir.'/'.$i.'.jpg';
	$final_thumb_file = $final_thumb_dir.'/'.$i.'.jpg';
	if (file_exists($temp_thumb_file)) {
		copy($temp_thumb_file, $final_thumb_file);
	}
}

$temp_thumb_file  = $tmp_thumb_dir.'/default.jpg';
$final_thumb_file = $final_thumb_dir.'/default.jpg';
if (file_exists($temp_thumb_file)) {
	copy($temp_thumb_file, $final_thumb_file);
}

// Mantém o bucket GCS em dia com os thumbs salvos
require_once $config['BASE_DIR']. '/include/function_server.php';
sync_video_thumbs($vid, null, true);

$response['src'] = get_thumb_url($vid).'/'.$thumb.'.jpg';

// Frames ordenados para o carousel da listagem: principal primeiro, depois as capas.
$frames = array_unique(array_merge(array($thumb), $covers));
$urls = array();
require_once $config['BASE_DIR']. '/include/function_thumbs.php';
foreach ($frames as $f) {
	$urls[] = get_video_thumb_src($vid, $f);
}
$response['frames'] = array_values($frames);
$response['urls'] = $urls;

echo json_encode($response);
die();
?>
