#!/usr/bin/env php
<?php
define('_VALID', 1);
$usage = "Uso: php export_cookies.php [browser]\n  browser: chrome (padrao), firefox, safari, edge, brave\n";

$browser = isset($argv[1]) ? $argv[1] : 'chrome';
require_once dirname(__FILE__) . '/../include/config.php';

$baseDir = $config['BASE_DIR'];
$cookiesFile = $baseDir . '/scripts/cookies.txt';
$curlCmd = $baseDir . '/scripts/yt-dlp';
$pyBinary = '';

foreach (array('/opt/homebrew/bin/python3', '/usr/local/bin/python3', '/usr/bin/python3', 'python3') as $bin) {
    $check = @shell_exec("$bin --version 2>&1");
    if ($check && stripos($check, 'Python 3') !== false) {
        $ver = '';
        preg_match('/Python 3\.(\d+)/', $check, $m);
        if (isset($m[1]) && (int)$m[1] >= 10) { $pyBinary = $bin; break; }
    }
}
if (!$pyBinary) { $pyBinary = '/opt/homebrew/bin/python3'; }

echo "Exportando cookies do browser: $browser\n";
echo "Python: $pyBinary\n";

$cmd = sprintf(
    '%s %s --cookies-from-browser %s --cookies %s --skip-download --no-warnings -j "https://www.youtube.com/watch?v=p3WDI8lG9Iw" 2>&1',
    escapeshellarg($pyBinary),
    escapeshellarg($curlCmd),
    escapeshellarg($browser),
    escapeshellarg($cookiesFile)
);

$output = shell_exec($cmd);
if (file_exists($cookiesFile)) {
    $lines = count(file($cookiesFile));
    echo "Cookies salvos em: $cookiesFile ($lines linhas)\n";
    $ytLines = 0;
    $fh = fopen($cookiesFile, 'r');
    while (($line = fgets($fh)) !== false) {
        if (stripos($line, '.youtube.com') !== false || stripos($line, '.google.com') !== false) { $ytLines++; }
    }
    fclose($fh);
    echo "Cookies do YouTube/Google: $ytLines\n";
} else {
    echo "ERRO: arquivo de cookies nao foi criado.\n";
}

if ($output && strpos($output, 'ERROR') === false) {
    echo "TESTE: OK - cookies funcionam!\n";
} else {
    echo "TESTE: Falhou - o browser pode nao estar logado no YouTube.\n  Abra o YouTube no $browser, faca login e rode novamente.\n";
}
