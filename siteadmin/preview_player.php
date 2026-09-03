<?php
define('_VALID', true);
define('_ADMIN', true);
require '../include/config.php';
require '../include/function_global.php';
require '../include/function_admin.php';
require '../include/function_smarty.php';
require '../classes/auth.class.php';

Auth::checkAdmin();

$title     = isset($_GET['title']) ? trim($_GET['title']) : 'Preview Video';
$src       = isset($_GET['src']) ? trim($_GET['src']) : '';
$poster    = isset($_GET['poster']) ? trim($_GET['poster']) : '';
$embed_url = isset($_GET['embed']) ? trim($_GET['embed']) : '';

$smarty->assign('title', $title);
$smarty->assign('src', $src);
$smarty->assign('poster', $poster);
$smarty->assign('embed_url', $embed_url);
$smarty->assign('baseurl', $config['BASE_URL']);

$smarty->display('preview_player.tpl');
?>
