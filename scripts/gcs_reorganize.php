<?php
/**
 * Reorganizes GCS video objects into per-video folders.
 *
 * Moves every object named  h264/{VID}_{label}.{ext}  to  h264/{VID}/{label}.{ext}
 * (e.g. h264/88_720p.mp4 -> h264/88/720p.mp4) using server-side copy + delete
 * (no download/upload involved).
 *
 * Usage (run from the VM / project root, like the migrations runner):
 *   php scripts/gcs_reorganize.php <server_id> [--dry-run]
 *
 * DB credentials come from env vars (same convention as function_migrations.php):
 *   DB_HOST (localhost)  DB_USER (root)  DB_PASS ('')  DB_NAME (avs)
 *
 * The service account needs storage.objects.list + copy + delete on the bucket
 * (roles/storage.objectAdmin).
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}
define('_VALID', true);

$serverId = 0;
$dryRun   = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (ctype_digit($arg)) {
        $serverId = (int) $arg;
    }
}

if ($serverId <= 0) {
    fwrite(STDERR, "Usage: php scripts/gcs_reorganize.php <server_id> [--dry-run]\n");
    exit(1);
}

$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'avs';

$db = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($db->connect_error) {
    fwrite(STDERR, "DB connection failed: " . $db->connect_error . "\n");
    exit(1);
}
$db->set_charset('utf8');

$res = $db->query("SELECT * FROM servers WHERE server_id = " . $serverId . " LIMIT 1");
if (!$res || $res->num_rows !== 1) {
    fwrite(STDERR, "Server ID {$serverId} not found.\n");
    exit(1);
}
$server = $res->fetch_assoc();
$res->free();
$db->close();

if (!isset($server['server_type']) || $server['server_type'] !== 'gcs') {
    fwrite(STDERR, "Server ID {$serverId} is not a GCS server.\n");
    exit(1);
}

$bucket  = $server['gcs_bucket'];
$keyPath = $server['gcs_key_path'];

if (empty($bucket) || empty($keyPath)) {
    fwrite(STDERR, "GCS server {$serverId} is missing gcs_bucket or gcs_key_path.\n");
    exit(1);
}
if (!file_exists($keyPath)) {
    fwrite(STDERR, "Service account key not found: {$keyPath}\n");
    exit(1);
}

require __DIR__ . '/../classes/gcs.class.php';

$gcs = new GCS($keyPath, $bucket);

echo "== GCS bucket reorganization ==\n";
echo "   Server ID : {$serverId}\n";
echo "   Bucket    : {$bucket}\n";
echo "   Mode      : " . ($dryRun ? "DRY-RUN (no changes)\n" : "LIVE\n");

$objects = $gcs->listObjects('h264/');
if ($objects === false) {
    fwrite(STDERR, "Failed to list objects: " . $gcs->getError() . "\n");
    exit(1);
}

// h264/{VID}_{label}.{ext}  ->  h264/{VID}/{label}.{ext}
$pattern = '/^h264\/(\d+)_([A-Za-z0-9]+)\.([A-Za-z0-9]+)$/';
$existing = array_flip($objects);

$moved   = 0;
$already = 0;
$skipped = 0;
$failed  = 0;

foreach ($objects as $name) {
    if (!preg_match($pattern, $name, $m)) {
        $skipped++;
        continue;
    }

    $dest = 'h264/' . $m[1] . '/' . $m[2] . '.' . $m[3];

    if (isset($existing[$dest])) {
        echo "  [SKIP] {$name}: destino {$dest} já existe\n";
        $already++;
        continue;
    }

    if ($dryRun) {
        echo "  [DRY] moveria {$name} -> {$dest}\n";
        $moved++;
        continue;
    }

    if (!$gcs->copyObject($name, $dest)) {
        echo "  [FAIL] {$name}: " . $gcs->getError() . "\n";
        $failed++;
        continue;
    }

    if ($gcs->deleteObject($name)) {
        echo "  [OK] {$name} -> {$dest}\n";
        $moved++;
    } else {
        echo "  [WARN] {$name} copiado para {$dest}, mas falha ao remover o original: " . $gcs->getError() . "\n";
        $moved++;
    }
}

echo "\n== " . ($dryRun ? 'DRY-RUN' : 'Resultado') . ": movidos: {$moved} | já existiam: {$already} | ignorados: {$skipped} | falhas: {$failed} ==\n";
echo "\nPronto. Os players GCS já usam a nova estrutura h264/{VID}/{resolução}.{ext}.\n";
echo "Novos uploads também já gravam nessa estrutura.\n";
exit($failed > 0 ? 1 : 0);