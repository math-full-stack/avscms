<?php
defined('_VALID') or die('Restricted Access!');
// Credenciais vêm do .env (via include/dotenv.php) ou do ambiente real.
// Sem fallback hardcoded: senha ausente = falha ao conectar (fail-closed).
$config['db_type'] = 'mysqli';
$config['db_host'] = getenv('DB_HOST') ?: '127.0.0.1';
$config['db_user'] = getenv('DB_USER') ?: 'avs_app';
$config['db_pass'] = getenv('DB_PASSWORD') ?: '';
$config['db_name'] = getenv('DB_NAME') ?: 'avs';
?>
