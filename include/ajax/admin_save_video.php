<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR']. '/classes/filter.class.php';
require $config['BASE_DIR']. '/include/compat/json.php';
require_once $config['BASE_DIR']. '/include/adodb/adodb.inc.php';
require_once $config['BASE_DIR']. '/include/dbconn.php';
require_once $config['BASE_DIR']. '/include/function_global.php';
require $config['BASE_DIR']. '/include/function_video.php';
require_once $config['BASE_DIR']. '/include/function_watermark.php';
require $config['BASE_DIR']. '/classes/auth.class.php';
Auth::checkAdmin();

function nl2br2($string) { 
	$string = str_replace(array("\\r\\n", "\\r", "\\n"), "\n", $string);
	return $string; 
}

$response = array('status' => 0);

$data = (array) $_POST['data'];

$vid            = trim($data['id']);
$title          = trim($data['title']);
$description    = trim($data['description']);
$keyword        = prepare_tags(trim($data['tags']));
$channel        = trim($data['category']);
$type           = trim($data['type']);
$featured       = trim($data['featured']);
$be_comment     = trim($data['be_comment']);
$be_rated       = trim($data['be_rated']);
$embed          = trim($data['embed']);      
$likes          = trim($data['likes']);
$dislikes       = trim($data['dislikes']);
$viewnumber     = trim($data['viewnumber']);
$active         = trim($data['active']);
$server			= trim($data['server']);

// Processamento (corte + watermark por vídeo)
$proc = wm_parse_form($data);
if ($proc['error'] !== '') {
    $response['error'] = $proc['error'];
    echo json_encode($response);
    die();
}

settype($vid, 'integer');
settype($viewnumber, 'integer');
settype($likes, 'integer');
settype($dislikes, 'integer');
settype($channel, 'integer');
if ( $likes != 0 || $dislikes !=0)
	$rate = round(($likes * 100)/($likes + $dislikes));
else
	$rate = 0;

update_tags($vid, $keyword);

// Bloquear ativar vídeo que está baixando ou na fila de conversão
if ($active == '1') {
    $check = $conn->execute("SELECT active FROM video WHERE VID = " . intval($vid) . " LIMIT 1");
    if ($check && $conn->Affected_Rows() == 1) {
        $curActive = $check->fields['active'];
        if ($curActive == '2' || $curActive == '3') {
            $response['error'] = 'Vídeo está na fila de conversão. Aguarde concluir antes de ativar.';
            echo json_encode($response);
            die();
        }
    }
}

$sql = "UPDATE video SET title = " .$conn->qStr($title). ", description = " .nl2br2($conn->qStr($description)). ",
						 keyword = " .$conn->qStr($keyword). ", channel = " .$channel. ", type = " .$conn->qStr($type). ",
						 featured = " .$conn->qStr($featured). ", be_comment = " .$conn->qStr($be_comment). ",
						 be_rated = " .$conn->qStr($be_rated). ", embed = " .$conn->qStr($embed). ",
						 likes = " .$conn->qStr($likes). ", dislikes = " .$conn->qStr($dislikes). ", 
						 rate = " .$conn->qStr($rate). ", viewnumber = " .$conn->qStr($viewnumber). ",
						 active  = " .$conn->qStr($active). ", server = ".$conn->qStr($server).",
						 cfg_manual = " .intval($proc['cfg_manual']). ", watermark_cfg = " .$conn->qStr($proc['watermark_cfg']). ",
						 cut = " .$conn->qStr($proc['cut']). ", cut_out = " .intval($proc['cut_out']). "
		WHERE VID = " .$conn->qStr($vid). " LIMIT 1";
$saved = $conn->execute($sql);
if ( $saved !== false ) {
    $response['status'] = 1;
    // "Herdar da fonte" (cfg_manual=0): reaplica o snapshot da grabber_source
    // agora, senão o cut da fonte só voltaria no próximo regrab.
    if (!$proc['cfg_manual']) {
        $srs = $conn->execute("SELECT source_url FROM video WHERE VID = " . intval($vid) . " LIMIT 1");
        if ($srs && !$srs->EOF) {
            wm_refresh_video_snapshot($vid, trim((string)$srs->fields['source_url']));
        }
    }
} else {
    $response['status'] = 0;
    $response['error'] = $sql;
}

echo json_encode($response);
die();
?>
