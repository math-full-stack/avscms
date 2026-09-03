<?php
defined('_VALID') or die('Restricted Access!');

require $config['BASE_DIR'] . '/classes/filter.class.php';
require $config['BASE_DIR'] . '/classes/auth.class.php';
Auth::checkAdmin();

header('Content-Type: application/json; charset=utf-8');

$ip       = isset($_POST['server_ip']) ? trim($_POST['server_ip']) : '';
$username = isset($_POST['ftp_username']) ? trim($_POST['ftp_username']) : '';
$password = isset($_POST['ftp_password']) ? trim($_POST['ftp_password']) : '';
$root     = isset($_POST['ftp_root']) ? trim($_POST['ftp_root']) : '';

if (empty($ip) || empty($username) || empty($password)) {
    echo json_encode(array(
        'status'  => 0,
        'message' => 'Por favor, preencha o IP/Host, Usuário FTP e Senha FTP para testar a conexão.'
    ));
    exit();
}

if (!function_exists('ftp_connect')) {
    echo json_encode(array(
        'status'  => 0,
        'message' => 'A extensão PHP FTP não está habilitada no servidor principal!'
    ));
    exit();
}

$connId = @ftp_connect($ip, 21, 10);
if (!$connId) {
    echo json_encode(array(
        'status'  => 0,
        'message' => 'Não foi possível conectar ao servidor FTP no IP/Host: ' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . ' (Porta 21).'
    ));
    exit();
}

$loginResult = @ftp_login($connId, $username, $password);
if (!$loginResult) {
    @ftp_close($connId);
    echo json_encode(array(
        'status'  => 0,
        'message' => 'Falha na autenticação FTP! Usuário ou senha incorretos.'
    ));
    exit();
}

@ftp_pasv($connId, true);

if (!empty($root)) {
    if (!@ftp_chdir($connId, $root)) {
        @ftp_close($connId);
        echo json_encode(array(
            'status'  => 0,
            'message' => 'Login efetuado com sucesso, porém o diretório FTP Root "' . htmlspecialchars($root, ENT_QUOTES, 'UTF-8') . '" não foi encontrado ou não é acessível.'
        ));
        exit();
    }
}

// Teste de permissão de escrita
$testFile = 'test_avs_' . time() . '.tmp';
$tempHandle = fopen('php://temp', 'r+');
fwrite($tempHandle, 'AVS FTP Test Connection');
rewind($tempHandle);

$writeOk = @ftp_fput($connId, $testFile, $tempHandle, FTP_ASCII);
fclose($tempHandle);

if ($writeOk) {
    @ftp_delete($connId, $testFile);
    @ftp_close($connId);
    echo json_encode(array(
        'status'  => 1,
        'message' => 'Conexão FTP estabelecida com sucesso! Permissões de escrita verificadas.'
    ));
    exit();
} else {
    @ftp_close($connId);
    echo json_encode(array(
        'status'  => 0,
        'message' => 'Conexão e autenticação efetuadas com sucesso, mas o usuário FTP não possui permissão de escrita no diretório raiz especificado.'
    ));
    exit();
}
