<?php
/**
 * GCS secure-bucket hardening (one-time operation).
 *
 * Makes an existing GCS video bucket PRIVATE so playback only happens through
 * short-lived V4 signed URLs:
 *   1. Removes the public (allUsers) ACL from every object under h264/, hd/,
 *      iphone/ and the _avs_test/ scratch prefix.
 *   2. Sets the bucket CORS rules to allow the site origin (required by the
 *      Media Bunny player, which reads objects cross-origin).
 *
 * Usage (run from the VM / project root, like the migrations runner):
 *   php scripts/gcs_secure_bucket.php <server_id> [--origin https://novinhasbr.net,http://localhost] [--dry-run]
 *
 *   --origin accepts a comma-separated list (or repeated flags) so both the
 *   production origin and localhost can be allowed in the same CORS rule.
 *   Note: setCors() REPLACES the whole bucket CORS config, so list ALL
 *   origins you need in a single run.
 *
 * DB credentials come from env vars (same convention as function_migrations.php):
 *   DB_HOST (localhost)  DB_USER (root)  DB_PASS ('')  DB_NAME (avs)
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

define('_VALID', true);

$args    = array_slice($argv, 1);
$serverId = 0;
$origins  = array();
$dryRun   = false;

foreach ($args as $arg) {
    if (preg_match('/^--origin=(.+)$/', $arg, $m)) {
        foreach (explode(',', $m[1]) as $o) {
            $o = trim($o);
            if ($o !== '') {
                $origins[] = rtrim($o, '/');
            }
        }
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (ctype_digit($arg)) {
        $serverId = (int) $arg;
    }
}
$origins = array_values(array_unique($origins));

if ($serverId <= 0) {
    fwrite(STDERR, "Usage: php scripts/gcs_secure_bucket.php <server_id> [--origin https://site,http://localhost] [--dry-run]\n");
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

echo "== GCS secure-bucket hardening ==\n";
echo "   Server ID : {$serverId}\n";
echo "   Bucket    : {$bucket}\n";
echo "   Mode      : " . ($dryRun ? "DRY-RUN (no changes)\n" : "LIVE\n");

// --- Step 1: remove public ACLs -------------------------------------------------
$prefixes = array('h264/', 'hd/', 'iphone/', '_avs_test/');
$scanned  = 0;
$removed  = 0;
$already  = 0;
$failed   = 0;

foreach ($prefixes as $prefix) {
    $objects = $gcs->listObjects($prefix);
    if ($objects === false) {
        echo "  [SKIP] não foi possível listar '{$prefix}': {$gcs->getError()}\n";
        continue;
    }
    foreach ($objects as $object) {
        $scanned++;
        if ($dryRun) {
            echo "  [DRY] removeria ACL público de {$object}\n";
            continue;
        }
        if ($gcs->removePublicAcl($object)) {
            // We can't tell 204 vs 404 apart from the return value alone;
            // either outcome means "no public ACL anymore".
            $removed++;
        } else {
            // 400 on buckets with uniform bucket-level access is EXPECTED:
            // those buckets ignore object ACLs entirely (access via IAM only).
            $err = $gcs->getError();
            if (stripos($err, 'uniform bucket-level') !== false || stripos($err, '400') !== false) {
                echo "  [SKIP] {$object}: bucket usa uniform bucket-level access (ACL de objeto não se aplica; controle via IAM)\n";
            } else {
                echo "  [FAIL] falha ao remover ACL público de {$object}: {$err}\n";
                $failed++;
            }
        }
    }
}

if (!$dryRun) {
    echo "== Objetos verificados: {$scanned} | ACL público removido (ou já privado): {$removed} | falhas: {$failed} ==\n";
} else {
    echo "== DRY-RUN: {$scanned} objetos seriam verificados ==\n";
}

// --- Step 2: CORS -----------------------------------------------------------------
if (empty($origins)) {
    $env = getenv('GCLOUD_SITE_URL');
    if ($env) {
        foreach (explode(',', $env) as $o) {
            $o = rtrim(trim($o), '/');
            if ($o !== '') {
                $origins[] = $o;
            }
        }
        $origins = array_values(array_unique($origins));
    }
}
if (empty($origins)) {
    echo "\n[AVISO] --origin não informado (ex.: --origin=https://novinhasbr.net,http://localhost). CORS não alterado.\n";
    echo "        Rode novamente com --origin para liberar o Media Bunny ler os vídeos.\n";
} else {
    if ($dryRun) {
        echo "\n[DRY] aplicaria CORS permitindo origens: " . implode(', ', $origins) . "\n";
    } else {
        if ($gcs->setCors($origins)) {
            echo "\n[OK] CORS configurado para: " . implode(', ', $origins) . " (GET/HEAD/OPTIONS).\n";
        } else {
            echo "\n[FAIL] CORS: {$gcs->getError()}\n";
            exit(1);
        }
    }
}

echo "\nPronto. Novos uploads já são privados; a partir de agora os players só acessam\n";
echo "os vídeos por URLs assinadas (expirantes). Não se esqueça de rodar esta operação\n";
echo "uma única vez para cada servidor GCS cadastrado.\n";
echo "\n[OBS] Se o bucket usa 'Uniform bucket-level access', as ACLs de objeto são\n";
echo "ignoradas — verifique no Console GCS se não existe um binding público de IAM\n";
echo "(allUsers) no nível do bucket; nesse caso remova-o manualmente, pois só IAM o faz.\n";
exit(0);