<?php
/**
 * Backfill: sincroniza a mídia derivada (thumbnails, sprite e miniclips de
 * hover) dos vídeos já vinculados ao bucket GCS.
 *
 * Os vídeos novos já sobem a mídia derivada junto com os formatos (dentro de
 * upload_video_formats_gcs). Este script cobre os vídeos existentes, criados
 * antes dessa sincronização automática.
 *
 * Uso:
 *   php scripts/gcs_sync_thumbs.php                # sincroniza tudo
 *   php scripts/gcs_sync_thumbs.php --dry-run      # apenas lista o que seria enviado
 *   php scripts/gcs_sync_thumbs.php --vid 123      # apenas um vídeo
 *
 * Idempotente: pode rodar quantas vezes for preciso (o upload substitui o objeto).
 */

define('_VALID', 1);
define('_ENTER', true);
define('_CLI', true);

$basedir = dirname(dirname(__FILE__));
require $basedir . '/include/config.php';
require_once $basedir . '/include/function_thumbs.php';
require_once $basedir . '/include/function_server.php';

$args   = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
$dryRun = in_array('--dry-run', $args, true);
$onlyVid = null;
foreach ($args as $arg) {
    if (strpos($arg, '--vid=') === 0) {
        $onlyVid = (int) substr($arg, 6);
    }
}

// 1) Servidor GCS ativo
$sql = "SELECT * FROM servers WHERE server_type = 'gcs' AND status = '1' ORDER BY server_id ASC LIMIT 1";
$rs  = $conn->execute($sql);
if ($conn->Affected_Rows() != 1) {
    echo "Nenhum servidor GCS ativo encontrado. Nada a fazer.\n";
    exit(1);
}
$server    = $rs->fields;
$serverUrl = rtrim($server['video_url'], '/');
echo "Servidor GCS: " . $serverUrl . " (bucket: " . $server['gcs_bucket'] . ")\n";

// 2) Vídeos vinculados a esse servidor
$sql = "SELECT VID, server FROM video WHERE server <> ''";
if ($onlyVid) {
    $sql .= " AND VID = " . $onlyVid;
}
$sql  .= " ORDER BY VID ASC";
$rs    = $conn->execute($sql);
$videos = ($conn->Affected_Rows() > 0) ? $rs->getrows() : array();

$scanned = 0;
$ok      = 0;
$fail    = 0;

foreach ($videos as $video) {
    $vid = intval($video['VID']);

    // Apenas vídeos do servidor GCS em questão (FTP/local ficam intactos)
    if (rtrim((string) $video['server'], '/') !== $serverUrl) {
        continue;
    }
    ++$scanned;

    $thumbDir = get_thumb_dir($vid);

    if ($dryRun) {
        echo "Vídeo " . $vid . ": ";
        if (is_dir($thumbDir)) {
            $files = array_values(array_diff(scandir($thumbDir), array('.', '..')));
            $files = array_filter($files, function ($f) use ($thumbDir) {
                return is_file($thumbDir . '/' . $f);
            });
            echo (count($files) > 0)
                ? count($files) . " arquivo(s) seriam enviados\n"
                : "sem arquivos de mídia local\n";
        } else {
            echo "sem pasta de thumbs local\n";
        }
        continue;
    }

    echo "\n[" . $vid . "] Sincronizando mídia derivada...";
    if (upload_video_thumbs_gcs($vid, $server)) {
        echo " [OK]\n";
        ++$ok;
    } else {
        echo " [FALHA]\n";
        ++$fail;
    }
}

echo "\n===== Resumo =====\n";
if ($dryRun) {
    echo "Vídeos no servidor GCS: " . $scanned . " (dry-run — nada foi enviado)\n";
} else {
    echo "Vídeos processados: " . $scanned . " | OK: " . $ok . " | Falhas: " . $fail . "\n";
}
?>