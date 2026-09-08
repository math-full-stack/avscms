<?php
defined('_VALID') or die('Restricted Access!');
$config['db_type'] = 'mysqli';
$config['db_host'] = getenv('DB_HOST') ?: '127.0.0.1';
$config['db_user'] = getenv('DB_USER') ?: 'avs_app';
$config['db_pass'] = getenv('DB_PASSWORD') ?: '.)V>oZ2rHf{/zKM9';
$config['db_name'] = getenv('DB_NAME') ?: 'avs';
?>
