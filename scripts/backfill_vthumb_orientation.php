<?php
define('_VALID', 1);
define('_ENTER', true);
define('_CLI', true);

$basedir = dirname(dirname(__FILE__));
require $basedir . '/include/config.php';
require_once $basedir . '/include/function_thumbs.php';
require_once $basedir . '/include/function_server.php';
require_once $basedir . '/include/function_video.php';

$args    = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
$dryRun  = in_array('--dry-run', $args, true);
$onlyVid = null;
$limit   = 0;
foreach ($args as $arg) {
    if (strpos($arg, '--vid=') === 0) {
        $onlyVid = (int) substr($arg, 6);
    }
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int) substr($arg, 8);
    }
}

$sql = "SELECT VID, server, vdoname, formats FROM video WHERE vthumbs = '1' AND height_sd > width_sd";
if ($onlyVid) {
    $sql .= " AND VID = " . $onlyVid;
}
$sql .= " ORDER BY VID ASC";
if ($limit > 0) {
    $sql .= " LIMIT " . $limit;
}
$rs     = $conn->execute($sql);
$videos = ($conn->Affected_Rows() > 0) ? $rs->getrows() : array();

echo "Videos verticais com preview (vthumbs=1): " . count($videos) . "\n";

$done   = 0;
$skip   = 0;
$fail   = 0;

foreach ($videos as $video) {
    $vid      = intval($video['VID']);
    $src      = '';

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

    if ($src === '' && !empty($video['vdoname'])) {
        $candidate = $config['VDO_DIR'] . '/' . $video['vdoname'];
        if (file_exists($candidate) && is_file($candidate) && filesize($candidate) > 100) {
            $src = $candidate;
        }
    }

    if ($src === '') {
        echo "[" . $vid . "] sem arquivo local para regenerar (H264/VDO ausentes) - pulado\n";
        ++$skip;
        continue;
    }

    if ($dryRun) {
        echo "[" . $vid . "] regeneraria o clipe a partir de " . basename($src) . "\n";
        ++$done;
        continue;
    }

    echo "[" . $vid . "] Regenerando clipe a partir de " . basename($src) . "...";
    if (!extract_video_vthumbs($src, $vid, false)) {
        echo " [FALHA]\n";
        ++$fail;
        continue;
    }

    if (!empty($video['server'])) {
        $gcsServer = get_server_by_video_url($video['server']);
        if ($gcsServer && isset($gcsServer['server_type']) && $gcsServer['server_type'] === 'gcs') {
            if (upload_video_thumbs_gcs($vid, $gcsServer, 'video.mp4') && upload_video_thumbs_gcs($vid, $gcsServer, 'video.webm')) {
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
    ++$done;
}

echo "\n===== Resumo =====\n";
if ($dryRun) {
    echo "A regenerar: " . $done . " | Sem arquivo local: " . $skip . "\n";
} else {
    echo "Processados: " . $done . " | Pulados: " . $skip . " | Falhas: " . $fail . "\n";
}
?>