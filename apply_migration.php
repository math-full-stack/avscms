<?php
define('_VALID', true);
require 'include/dotenv.php';
require 'include/config.db.php';

$dsn = "mysql:host=127.0.0.1;port=3307;dbname=avs;charset=utf8mb4";
$pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = file_get_contents('sql/migrations/20261003000001_add_novos_feed_ad_group.sql');
$pdo->exec($sql);
echo "Migration aplicada com sucesso\n";