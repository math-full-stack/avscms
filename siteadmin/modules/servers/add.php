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
    'server_type'  => 'ftp',
    'gcs_bucket'   => '',
    'gcs_key_path' => '',
    // Cloudflare R2 / S3-compatível
    's3_endpoint'   => '',
    's3_bucket'     => '',
    's3_access_key' => '',
    's3_secret_key' => '',
    's3_region'     => 'auto',
    'status'       => '1'
);

if (isset($_POST['add_server'])) {
    $filter       = new VFilter();
    $server_type  = $filter->get('server_type');
    $url          = $filter->get('url');
    $video_url    = $filter->get('video_url');
    $server_ip    = $filter->get('server_ip');
    $ftp_username = $filter->get('ftp_username');
    $ftp_password = trim($_POST['ftp_password']);
    $ftp_root     = $filter->get('ftp_root');
    $gcs_bucket   = $filter->get('gcs_bucket');
    $gcs_key_path = $filter->get('gcs_key_path');
    $status       = $filter->get('status');

    // Cloudflare R2 / S3-compatível
    $s3_endpoint   = $filter->get('s3_endpoint');
    $s3_bucket     = $filter->get('s3_bucket');
    $s3_access_key = $filter->get('s3_access_key');
    $s3_secret_key = isset($_POST['s3_secret_key']) ? trim($_POST['s3_secret_key']) : '';
    $s3_region     = $filter->get('s3_region');

    $server['server_type']  = ($server_type === 'gcs' || $server_type === 'r2') ? $server_type : 'ftp';
    $server['url']          = $url;
    $server['video_url']    = $video_url;
    $server['server_ip']    = $server_ip;
    $server['ftp_username'] = $ftp_username;
    $server['ftp_root']     = $ftp_root;
    $server['gcs_bucket']   = $gcs_bucket;
    $server['gcs_key_path'] = $gcs_key_path;
    $server['s3_endpoint']   = $s3_endpoint;
    $server['s3_bucket']     = $s3_bucket;
    $server['s3_access_key'] = $s3_access_key;
    $server['s3_secret_key'] = $s3_secret_key;
    $server['s3_region']     = ($s3_region !== '') ? $s3_region : 'auto';
    $server['status']       = ($status == '1') ? '1' : '0';

    if ($server['server_type'] === 'r2') {
        // Validação R2/S3: credenciais por Access Key e base pública de leitura.
        if (empty($s3_endpoint)) {
            $errors[] = 'Por favor, insira o endpoint do R2 (ou só o account id)!';
        }
        if (empty($s3_bucket)) {
            $errors[] = 'Por favor, insira o nome do Bucket R2!';
        }
        if (empty($s3_access_key)) {
            $errors[] = 'Por favor, insira a Access Key ID do R2!';
        }
        if (empty($s3_secret_key)) {
            $errors[] = 'Por favor, insira a Secret Access Key do R2!';
        }
        if (empty($video_url)) {
            $errors[] = 'Por favor, insira a URL pública do bucket R2 (ex: https://pub-xxxx.r2.dev)!';
        }
    } elseif ($server['server_type'] === 'gcs') {
        // Validação GCS
        if (empty($gcs_bucket)) {
            $errors[] = 'Por favor, insira o nome do Bucket GCS!';
        }
        if (empty($gcs_key_path)) {
            $errors[] = 'Por favor, insira o caminho do arquivo de chave JSON do Service Account!';
        }
        if (empty($video_url)) {
            $errors[] = 'Por favor, insira a URL de Streaming do Bucket (ex: https://storage.googleapis.com/pornozinho-cdn1)!';
        }

        // Validar arquivo de chave
        if (!empty($gcs_key_path)) {
            $keyPath = $gcs_key_path;
            if (!file_exists($keyPath)) {
                $keyPathRelative = $config['BASE_DIR'] . '/' . $gcs_key_path;
                if (file_exists($keyPathRelative)) {
                    $keyPath = $keyPathRelative;
                } else {
                    $errors[] = 'Arquivo de chave não encontrado: ' . htmlspecialchars($gcs_key_path, ENT_QUOTES, 'UTF-8');
                }
            }
            if (empty($errors)) {
                $key = @json_decode(file_get_contents($keyPath), true);
                if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
                    $errors[] = 'Arquivo JSON inválido! Certifique-se de que é uma Service Account Key válida.';
                }
            }
        }
    } else {
        // Validação FTP
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
    }

    if (!$errors) {
        if ($server['server_type'] === 'r2') {
            $sql = "INSERT INTO servers SET
                        url = " . $conn->qStr($url) . ",
                        video_url = " . $conn->qStr($video_url) . ",
                        server_type = 'r2',
                        s3_endpoint = " . $conn->qStr($s3_endpoint) . ",
                        s3_bucket = " . $conn->qStr($s3_bucket) . ",
                        s3_access_key = " . $conn->qStr($s3_access_key) . ",
                        s3_secret_key = " . $conn->qStr($s3_secret_key) . ",
                        s3_region = " . $conn->qStr($server['s3_region']) . ",
                        current_used = '0',
                        status = '" . $server['status'] . "'";
        } elseif ($server['server_type'] === 'gcs') {
            $sql = "INSERT INTO servers SET
                        url = " . $conn->qStr($url) . ",
                        video_url = " . $conn->qStr($video_url) . ",
                        server_type = 'gcs',
                        gcs_bucket = " . $conn->qStr($gcs_bucket) . ",
                        gcs_key_path = " . $conn->qStr($gcs_key_path) . ",
                        current_used = '0',
                        status = '" . $server['status'] . "'";
        } else {
            $sql = "INSERT INTO servers SET
                        url = " . $conn->qStr($url) . ",
                        video_url = " . $conn->qStr($video_url) . ",
                        server_ip = " . $conn->qStr($server_ip) . ",
                        ftp_username = " . $conn->qStr($ftp_username) . ",
                        ftp_password = " . $conn->qStr($ftp_password) . ",
                        ftp_root = " . $conn->qStr($ftp_root) . ",
                        server_type = 'ftp',
                        current_used = '0',
                        status = '" . $server['status'] . "'";
        }

        $conn->execute($sql);
        $sid = $conn->insert_Id();

        if ($sid > 0) {
            $typeLabel = ($server['server_type'] === 'gcs') ? 'Google Cloud Storage'
                       : (($server['server_type'] === 'r2') ? 'Cloudflare R2' : 'FTP');
            VRedirect::go('servers.php?m=all&msg=' . urlencode('Servidor (' . $typeLabel . ') adicionado com sucesso! (ID: ' . $sid . ')'));
        } else {
            $errors[] = 'Erro ao salvar o servidor no banco de dados.';
        }
    }
}

$smarty->assign('server', $server);
?>
