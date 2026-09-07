<?php
defined('_VALID') or die('Restricted Access!');

$conn = ADONewConnection($config['db_type']);

// Retry connection up to 3 times with delay (handles SSH tunnel hiccups)
$connected = false;
for ($i = 0; $i < 3; $i++) {
    try {
        if ($conn->Connect($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name'])) {
            $connected = true;
            break;
        }
    } catch (\mysqli_sql_exception $e) {
        // Connection failed, will retry
    }
    if ($i < 2) sleep(1);
}
if (!$connected) {
    echo 'Could not connect to mysql! Please check your database settings!';
    die();
}
$conn->execute("SET NAMES 'utf8mb4'");

// Auto-expire idle connections after 5 minutes (300s) - long enough for tunnel stability,
// short enough to not exhaust max_connections. 60s was too aggressive causing "gone away".
$conn->execute("SET SESSION wait_timeout = 300");
$conn->execute("SET SESSION interactive_timeout = 300");

// Auto-close DB connection on script shutdown.
// ADODB's _connectionID holds the raw mysqli resource — save it now and
// close it directly in the shutdown function, guaranteeing the socket is
// freed regardless of PHP's object destruction order.
$_avscms_raw_mysqli = $conn->_connectionID;
register_shutdown_function(function () use (&$_avscms_raw_mysqli, &$conn) {
    // Nullify ADODB's handle first so its destructors don't touch the
    // already-closed mysqli object (causes "mysqli object is already closed").
    if ($conn && is_object($conn)) {
        $conn->_connectionID = null;
    }
    // Now safely close the raw mysqli.
    if ($_avscms_raw_mysqli) {
        if (is_object($_avscms_raw_mysqli) && $_avscms_raw_mysqli instanceof \mysqli) {
            @$_avscms_raw_mysqli->close();
        } elseif (is_resource($_avscms_raw_mysqli)) {
            @mysqli_close($_avscms_raw_mysqli);
        }
    }
});
?>
