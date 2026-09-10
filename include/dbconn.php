<?php
defined('_VALID') or die('Restricted Access!');

$connected = false;
$last_error = '';

// Cloud Run: Cloud SQL Auth Proxy listens on a Unix socket
// Path format: /cloudsql/PROJECT:REGION:INSTANCE
if (isset($_ENV['K_SERVICE'])) {
    $socket_path = '/cloudsql/novinhasbr:southamerica-east1:pornozinho-sql';
    $mysqli = @new \mysqli('localhost', $config['db_user'], $config['db_pass'], $config['db_name'], 0, $socket_path);
    if ($mysqli->connect_error) {
        $last_error = 'mysqli: ' . $mysqli->connect_error;
    } else {
        $mysqli->set_charset('utf8mb4');
        $conn = ADONewConnection($config['db_type']);
        $conn->_connectionID = $mysqli;
        $conn->dialect = 'mysql';
        $conn->fmtDate = 'Y-m-d';
        $connected = true;
    }
}

// Local / VM: use ADOdb with TCP
if (!$connected) {
    $conn = ADONewConnection($config['db_type']);
    $host = $config['db_host'];
    if (!empty($config['db_port']) && $config['db_port'] != '3306') {
        $host .= ':' . $config['db_port'];
    }
    for ($i = 0; $i < 3; $i++) {
        try {
            if ($conn->Connect($host, $config['db_user'], $config['db_pass'], $config['db_name'])) {
                $connected = true;
                break;
            }
            $last_error = $conn->ErrorMsg() ?: 'Connect returned false';
        } catch (\mysqli_sql_exception $e) {
            $last_error = $e->getMessage();
        }
        if ($i < 2) sleep(1);
    }
}
if (!$connected) {
    echo 'Could not connect to mysql! Error: ' . htmlspecialchars($last_error);
    die();
}
$conn->execute("SET NAMES 'utf8mb4'");

// Auto-expire idle connections after 5 minutes (300s) - long enough for tunnel stability,
// short enough to not exhaust max_connections. 60s was too aggressive causing "gone away".
$conn->execute("SET SESSION wait_timeout = 300");
$conn->execute("SET SESSION interactive_timeout = 300");

// Do NOT register a shutdown function to close the DB connection.
// PHP destroys objects in an undefined order during shutdown — a custom
// shutdown handler that nullifies $conn->_connectionID breaks
// Session::write() (which runs later and needs the same $conn).
// PHP's natural GC handles connection cleanup.
?>
