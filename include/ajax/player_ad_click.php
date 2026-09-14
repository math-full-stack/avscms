<?php
defined('_VALID') or die('Restricted Access!');

require_once $config['BASE_DIR'] . '/include/function_player_ads.php';

$id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
$ok = player_ads_track($id, 'click');

header('Content-Type: application/json; charset=utf-8');
die(json_encode(array('status' => $ok ? 1 : 0)));