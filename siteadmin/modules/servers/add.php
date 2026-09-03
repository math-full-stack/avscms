<?php
defined('_VALID') or die('Restricted Access!');
Auth::checkAdmin();

require_once $config['BASE_DIR'] . '/classes/filter.class.php';

$server = array(
    'url'          => '',
    'video_url'    => '',
    'server_ip'    => '',
    'ftp_username' => '',
    'ftp_password' => '',
    'ftp_root'     => '',
    'status'       => '1'
);

if (isset($_POST['add_server'])) {
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
    $server['ftp_password'] = $ftp_password;
    $server['ftp_root']     = $ftp_root;
    $server['status']       = ($status == '1') ? '1' : '0';

    if (empty($url)) {
        $errors[] = 'Por favor, insira a URL do Servidor (ex: http://node1.seusite.com)!';
    }

    if (empty($video_url)) {
        $errors[] = 'Por favor, insira a URL de Streaming de Vídeos (ex: http://node1.seusite.com/media/videos)!';
    }

    if (empty($server_ip)) {
        $errors[] = 'Por favor, insira o IP ou Host FTP do Servidor!';
    }

    if (empty($ftp_username)) {
        $errors[] = 'Por favor, insira o Usuário FTP!';
    }

    if (empty($ftp_password)) {
        $errors[] = 'Por favor, insira a Senha do Usuário FTP!';
    }

    if (empty($ftp_root)) {
        $errors[] = 'Por favor, insira o caminho FTP Root (diretório raiz remoto de vídeos)!';
    }

    if (!$errors) {
        $sql = "INSERT INTO servers SET
                    url = " . $conn->qStr($url) . ",
                    video_url = " . $conn->qStr($video_url) . ",
                    server_ip = " . $conn->qStr($server_ip) . ",
                    ftp_username = " . $conn->qStr($ftp_username) . ",
                    ftp_password = " . $conn->qStr($ftp_password) . ",
                    ftp_root = " . $conn->qStr($ftp_root) . ",
                    current_used = '0',
                    status = '" . $server['status'] . "'";

        $conn->execute($sql);
        $sid = $conn->insert_Id();

        if ($sid > 0) {
            VRedirect::go('servers.php?m=all&msg=' . urlencode('Servidor secundário adicionado com sucesso! (ID: ' . $sid . ')'));
        } else {
            $errors[] = 'Erro ao salvar o servidor no banco de dados.';
        }
    }
}

$smarty->assign('server', $server);
?>
