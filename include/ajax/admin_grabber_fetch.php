<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR'] . '/classes/filter.class.php';
require $config['BASE_DIR'] . '/classes/auth.class.php';
require $config['BASE_DIR'] . '/classes/grabbers/GrabberManager.php';

Auth::checkAdmin();

header('Content-Type: application/json; charset=utf-8');

$url = isset($_POST['url']) ? trim($_POST['url']) : (isset($_GET['url']) ? trim($_GET['url']) : '');

if (empty($url)) {
    echo json_encode(array('status' => false, 'error' => 'Por favor, informe a URL do vídeo.'));
    exit();
}

$grabber = GrabberManager::getGrabberForUrl($url);
if (!$grabber) {
    echo json_encode(array(
        'status' => false,
        'error'  => 'Nenhum grabber disponível para esta URL. Sites suportados: ' . implode(', ', GrabberManager::getSupportedSites())
    ));
    exit();
}

$info = $grabber->fetchInfo($url);
echo json_encode($info);
exit();
?>
