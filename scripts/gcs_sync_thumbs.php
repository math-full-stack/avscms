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
 *   php scripts/gcs_sync_thumbs.php                     # sincroniza tudo
 *   php scripts/gcs_sync_thumbs.php --dry-run           # apenas lista o que seria enviado
 *   php scripts/gcs_sync_thumbs.php --vid 123           # apenas um vídeo
 *   php scripts/gcs_sync_thumbs.php --prune             # apaga o tmb/{VID} local após upload OK
 *   php scripts/gcs_sync_thumbs.php --keep-local        # nunca apaga o local (mesmo com del_original_video)
 *
 * O sprite.jpg do timeline preview é garantido (gerado das thumbs 1..20 quando
 * o player Main usa preview) antes do envio, para que a pasta local possa ser
 * removida sem perder o preview. O --prune só remove quando o upload de toda a
 * pasta foi confirmado no bucket e o sprite não é mais necessário localmente.
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

$args      = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
$dryRun    = in_array('--dry-run', $args, true);
$prune     = in_array('--prune', $args, true);
$keepLocal = in_array('--keep-local', $args, true);
$onlyVid   = null;
foreach ($args as $arg) {
    if (strpos($arg, '--vid=') === 0) {
        $onlyVid = (int) substr($arg, 6);
    }
}

// Deleção local: --prune força apagar; --keep-local força manter; sem flags
// segue a config global del_original_video (mesma regra dos vídeos/formatos).
$deleteLocal = ($keepLocal) ? false : (($prune) ? true : null);
echo "Política de remoção local: " . ($keepLocal ? '--keep-local (mantém)' : ($prune ? '--prune (remove após upload)' : 'segue del_original_video')) . "\n";

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
            $willDelete = ($deleteLocal === true)
                || ($deleteLocal === null && isset($config['del_original_video']) && $config['del_original_video'] == '1');
            echo (count($files) > 0)
                ? count($files) . " arquivo(s) seriam enviados"
                : "sem arquivos de mídia local";
            echo ($willDelete ? " (local seria removido após upload)\n" : " (local mantido)\n");
        } else {
            echo "sem pasta de thumbs local\n";
        }
        continue;
    }

    echo "\n[" . $vid . "] Sincronizando mídia derivada...";
    if (upload_video_thumbs_gcs($vid, $server, null, false, $deleteLocal)) {
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
    if ($deleteLocal === true) {
        echo "Pastas locais de thumbs removidas após upload confirmado (--prune).\n";
    } elseif ($deleteLocal === false) {
        echo "Pastas locais de thumbs mantidas (--keep-local).\n";
    } else {
        echo "Remoção local seguiu a config del_original_video.";
        echo (isset($config['del_original_video']) && $config['del_original_video'] == '1')
            ? " (ativa — tmb/{VID} removidos após upload)\n"
            : " (inativa — local mantido)\n";
    }
}
?>