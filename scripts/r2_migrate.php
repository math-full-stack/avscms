<?php
/**
 * Migra TODA a mídia de um bucket Google Cloud Storage (servidor GCS) para um
 * bucket Cloudflare R2 (servidor R2), e reaponta os vídeos para a nova base.
 *
 * Por que existe: o egress do GCP é cobrado por GB; o R2 tem egress zero. A
 * cópia é feita objeto a objeto (download autenticado do GCS -> PutObject no R2)
 * porque os dois backends não conversam entre si.
 *
 * Uso (do VM / raiz do projeto, como o runner de migrations):
 *   php scripts/r2_migrate.php <gcs_server_id> <r2_server_id> [opções]
 *
 * Opções:
 *   --dry-run          Só lista o que seria copiado (nenhuma escrita)
 *   --limit=N          Copia no máximo N objetos (para um teste rápido)
 *   --prefix=h264/      Copia apenas objetos com este prefixo
 *   --delete-source    Remove do GCS os objetos já copiados e verificados
 *   --disable-source   Desativa o servidor GCS no final (só depois de verificar
 *                      que TODO objeto de origem existe no R2)
 *   --skip-video-url   Não reaponta video.server para a base do R2
 *   --test             Só valida as credenciais do R2 (list + write + delete)
 *                      e sai — rode isto ANTES da cópia completa
 *
 * Credenciais do banco vêm das env vars (mesma convenção dos outros scripts):
 *   DB_HOST (localhost)  DB_USER (root)  DB_PASS ('')  DB_NAME (avs)
 *
 * Idempotente: objetos que já existem no R2 são pulados, então pode ser
 * interrompido e re-executado com segurança.
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only.\n");
}

$args = array_slice($argv, 1);
$gcsServerId = 0;
$r2ServerId  = 0;
$dryRun      = false;
$deleteSource  = false;
$disableSource = false;
$skipVideoUrl  = false;
$testOnly      = false;
$limit    = 0;
$prefix   = '';

foreach ($args as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--delete-source') {
        $deleteSource = true;
    } elseif ($arg === '--disable-source') {
        $disableSource = true;
    } elseif ($arg === '--skip-video-url') {
        $skipVideoUrl = true;
    } elseif ($arg === '--test') {
        $testOnly = true;
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = max(0, (int) substr($arg, 8));
    } elseif (strpos($arg, '--prefix=') === 0) {
        $prefix = trim(substr($arg, 9), '/');
        if ($prefix !== '') {
            $prefix .= '/';
        }
    } elseif (ctype_digit($arg)) {
        if ($gcsServerId === 0) {
            $gcsServerId = (int) $arg;
        } else {
            $r2ServerId = (int) $arg;
        }
    }
}

if ($gcsServerId <= 0 || $r2ServerId <= 0) {
    fwrite(STDERR, "Usage: php scripts/r2_migrate.php <gcs_server_id> <r2_server_id> [--test] [--dry-run] [--limit=N] [--prefix=h264/] [--delete-source] [--disable-source] [--skip-video-url]\n");
    exit(1);
}

if ($deleteSource && $dryRun) {
    fwrite(STDERR, "--delete-source não faz sentido com --dry-run.\n");
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

/**
 * Carrega a linha de um servidor pelo id.
 */
function r2m_load_server(mysqli $db, $id)
{
    $res = $db->query("SELECT * FROM servers WHERE server_id = " . (int) $id . " LIMIT 1");
    if (!$res || $res->num_rows !== 1) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return $row;
}

$gcsServer = r2m_load_server($db, $gcsServerId);
$r2Server  = r2m_load_server($db, $r2ServerId);

if (!$gcsServer) {
    fwrite(STDERR, "Servidor GCS {$gcsServerId} não encontrado.\n");
    exit(1);
}
if (!$r2Server) {
    fwrite(STDERR, "Servidor R2 {$r2ServerId} não encontrado.\n");
    exit(1);
}
if (!isset($gcsServer['server_type']) || $gcsServer['server_type'] !== 'gcs') {
    fwrite(STDERR, "Servidor {$gcsServerId} não é do tipo 'gcs'.\n");
    exit(1);
}
if (!isset($r2Server['server_type']) || $r2Server['server_type'] !== 'r2') {
    fwrite(STDERR, "Servidor {$r2ServerId} não é do tipo 'r2'.\n");
    exit(1);
}

// ---------------------------------------------------------------- GCS client
$gcsBucket = (string) $gcsServer['gcs_bucket'];
if ($gcsBucket === '') {
    fwrite(STDERR, "Servidor GCS sem gcs_bucket.\n");
    exit(1);
}

$keyPath = (string) $gcsServer['gcs_key_path'];
$envJson = getenv('GCS_KEY_JSON');
$envPath = getenv('GCS_KEY_PATH');

if ($envJson !== false && $envJson !== '') {
    $tmpKey = tempnam(sys_get_temp_dir(), 'r2m_gcs_');
    file_put_contents($tmpKey, $envJson);
    $keyPath = $tmpKey;
} elseif ($envPath !== false && $envPath !== '') {
    $keyPath = $envPath;
} elseif (!file_exists($keyPath)) {
    $relative = dirname(__DIR__) . '/' . $keyPath;
    if (file_exists($relative)) {
        $keyPath = $relative;
    }
}

if (!file_exists($keyPath)) {
    fwrite(STDERR, "Service account key não encontrada: {$keyPath}\n");
    exit(1);
}

require_once dirname(__DIR__) . '/classes/gcs.class.php';
require_once dirname(__DIR__) . '/classes/s3.class.php';

$gcs = new GCS($keyPath, $gcsBucket);
$token = $gcs->getReadAccessToken();
if (!$token) {
    fwrite(STDERR, "Não foi possível obter token de leitura do GCS: " . $gcs->getError() . "\n");
    exit(1);
}

// ----------------------------------------------------------------- R2 client
$endpoint = (string) $r2Server['s3_endpoint'];
if (strpos($endpoint, '://') === false) {
    $endpoint = 'https://' . trim($endpoint, '.') . '.r2.cloudflarestorage.com';
}

$r2 = new S3(
    $endpoint,
    (string) $r2Server['s3_bucket'],
    (string) $r2Server['s3_access_key'],
    (string) $r2Server['s3_secret_key'],
    ((string) $r2Server['s3_region'] !== '' ? (string) $r2Server['s3_region'] : 'auto'),
    (string) $r2Server['video_url']
);

/**
 * Content-Type por extensão.
 */
function r2m_content_type($object)
{
    $ext = strtolower(pathinfo($object, PATHINFO_EXTENSION));
    $map = array(
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'vtt'  => 'text/vtt',
        'txt'  => 'text/plain'
    );
    return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
}

/**
 * Baixa um objeto do GCS (objeto privado) para um arquivo local via Bearer.
 */
function r2m_gcs_download($bucket, $token, $object, $target)
{
    $url = 'https://storage.googleapis.com/storage/v1/b/' . rawurlencode($bucket)
         . '/o/' . rawurlencode($object) . '?alt=media';

    $fp = @fopen($target, 'wb');
    if (!$fp) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FILE           => $fp,
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 0
    ));
    $ok   = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $err !== '' || $code !== 200 || !file_exists($target) || filesize($target) <= 0) {
        @unlink($target);
        return false;
    }

    return true;
}

// ------------------------------------------------------------- smoke test
if ($testOnly) {
    echo "== Teste de credenciais do R2 (server #{$r2ServerId}) ==\n";

    $list = $r2->testConnection();
    echo '   list  : ' . ($list['success'] ? 'OK' : 'FALHA') . ' — ' . strip_tags($list['message']) . "\n";

    $write = $r2->testWrite();
    echo '   write : ' . ($write['success'] ? 'OK' : 'FALHA') . ' — ' . strip_tags($write['message']) . "\n";

    if (!$list['success'] || !$write['success']) {
        exit(1);
    }

    echo "\nCredenciais válidas. Pode rodar a migração completa.\n";
    exit(0);
}

echo "== Migração GCS -> Cloudflare R2 ==\n";
echo "   Origem  : server #{$gcsServerId} (gs://{$gcsBucket})\n";
echo "   Destino : server #{$r2ServerId} (s3://" . $r2Server['s3_bucket'] . ")\n";
echo "   Modo    : " . ($dryRun ? "DRY-RUN (sem alterações)\n" : "LIVE\n");
if ($prefix !== '') {
    echo "   Prefixo : {$prefix}\n";
}
if ($limit > 0) {
    echo "   Limite  : {$limit} objetos\n";
}

// ------------------------------------------------------------------ listagem
$sourceObjects = $gcs->listObjects($prefix);
if ($sourceObjects === false) {
    fwrite(STDERR, "Falha ao listar o bucket de origem: " . $gcs->getError() . "\n");
    exit(1);
}

echo "\nObjetos na origem: " . count($sourceObjects) . "\n";

// O que já está no destino (retomada de execução interrompida).
$destObjects = $r2->listObjects($prefix);
if ($destObjects === false) {
    fwrite(STDERR, "Falha ao listar o bucket de destino: " . $r2->getError() . "\n");
    exit(1);
}
$destSet = array_flip($destObjects);

$copied  = 0;
$skipped = 0;
$failed  = 0;
$failedObjects = array();

foreach ($sourceObjects as $object) {
    if ($limit > 0 && ($copied + $skipped) >= $limit) {
        echo "\n(Limite de {$limit} objetos atingido.)\n";
        break;
    }

    if (isset($destSet[$object])) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        echo "  [DRY] copiaria {$object}\n";
        $copied++;
        continue;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'r2m_');
    if ($tmp === false) {
        fwrite(STDERR, "Sem espaço para arquivo temporário.\n");
        exit(1);
    }

    if (!r2m_gcs_download($gcsBucket, $token, $object, $tmp)) {
        echo "  [FAIL] download {$object}\n";
        @unlink($tmp);
        $failed++;
        $failedObjects[] = $object;
        continue;
    }

    $size = filesize($tmp);
    $uri  = $r2->upload($tmp, $object, r2m_content_type($object), array(
        // Mídia imutável (thumbs/vídeo por VID): cache longo.
        'cacheControl' => (strpos($object, 'thumbs/') === 0)
            ? 'public, max-age=604800'
            : 'public, max-age=31536000'
    ));
    @unlink($tmp);

    if ($uri === false) {
        echo "  [FAIL] upload {$object}: " . $r2->getError() . "\n";
        $failed++;
        $failedObjects[] = $object;
        continue;
    }

    echo "  [OK] {$object} (" . round($size / 1024, 1) . " KB)\n";
    $copied++;
}

echo "\n== Cópia: enviados: {$copied} | já existiam: {$skipped} | falhas: {$failed} ==\n";

if ($failed > 0) {
    echo "\nObjetos com falha:\n";
    foreach ($failedObjects as $o) {
        echo "  - {$o}\n";
    }
    fwrite(STDERR, "\nAbortando: corrija as falhas e rode de novo (é retomável).\n");
    exit(1);
}

if ($dryRun) {
    exit(0);
}

// ------------------------------------------------------------ verificação final
$destObjects = $r2->listObjects($prefix);
if ($destObjects === false) {
    fwrite(STDERR, "Falha ao relistar o destino para verificação: " . $r2->getError() . "\n");
    exit(1);
}
$destSet = array_flip($destObjects);

$missing = array();
foreach ($sourceObjects as $object) {
    if (!isset($destSet[$object])) {
        $missing[] = $object;
    }
}

if ($missing) {
    fwrite(STDERR, "Verificação falhou: " . count($missing) . " objeto(s) da origem não estão no R2.\n");
    foreach (array_slice($missing, 0, 20) as $o) {
        fwrite(STDERR, "  - {$o}\n");
    }
    exit(1);
}
echo "Verificação OK: todos os " . count($sourceObjects) . " objetos de origem existem no R2.\n";

$oldVideoUrl = rtrim((string) $gcsServer['video_url'], '/');
$newVideoUrl = rtrim((string) $r2Server['video_url'], '/');

// ----------------------------------------------------- reaponta video.server
if (!$skipVideoUrl && $oldVideoUrl !== '' && $newVideoUrl !== '' && $oldVideoUrl !== $newVideoUrl) {
    $stmt = $db->prepare("UPDATE video SET server = ? WHERE server = ?");
    $stmt->bind_param('ss', $newVideoUrl, $oldVideoUrl);
    $stmt->execute();
    $movedVideos = $stmt->affected_rows;
    $stmt->close();
    echo "video.server reapontado: {$oldVideoUrl} -> {$newVideoUrl} ({$movedVideos} linha(s)).\n";
} elseif ($oldVideoUrl === $newVideoUrl) {
    echo "video.server já aponta para a base do R2.\n";
}

// ------------------------------------------------------------ remove origem
if ($deleteSource) {
    $removed = 0;
    $srcFailed = 0;
    foreach ($sourceObjects as $object) {
        if ($gcs->deleteObject($object)) {
            $removed++;
        } else {
            $srcFailed++;
            echo "  [WARN] falha ao remover do GCS: {$object}\n";
        }
    }
    echo "Removidos do GCS: {$removed}"
       . ($srcFailed > 0 ? " | falhas: {$srcFailed}" : "") . ".\n";
}

// ---------------------------------------------------------- desativa origem
if ($disableSource) {
    $db->query("UPDATE servers SET status = '0' WHERE server_id = " . (int) $gcsServerId . " LIMIT 1");
    echo "Servidor GCS #{$gcsServerId} desativado (status=0). Novos uploads vão para o R2.\n";
}

echo "\nPronto.\n";
exit(0);
