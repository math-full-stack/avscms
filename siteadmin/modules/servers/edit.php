<?php
defined('_VALID') or die('Restricted Access!');
Auth::checkAdmin();

require_once $config['BASE_DIR'] . '/classes/filter.class.php';

$sid = isset($_GET['SID']) ? intval($_GET['SID']) : (isset($_POST['server_id']) ? intval($_POST['server_id']) : 0);

if ($sid <= 0) {
    VRedirect::go('servers.php?m=all&err=' . urlencode('ID do servidor inválido.'));
}

$sql = "SELECT * FROM servers WHERE server_id = " . $sid . " LIMIT 1";
$rs  = $conn->execute($sql);

if ($conn->Affected_Rows() != 1) {
    VRedirect::go('servers.php?m=all&err=' . urlencode('Servidor não encontrado!'));
}

$server = $rs->fields;

if (isset($_POST['edit_server'])) {
    $filter       = new VFilter();
    $url          = $filter->get('url');
    $video_url    = $filter->get('video_url');
    $server_ip    = $filter->get('server_ip');
    $ftp_username = $filter->get('ftp_username');
    $ftp_password = trim($_POST['ftp_password']);
    $ftp_root     = $filter->get('ftp_root');
    $status       = $filter->get('status');

    $server['url']          = $url;
    $server['video_url']    = $video_url;
    $server['server_ip']    = $server_ip;
    $server['ftp_username'] = $ftp_username;
    $server['ftp_root']     = $ftp_root;
    $server['status']       = ($status == '1') ? '1' : '0';

    if (!empty($ftp_password)) {
        $server['ftp_password'] = $ftp_password;
    }

    if (empty($url)) {
        $errors[] = 'Por favor, insira a URL do Servidor!';
    }

    if (empty($video_url)) {
        $errors[] = 'Por favor, insira a URL de Streaming de Vídeos!';
    }

    if (empty($server_ip)) {
        $errors[] = 'Por favor, insira o IP ou Host FTP do Servidor!';
    }

    if (empty($ftp_username)) {
        $errors[] = 'Por favor, insira o Usuário FTP!';
    }

    if (empty($ftp_root)) {
        $errors[] = 'Por favor, insira o caminho FTP Root!';
    }

    if (!$errors) {
        $sql = "UPDATE servers SET
                    url = " . $conn->qStr($url) . ",
                    video_url = " . $conn->qStr($video_url) . ",
                    server_ip = " . $conn->qStr($server_ip) . ",
                    ftp_username = " . $conn->qStr($ftp_username) . ",
                    ftp_password = " . $conn->qStr($server['ftp_password']) . ",
                    ftp_root = " . $conn->qStr($ftp_root) . ",
                    status = '" . $server['status'] . "'
                WHERE server_id = " . $sid . " LIMIT 1";

        $conn->execute($sql);
        VRedirect::go('servers.php?m=all&msg=' . urlencode('Servidor ID ' . $sid . ' atualizado com sucesso!'));
    }
}

$smarty->assign('server', $server);
$smarty->assign('sid', $sid);
?>
