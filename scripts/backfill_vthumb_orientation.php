<?php
/**
 * Backfill dos miniclips de hover (video.mp4/video.webm) dos vídeos.
 *
 * Dois modos:
 *
 *   (padrão)   Vídeos VERTICAIS com vthumbs=1 — regrava o clipe no formato
 *              retrato novo (composição blurred-backdrop), corrigindo os que
 *              foram gerados antes da melhoria de orientação.
 *
 *   --missing  Vídeos com vthumbs=0 (clipe ausente/quebrado) — regenera a
 *              partir da melhor fonte disponível: h264 local → vídeo original
 *              local → download do bucket GCS (via V4 Signed URL) quando o
 *              local já foi limpo (del_original_video=1). Cobre o bug do
 *              overlay fps do FFmpeg 9, que zerava o vthumbs em silêncio
 *              nos vídeos novos verticais.
 *
 * Uso:
 *   php scripts/backfill_vthumb_orientation.php                        # verticais vthumbs=1
 *   php scripts/backfill_vthumb_orientation.php --missing              # vthumbs=0 (quebrados)
 *   php scripts/backfill_vthumb_orientation.php --missing --dry-run    # apenas lista
 *   php scripts/backfill_vthumb_orientation.php --missing --vid=83     # um vídeo só
 *   php scripts/backfill_vthumb_orientation.php --missing --limit=5    # primeiros N
 */

define('_VALID', 1);
define('_ENTER', true);
define('_CLI', true);

$basedir = dirname(dirname(__FILE__));
require $basedir . '/include/config.php';
require_once $basedir . '/include/function_thumbs.php';
require_once $basedir . '/include/function_server.php';
require_once $basedir . '/include/function_video.php';

/**
 * Baixa o melhor formato (maior altura) de um vídeo do bucket GCS para o
 * TMP_DIR, via V4 Signed URL. Retorna o caminho local, ou '' em caso de falha.
 * Usado pelo modo --missing quando a fonte local já foi removida.
 */
function download_gcs_source($vid, $formats, $server)
{
    global $config;

    if (empty($formats) || empty($server)) {
        return '';
    }

    $gcs = gcs_get_client($server);
    if (!$gcs) {
        return '';
    }

    $best  = '';
    $bestH = 0;
    foreach (explode(',', $formats) as $fmt) {
        $parts = explode('.', trim($fmt));
        if (count($parts) < 3) {
            continue;
        }
        $h = (int) $parts[0];
        if ($h > $bestH) {
            $bestH = $h;
            $best  = $parts[1] . '.' . $parts[2]; // ex.: '720p.mp4'
        }
    }
    if ($best === '') {
        return '';
    }

    $url = $gcs->getSignedUrl('h264/' . intval($vid) . '/' . $best, 3600);
    if (!$url) {
        return '';
    }

    $target = $config['TMP_DIR'] . '/vthumb_' . intval($vid) . '_' . $best;
    $data   = @file_get_contents($url);
    if ($data === false || strlen($data) < 1000) {
        @unlink($target);
        return '';
    }
    file_put_contents($target, $data);
    return $target;
}

$args     = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
$dryRun   = in_array('--dry-run', $args, true);
$missing  = in_array('--missing', $args, true);
$onlyVid  = null;
$limit    = 0;
foreach ($args as $arg) {
    if (strpos($arg, '--vid=') === 0) {
        $onlyVid = (int) substr($arg, 6);
    }
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int) substr($arg, 8);
    }
}

if ($missing) {
    $sql = "SELECT VID, server, vdoname, formats FROM video WHERE vthumbs = '0'";
    $label = 'Videos sem miniclip (vthumbs=0)';
} else {
    $sql = "SELECT VID, server, vdoname, formats FROM video WHERE vthumbs = '1' AND height_sd > width_sd";
    $label = 'Videos verticais com preview (vthumbs=1)';
}

if ($onlyVid) {
    $sql .= " AND VID = " . $onlyVid;
}
$sql .= " ORDER BY VID ASC";
if ($limit > 0) {
    $sql .= " LIMIT " . $limit;
}
$rs     = $conn->execute($sql);
$videos = ($conn->Affected_Rows() > 0) ? $rs->getrows() : array();

echo $label . ": " . count($videos) . "\n";

$done   = 0;
$skip   = 0;
$fail   = 0;

/**
 * Garante que a pasta tmb/{VID} existe e é gravável pelo usuário atual.
 *
 * Pastas criadas pelo worker (daemon) podem ficar sem permissão de escrita
 * para o usuário que roda o backfill — o que faz o ffmpeg falhar com
 * "Permission denied" ao gravar video_copy.*. Como as thumbs já estão no
 * bucket (vídeos GCS), a pasta local vazia/inacessível é recriada.
 */
function ensure_writable_thumb_dir($vid)
{
    $dir = get_thumb_dir($vid);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
        @chmod($dir, 0777);
        return $dir;
    }
    if (is_writable($dir)) {
        return $dir;
    }

    // Pasta criada por outro usuário (worker roda como daemon) sem permissão
    // de escrita. Unlink/rmdir exigem escrita NA própria pasta — que não temos.
    // Mas o pai (tmb/) é nosso: copiamos o conteúdo (é legível), renomeamos a
    // pasta para o lado e recriamos gravável, devolvendo os arquivos.
    @chmod($dir, 0777);
    if (!is_writable($dir)) {
        $backup = $dir . '_unwritable_' . getmypid();
        $kept   = array();
        foreach (glob($dir . '/*') ?: array() as $f) {
            if (is_file($f) && is_readable($f)) {
                $kept[basename($f)] = $f;
            }
        }
        if (@rename($dir, $backup)) {
            @mkdir($dir, 0777, true);
            @chmod($dir, 0777);
            foreach ($kept as $name => $src) {
                @copy($src, $dir . '/' . $name);
            }
            // Melhor esforço: limpa a pasta antiga (pode não dar por permissão).
            foreach (glob($backup . '/*') ?: array() as $f) {
                @unlink($f);
            }
            @rmdir($backup);
        }
    }
    return $dir;
}

foreach ($videos as $video) {
    $vid         = intval($video['VID']);
    $src         = '';
    $downloaded  = false;

    // 1) Melhor formato h264 local
    if (!empty($video['formats'])) {
        $formats = array_filter(array_map('trim', explode(',', $video['formats'])));
        $best    = 0;
        foreach ($formats as $format) {
            $parts = explode('.', $format);
            if (count($parts) < 3) {
                continue;
            }
            $h  = (int) $parts[0];
            $candidate = $config['H264_DIR'] . '/' . $vid . '_' . $parts[1] . '.' . $parts[2];
            if (file_exists($candidate) && is_file($candidate) && filesize($candidate) > 100 && $h > $best) {
                $best = $h;
                $src  = $candidate;
            }
        }
    }

    // 2) Vídeo original local
    if ($src === '' && !empty($video['vdoname'])) {
        $candidate = $config['VDO_DIR'] . '/' . $video['vdoname'];
        if (file_exists($candidate) && is_file($candidate) && filesize($candidate) > 100) {
            $src = $candidate;
        }
    }

    // 3) Fonte local já limpa (del_original_video): baixa do bucket (modo --missing)
    if ($src === '' && $missing) {
        $gcsServer = get_server_by_video_url($video['server']);
        if ($gcsServer && isset($gcsServer['server_type']) && $gcsServer['server_type'] === 'gcs') {
            $src        = download_gcs_source($vid, $video['formats'], $gcsServer);
            $downloaded = ($src !== '');
        }
    }

    if ($src === '') {
        echo "[" . $vid . "] sem arquivo local nem fonte no bucket para regenerar - pulado\n";
        ++$skip;
        continue;
    }

    if ($dryRun) {
        echo "[" . $vid . "] regeneraria o clipe a partir de " . basename($src) . ($downloaded ? " (baixado do bucket)" : "") . "\n";
        ++$done;
        continue;
    }

    ensure_writable_thumb_dir($vid);

    echo "[" . $vid . "] Regenerando clipe a partir de " . basename($src) . ($downloaded ? " (baixado do bucket)" : "") . "...";
    $ok = extract_video_vthumbs($src, $vid, false);

    // Fonte local corrompida (ex.: conversão interrompida): tenta o bucket
    // antes de desistir (modo --missing, vídeo GCS).
    if (!$ok && !$downloaded && $missing) {
        $gcsServer = get_server_by_video_url($video['server']);
        if ($gcsServer && isset($gcsServer['server_type']) && $gcsServer['server_type'] === 'gcs') {
            echo "\n[" . $vid . "] fonte local falhou - tentando fonte do bucket...";
            $src2 = download_gcs_source($vid, $video['formats'], $gcsServer);
            if ($src2 !== '') {
                $ok = extract_video_vthumbs($src2, $vid, false);
                @unlink($src2);
            }
        }
    }

    if (!$ok) {
        echo " [FALHA]\n";
        ++$fail;
        if ($downloaded) {
            @unlink($src);
        }
        continue;
    }

    if (!empty($video['server'])) {
        $gcsServer = get_server_by_video_url($video['server']);
        if ($gcsServer && isset($gcsServer['server_type']) && $gcsServer['server_type'] === 'gcs') {
            if (upload_video_thumbs_gcs($vid, $gcsServer, 'video.mp4') && upload_video_thumbs_gcs($vid, $gcsServer, 'video.webm')) {
                // Limpa os resíduos do bug (video_copy.* subidos sem renomear)
                $gcs = gcs_get_client($gcsServer);
                if ($gcs) {
                    foreach (array('video_copy.mp4', 'video_copy.webm') as $stale) {
                        if ($gcs->deleteObject('thumbs/' . $vid . '/' . $stale)) {
                            echo " [OK] (+GCS, stale removido)";
                        }
                    }
                }
                echo " [OK] (+GCS)\n";
            } else {
                echo " [OK] (sync GCS falhou)\n";
            }
        } else {
            echo " [OK]\n";
        }
    } else {
        echo " [OK]\n";
    }
    if ($downloaded) {
        @unlink($src);
    }
    ++$done;
}

echo "\n===== Resumo =====\n";
if ($dryRun) {
    echo "A regenerar: " . $done . " | Sem fonte: " . $skip . "\n";
} else {
    echo "Processados: " . $done . " | Pulados: " . $skip . " | Falhas: " . $fail . "\n";
}
?>