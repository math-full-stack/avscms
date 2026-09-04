<?php
defined('_VALID') or die('Restricted Access!');

require_once $config['BASE_DIR']. '/classes/filter.class.php';
require_once $config['BASE_DIR']. '/include/compat/json.php';
require_once $config['BASE_DIR']. '/include/adodb/adodb.inc.php';
require_once $config['BASE_DIR']. '/include/dbconn.php';
require_once $config['BASE_DIR']. '/classes/auth.class.php';
Auth::checkAdmin();

$response = array('status' => 0);

$filter  = new VFilter();
$sid     = $filter->get('server_id', 'INTEGER');

$sql = "SELECT * from servers WHERE server_id = " . intval($sid) . " LIMIT 1";
$rs = $conn->execute($sql);
if ( $conn->Affected_Rows() == 1 ) {
	$server = $rs->getrows();
	$server = $server[0];
	foreach ($server as $key=>$value) {
		if ($key == 'status') {
			$key = 'active';
		}
		// Never send FTP password or GCS key path to the browser
		if ($key == 'ftp_password' || $key == 'gcs_key_path') {
			$response[$key] = '***';
			continue;
		}
		$response[$key] = $value;
	}		
	$response['status'] = 1;
}

echo json_encode($response);
die();
?>
