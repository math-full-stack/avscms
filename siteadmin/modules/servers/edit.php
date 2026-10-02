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
    $gcs_bucket   = $filter->get('gcs_bucket');
    $gcs_key_path = $filter->get('gcs_key_path');
    $status       = $filter->get('status');

    // Cloudflare R2 / S3-compatível
    $s3_endpoint   = $filter->get('s3_endpoint');
    $s3_bucket     = $filter->get('s3_bucket');
    $s3_access_key = $filter->get('s3_access_key');
    $s3_secret_key = isset($_POST['s3_secret_key']) ? trim($_POST['s3_secret_key']) : '';
    $s3_region     = $filter->get('s3_region');

    $serverType = $server['server_type'];
    $server['url']          = $url;
    $server['video_url']    = $video_url;
    $server['ftp_username'] = $ftp_username;
    $server['ftp_root']     = $ftp_root;
    $server['gcs_bucket']   = $gcs_bucket;
    $server['gcs_key_path'] = $gcs_key_path;
    $server['s3_endpoint']   = $s3_endpoint;
    $server['s3_bucket']     = $s3_bucket;
    $server['s3_access_key'] = $s3_access_key;
    $server['s3_region']     = ($s3_region !== '') ? $s3_region : 'auto';
    $server['status']       = ($status == '1') ? '1' : '0';

    // Secret em branco no form = manter a que já está salva.
    $server['s3_secret_key'] = ($s3_secret_key !== '') ? $s3_secret_key : (string)$server['s3_secret_key'];

    if (!empty($ftp_password)) {
        $server['ftp_password'] = $ftp_password;
    }

    if ($serverType === 'r2') {
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
        if (empty($server['s3_secret_key'])) {
            $errors[] = 'Por favor, insira a Secret Access Key do R2!';
        }
        if (empty($video_url)) {
            $errors[] = 'Por favor, insira a URL pública do bucket R2 (ex: https://pub-xxxx.r2.dev)!';
        }
    } elseif ($serverType === 'gcs') {
        // Validação GCS
        if (empty($gcs_bucket)) {
            $errors[] = 'Por favor, insira o nome do Bucket GCS!';
        }
        if (empty($video_url)) {
            $errors[] = 'Por favor, insira a URL de Streaming do Bucket!';
        }
        // Só validar chave se foi informada uma nova
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
    }

    if (!$errors) {
        if ($serverType === 'r2') {
            $sql = "UPDATE servers SET
                        url = " . $conn->qStr($url) . ",
                        video_url = " . $conn->qStr($video_url) . ",
                        s3_endpoint = " . $conn->qStr($s3_endpoint) . ",
                        s3_bucket = " . $conn->qStr($s3_bucket) . ",
                        s3_access_key = " . $conn->qStr($s3_access_key) . ",
                        s3_secret_key = " . $conn->qStr($server['s3_secret_key']) . ",
                        s3_region = " . $conn->qStr($server['s3_region']) . ",
                        status = '" . $server['status'] . "'
                    WHERE server_id = " . $sid . " LIMIT 1";
        } elseif ($serverType === 'gcs') {
            $sql = "UPDATE servers SET
                        url = " . $conn->qStr($url) . ",
                        video_url = " . $conn->qStr($video_url) . ",
                        gcs_bucket = " . $conn->qStr($gcs_bucket) . ",
                        gcs_key_path = " . $conn->qStr($server['gcs_key_path']) . ",
                        status = '" . $server['status'] . "'
                    WHERE server_id = " . $sid . " LIMIT 1";
        } else {
            $sql = "UPDATE servers SET
                        url = " . $conn->qStr($url) . ",
                        video_url = " . $conn->qStr($video_url) . ",
                        server_ip = " . $conn->qStr($server_ip) . ",
                        ftp_username = " . $conn->qStr($ftp_username) . ",
                        ftp_password = " . $conn->qStr($server['ftp_password']) . ",
                        ftp_root = " . $conn->qStr($ftp_root) . ",
                        status = '" . $server['status'] . "'
                    WHERE server_id = " . $sid . " LIMIT 1";
        }

        $conn->execute($sql);
        VRedirect::go('servers.php?m=all&msg=' . urlencode('Servidor ID ' . $sid . ' atualizado com sucesso!'));
    }
}

$smarty->assign('server', $server);
$smarty->assign('sid', $sid);
?>
