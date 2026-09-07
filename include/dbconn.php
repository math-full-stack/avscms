<?php
defined('_VALID') or die('Restricted Access!');

$conn = ADONewConnection($config['db_type']);
if ( !$conn->Connect($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']) ) {
    echo 'Could not connect to mysql! Please check your database settings!';
    die();
}
$conn->execute("SET NAMES 'utf8mb4'");

// Auto-close DB connection when script ends (CLI + web).
// Prevents connection leak: without this, CLI scripts spawned by cron /
// background processes leave MySQL connections in Sleep state until
// wait_timeout (8h default) expires — exhausting max_connections.
register_shutdown_function(function () use (&$conn) {
    if ($conn && is_object($conn) && $conn->_connectionID) {
        @$conn->Close();
    }
});
?>
