<?php
defined('_VALID') or die('Restricted Access!');

$conn = ADONewConnection($config['db_type']);
if ( !$conn->Connect($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']) ) {
    echo 'Could not connect to mysql! Please check your database settings!';
    die();
}
$conn->execute("SET NAMES 'utf8mb4'");

// Auto-expire idle connections after 60s (MySQL default is 8h = 28800s).
// Critical for tunnel-based MySQL: when the tunnel drops, the MySQL server
// keeps sleeping connections alive. With 151 max_connections, this fills up
// in minutes. Setting per-session timeout ensures cleanup.
$conn->execute("SET SESSION wait_timeout = 60");
$conn->execute("SET SESSION interactive_timeout = 60");

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
