<?php
defined('_VALID') or die('Restricted Access!');

require_once $config['BASE_DIR']. '/classes/filter.class.php';
require_once $config['BASE_DIR']. '/include/compat/json.php';
require_once $config['BASE_DIR']. '/include/adodb/adodb.inc.php';
require_once $config['BASE_DIR']. '/include/dbconn.php';
require_once $config['BASE_DIR']. '/classes/auth.class.php';
Auth::checkAdmin();

$response = array('status' => 0);

$data = (array) $_POST['data'];

$sid          = intval($data['id']);
$url          = trim($data['url']);
$video_url    = trim($data['video_url']);
$server_ip    = trim($data['server_ip']);
$ftp_username = trim($data['ftp_username']);
$ftp_password = trim($data['ftp_password']);
$ftp_root     = trim($data['ftp_root']);
$current_used = intval($data['current_used']);
$status       = intval($data['active']);
$server_type  = isset($data['server_type']) ? trim($data['server_type']) : 'ftp';
$gcs_key_path = isset($data['gcs_key_path']) ? trim($data['gcs_key_path']) : '';
$gcs_bucket   = isset($data['gcs_bucket']) ? trim($data['gcs_bucket']) : '';

// Cloudflare R2 / S3-compatível (fonte 'r2')
$s3_endpoint   = isset($data['s3_endpoint']) ? trim($data['s3_endpoint']) : '';
$s3_bucket     = isset($data['s3_bucket']) ? trim($data['s3_bucket']) : '';
$s3_access_key = isset($data['s3_access_key']) ? trim($data['s3_access_key']) : '';
$s3_secret_key = isset($data['s3_secret_key']) ? trim($data['s3_secret_key']) : '';
$s3_region     = isset($data['s3_region']) ? trim($data['s3_region']) : 'auto';

// Secret em branco no form = manter o que já está salvo (não apaga credencial
// por edição parcial da ficha).
if ($server_type === 'r2' && $s3_secret_key === '') {
    $rs = $conn->execute("SELECT s3_secret_key FROM servers WHERE server_id = ".$sid." LIMIT 1");
    if ($rs && $conn->Affected_Rows() == 1) {
        $s3_secret_key = (string)$rs->fields['s3_secret_key'];
    }
}

if ($sid <= 0) {
    echo json_encode($response);
    die();
}

if ($server_type === 'r2') {
    $sql = "UPDATE servers
            SET url = ".$conn->qStr($url).",
                video_url = ".$conn->qStr($video_url).",
                server_type = 'r2',
                s3_endpoint = ".$conn->qStr($s3_endpoint).",
                s3_bucket = ".$conn->qStr($s3_bucket).",
                s3_access_key = ".$conn->qStr($s3_access_key).",
                s3_secret_key = ".$conn->qStr($s3_secret_key).",
                s3_region = ".$conn->qStr($s3_region !== '' ? $s3_region : 'auto').",
                current_used = '".$current_used."',
                status = '".$status."'
            WHERE server_id = ".$sid."
            LIMIT 1";
} elseif ($server_type === 'gcs') {
    $sql = "UPDATE servers
            SET url = ".$conn->qStr($url).",
                video_url = ".$conn->qStr($video_url).",
                server_type = 'gcs',
                gcs_key_path = ".$conn->qStr($gcs_key_path).",
                gcs_bucket = ".$conn->qStr($gcs_bucket).",
                current_used = '".$current_used."',
                status = '".$status."'
            WHERE server_id = ".$sid."
            LIMIT 1";
} else {
    $sql = "UPDATE servers
            SET url = ".$conn->qStr($url).",
                video_url = ".$conn->qStr($video_url).",
                server_ip = ".$conn->qStr($server_ip).",
                ftp_username = ".$conn->qStr($ftp_username).",
                ftp_password = ".$conn->qStr($ftp_password).",
                ftp_root = ".$conn->qStr($ftp_root).",
                server_type = 'ftp',
                current_used = '".$current_used."',
                status = '".$status."'
            WHERE server_id = ".$sid."
            LIMIT 1";
}

$conn->execute($sql);
$response['status'] = 1;

echo json_encode($response);
die();
?>
