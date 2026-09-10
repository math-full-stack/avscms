<?php
/**
 * Backfill: regenera e re-envia a mídia derivada (thumbs, sprite, miniclips)
 * de vídeos GCS cujo bucket NÃO tem o conjunto essencial de thumbs.
 *
 * Cenário: um upload parcial (ou extração incompleta) deixou vídeos com
 * default.jpg / frames ausentes no bucket (404 no player e na grade) depois
 * que a pasta local tmb/{VID} já foi removida (media_cleanup antigo apagava o
 * local com qualquer objeto no bucket). A fonte h264/{VID}/* ainda existe no
 * bucket: este script baixa o melhor formato, extrai thumbs/vthumbs/sprite
 * localmente, envia tudo para o bucket e limpa os temporários.
 *
 * Uso (rodar no PC conversor — exige ffmpeg/ffprobe + chave GCS no .env):
 *   php scripts/gcs_backfill_thumbs.php               # dry-run: lista afetados
 *   php scripts/gcs_backfill_thumbs.php --vid 115     # dry-run de um vídeo
 *   php scripts/gcs_backfill_thumbs.php --run         # executa de verdade
 *   php scripts/gcs_backfill_thumbs.php --vid 115 --run
 *
 * Exit code: 0 = ok, 1 = falhas ou ambiente inválido.
 */

define('_VALID', 1);
define('_ENTER', true);
define('_CLI', true);
define('_CONSOLE', true);

$basedir = dirname(dirname(__FILE__));
require $basedir . '/include/config.php';
require_once $basedir . '/include/function_thumbs.php';
require_once $basedir . '/include/function_server.php';
require_once $basedir . '/include/function_video.php';

// FFmpeg roda apenas no host conversor (o PC); a VM (web) não tem o pipeline.
if (!isset($config['worker_role']) || $config['worker_role'] !== 'converter') {
    fwrite(STDERR, "worker_role != 'converter' — este script roda apenas no PC conversor (ffmpeg local).\n");
    exit(1);
}

$args    = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
$run     = in_array('--run', $args, true);
$onlyVid = null;
foreach ($args as $arg) {
    if (strpos($arg, '--vid=') === 0) {
        $onlyVid = (int) substr($arg, 6);
    }
}

echo "Modo: " . ($run ? "EXECUÇÃO (--run) — vai baixar/extrair/enviar para o bucket" : "dry-run (apenas relata)") . "\n";

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

// 2) Vídeos vinculados ao servidor GCS
$sql = "SELECT VID, server, formats, thumbs FROM video WHERE server = " . $conn->qStr($serverUrl);
if ($onlyVid) {
    $sql .= " AND VID = " . $onlyVid;
}
$sql  .= " ORDER BY VID ASC";
$rs    = $conn->execute($sql);
$videos = ($conn->Affected_Rows() > 0) ? $rs->getrows() : array();
if (empty($videos)) {
    echo "Nenhum vídeo vinculado a este servidor GCS.\n";
    exit(0);
}

$gcs = gcs_get_client($server);
if (!$gcs) {
    echo "Não foi possível resolver o cliente GCS (chave/bucket).\n";
    exit(1);
}

$scanned  = 0;
$affected = array();
foreach ($videos as $video) {
    $vid = intval($video['VID']);
    ++$scanned;

    $complete = gcs_thumbs_complete_on_bucket($vid, $server, intval($video['thumbs']));
    if ($complete) {
        continue;
    }

    // Detalhe do que falta
    $missing = array();
    $list = $gcs->listObjects('thumbs/' . $vid . '/');
    $have = array();
    if (is_array($list)) {
        foreach ($list as $n) {
            if (substr($n, -1) !== '/') {
                $have[basename($n)] = true;
            }
        }
    }
    if (!isset($have['default.jpg'])) {
        $missing[] = 'default.jpg';
    }
    $expected = intval($video['thumbs']);
    if ($expected <= 0) {
        $expected = 20;
    }
    $expected = min($expected, 20);
    $nFrames = 0;
    for ($i = 1; $i <= $expected; $i++) {
        if (!isset($have[$i . '.jpg'])) {
            $missing[] = $i . '.jpg';
        } else {
            $nFrames++;
        }
    }
    $affected[] = array('vid' => $vid, 'thumbs' => $video['thumbs'], 'formats' => $video['formats'], 'missing' => $missing);
    echo "[" . $vid . "] INCOMPLETO — faltam " . count($missing) . " arquivo(s): "
        . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? ', ...' : '') . "\n";
}

if (empty($affected)) {
    echo "\nTodos os vídeos GCS com o conjunto essencial de thumbs no bucket. Nada a fazer.\n";
    exit(0);
}

echo "\n" . count($affected) . " de " . $scanned . " vídeo(s) GCS precisam de backfill de thumbs.\n";

if (!$run) {
    echo "Dry-run: nada foi alterado. Rode com --run para baixar/extrair/enviar.\n";
    exit(0);
}

// 3) Execução
$ok   = 0;
$fail = 0;
foreach ($affected as $a) {
    $vid = $a['vid'];
    echo "\n===== [" . $vid . "] Regenerando mídia derivada =====\n";

    $target = $config['TMP_DIR'] . '/gcs_backfill_' . $vid . '.mp4';
    @unlink($target);

    echo "Baixando melhor formato h264 do bucket...\n";
    $src = gcs_download_h264_source($vid, $target);
    if ($src === false || !file_exists($src) || filesize($src) <= 0) {
        echo "  [FALHA] download do h264/{$vid}/ falhou — pulando.\n";
        ++$fail;
        continue;
    }
    echo "  fonte: " . $src . " (" . round(filesize($src) / 1048576, 1) . " MB)\n";

    $thumbDir = get_thumb_dir($vid);
    @mkdir($thumbDir, 0777, true);
    @chmod($thumbDir, 0777);

    echo "Extraindo frames (extract_video_thumbs)...\n";
    extract_video_thumbs($src, $vid, 'all', $config['thumbnail_remove_bb'], $config['thumbnail_keep_ar']);

    if ($config['vthumbs'] == '1') {
        echo "Gerando miniclips de hover (extract_video_vthumbs)...\n";
        extract_video_vthumbs($src, $vid, false);
    }

    echo "Garantindo sprite do timeline preview...\n";
    ensure_video_sprite_local($vid);

    // Envia a pasta inteira e remove o local após upload confirmado.
    if (upload_video_thumbs_gcs($vid, $server, null, false, true)) {
        echo "  [OK] mídia derivada enviada ao bucket (tmb/{$vid} local removido).\n";
        ++$ok;
    } else {
        echo "  [FALHA] upload/sync das thumbs — local mantido para re-tentar.\n";
        ++$fail;
    }

    @unlink($target);
}

echo "\n===== Resumo =====\n";
echo "Backfill: OK: " . $ok . " | Falhas: " . $fail . "\n";
if ($fail > 0) {
    echo "Vídeos com falha mantiveram a pasta local tmb/{VID} como fallback (re-tente depois).\n";
    exit(1);
}
exit(0);