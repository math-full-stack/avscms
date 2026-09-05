<?php
/**
 * Verificação/limpeza da mídia local restante em media/videos.
 *
 * Varre os três tipos de mídia produzidos pelos jobs de conversão/upload:
 *   - thumbs      media/videos/tmb[/tmbN]/.../{VID}/  (thumbs, sprite, miniclips)
 *   - formatos    media/videos/h264/{VID}_{label}.* (H.264 MP4 derivados)
 *   - original    media/videos/vid/{VID}.*          (fonte enviada pelo grabber/upload)
 *
 * Regra central (fail-safe): um arquivo local SÓ é removido quando o conteúdo
 * equivalente já está confirmado no bucket GCS do vídeo:
 *   - pasta tmb/{VID}        -> quando existem objetos thumbs/{VID}/ no bucket
 *   - h264/{VID}_{label}.*   -> quando existe ao menos um objeto h264/{VID}/*
 *   - vid/{VID}.* (original) -> quando existe ao menos um formato no bucket
 * Arquivos de vídeos locais/FTP (sem servidor GCS) nunca são tocados, e mídia
 * órfã (VID inexistente no banco) só é removida com --delete-orphans.
 *
 * Uso:
 *   php scripts/media_cleanup.php                 # dry-run (apenas relata)
 *   php scripts/media_cleanup.php --dry-run       # idem (explícito)
 *   php scripts/media_cleanup.php --delete        # remove o que estiver confirmado no bucket
 *   php scripts/media_cleanup.php --vid 123       # restringe a um vídeo
 *   php scripts/media_cleanup.php --delete-orphans# remove mídia sem registro no banco
 *
 * Recomendado: rodar primeiro `php scripts/gcs_sync_thumbs.php --prune --dry-run`
 * (garante thumbs/sprite no bucket) e então este script para o que sobrar.
 */

define('_VALID', 1);
define('_ENTER', true);
define('_CLI', true);

$basedir = dirname(dirname(__FILE__));
require $basedir . '/include/config.php';
require_once $basedir . '/include/function_thumbs.php';
require_once $basedir . '/include/function_server.php';

$args         = isset($_SERVER['argv']) ? $_SERVER['argv'] : array();
$dryRun       = !in_array('--delete', $args, true);
$deleteOrphans = in_array('--delete-orphans', $args, true);
$onlyVid      = null;
foreach ($args as $arg) {
    if (strpos($arg, '--vid=') === 0) {
        $onlyVid = (int) substr($arg, 6);
    }
}

echo "Modo: " . ($dryRun ? "dry-run (nada será removido)" : "execução (--delete)") . "\n";

// 1) Servidores GCS ativos (URL normalizada -> linha do servidor)
$gcsServers = array();
$sql = "SELECT * FROM servers WHERE server_type = 'gcs' AND status = '1' ORDER BY server_id ASC";
$rs  = $conn->execute($sql);
if ($conn->Affected_Rows() > 0) {
    foreach ($rs->getrows() as $server) {
        $gcsServers[rtrim($server['video_url'], '/')] = $server;
    }
}
if (empty($gcsServers)) {
    echo "Nenhum servidor GCS ativo encontrado. Nada a verificar (modo local/FTP).\n";
    exit(0);
}
foreach ($gcsServers as $url => $server) {
    echo "Servidor GCS: " . $url . " (bucket: " . $server['gcs_bucket'] . ")\n";
}

// 2) Inventário local: VID -> tipos de mídia presentes
$found = array(); // $found[$vid]['thumbs'] bool | ['h264'] array de arquivos | ['vid'] array de arquivos
$thumbsRoot = $config['BASE_DIR'] . '/media/videos';
$h264Dir    = isset($config['H264_DIR']) ? $config['H264_DIR'] : $thumbsRoot . '/h264';
$vidDir     = isset($config['VDO_DIR']) ? $config['VDO_DIR'] : $thumbsRoot . '/vid';

// 2a) Pastas de thumbs (tmb, tmb1, tmb2, ...)
foreach (glob($thumbsRoot . '/tmb*', GLOB_ONLYDIR) as $volume) {
    if (preg_match('#/tmb\d*$#', $volume) !== 1) {
        continue;
    }
    foreach (glob($volume . '/*', GLOB_ONLYDIR) as $vidFolder) {
        $vid = (int) basename($vidFolder);
        if ($vid <= 0) {
            continue;
        }
        $found[$vid]['thumbs'] = $vidFolder;
    }
}

// 2b) Formatos H.264 (arquivos planos {VID}_{label}.{ext})
if (is_dir($h264Dir)) {
    foreach (glob($h264Dir . '/*') as $file) {
        if (!is_file($file)) {
            continue;
        }
        $name = basename($file);
        if (preg_match('/^(\d+)_/', $name, $m)) {
            $found[(int) $m[1]]['h264'][] = $file;
        }
    }
}

// 2c) Originais (arquivos planos {VID}.{ext} em vid/)
if (is_dir($vidDir)) {
    foreach (glob($vidDir . '/*') as $file) {
        if (!is_file($file)) {
            continue;
        }
        $name = basename($file);
        if (preg_match('/^(\d+)\./', $name, $m)) {
            $found[(int) $m[1]]['vid'][] = $file;
        }
    }
}

if (empty($found)) {
    echo "\nNenhuma mídia local restante em media/videos (thumbs/h264/vid). Limpo!\n";
    exit(0);
}

// 3) Para cada vídeo com mídia local, decide o que pode ser removido
ksort($found);
$removed = array('thumbs' => 0, 'h264' => 0, 'vid' => 0);
$kept    = array('thumbs' => 0, 'h264' => 0, 'vid' => 0);

foreach ($found as $vid => $assets) {
    if ($onlyVid && $vid !== $onlyVid) {
        continue;
    }

    // Registro do vídeo
    $sql = "SELECT VID, server, active, vdoname FROM video WHERE VID = " . $vid . " LIMIT 1";
    $rs  = $conn->execute($sql);
    if ($conn->Affected_Rows() != 1) {
        echo "\n[" . $vid . "] ÓRFÃO (sem registro na tabela video): ";
        foreach ($assets as $type => $paths) {
            $list = is_array($paths) ? $paths : array($paths);
            echo $type . '(' . count($list) . ') ';
        }
        echo "\n";
        if ($deleteOrphans && !$dryRun) {
            foreach ($assets as $type => $paths) {
                if ($type === 'thumbs') {
                    delete_local_video_thumbs($vid);
                } else {
                    foreach ($paths as $path) {
                        @unlink($path);
                    }
                }
            }
            echo "   -> removido (--delete-orphans)\n";
        } elseif ($deleteOrphans) {
            echo "   -> seria removido com --delete (--delete-orphans ativo)\n";
        } else {
            echo "   -> mantido (use --delete-orphans para remover mídia sem vídeo)\n";
        }
        continue;
    }
    $row = $rs->fields;

    // Vídeo em servidor GCS? (normaliza a URL gravada em video.server)
    $server = isset($gcsServers[rtrim($row['server'], '/')]) ? $gcsServers[rtrim($row['server'], '/')] : null;
    if (!$server) {
        echo "\n[" . $vid . "] mantido: vídeo " . ($row['server'] === '' ? 'sem servidor (local/FTP)' : 'em servidor não-GCS (' . $row['server'] . ')') . "\n";
        foreach ($assets as $type => $paths) {
            $kept[$type] += (is_array($paths) ? count($paths) : 1);
        }
        continue;
    }

    // Confirma no bucket o que existe por tipo de mídia
    $hasFormats = gcs_video_has_formats($vid, $server);
    $hasThumbs  = false;
    $gcs        = gcs_get_client($server);
    if ($gcs) {
        $list = $gcs->listObjects('thumbs/' . $vid . '/');
        $hasThumbs = (is_array($list) && count($list) > 0);
    }

    echo "\n[" . $vid . "] GCS (" . $row['server'] . ") active=" . $row['active']
        . " | bucket h264=" . ($hasFormats ? 'OK' : 'ausente') . " thumbs=" . ($hasThumbs ? 'OK' : 'ausente') . "\n";

    $assets['thumbs'] = isset($assets['thumbs']) ? $assets['thumbs'] : null;
    $assets['h264']   = isset($assets['h264']) ? $assets['h264'] : array();
    $assets['vid']    = isset($assets['vid']) ? $assets['vid'] : array();

    // thumbs locais
    if ($assets['thumbs']) {
        if ($hasThumbs) {
            $verb = ($dryRun) ? "removeria" : "removida";
            echo "   tmb/" . $vid . ": $verb (confirma thumbs/" . $vid . "/ no bucket)\n";
            if (!$dryRun) {
                if (delete_local_video_thumbs($vid)) {
                    ++$removed['thumbs'];
                } else {
                    echo "   -> falha ao remover tmb/" . $vid . "\n";
                    ++$kept['thumbs'];
                }
            } else {
                ++$removed['thumbs'];
            }
        } else {
            echo "   tmb/" . $vid . ": mantida (bucket sem thumbs — rode gcs_sync_thumbs.php primeiro)\n";
            ++$kept['thumbs'];
        }
    }

    // formatos H.264 locais
    foreach ($assets['h264'] as $file) {
        if ($hasFormats) {
            echo "   " . basename($file) . ": " . ($dryRun ? "removeria" : "removido") . " (formato confirmado no bucket)\n";
            if (!$dryRun) {
                @unlink($file);
            }
            ++$removed['h264'];
        } else {
            echo "   " . basename($file) . ": mantido (bucket sem formatos h264/" . $vid . "/)\n";
            ++$kept['h264'];
        }
    }

    // original local
    foreach ($assets['vid'] as $file) {
        if ($hasFormats) {
            echo "   " . basename($file) . " (original): " . ($dryRun ? "removeria" : "removido") . " (formatos no bucket — mesma regra do pipeline)\n";
            if (!$dryRun) {
                @unlink($file);
            }
            ++$removed['vid'];
        } else {
            echo "   " . basename($file) . " (original): mantido (bucket sem formatos — única cópia para reprocessar)\n";
            ++$kept['vid'];
        }
    }
}

echo "\n===== Resumo =====\n";
echo "Removeria/Removidos:  thumbs=" . $removed['thumbs'] . " h264=" . $removed['h264'] . " vid=" . $removed['vid'] . "\n";
echo "Mantidos (sem cópia no bucket / não-GCS): thumbs=" . $kept['thumbs'] . " h264=" . $kept['h264'] . " vid=" . $kept['vid'] . "\n";
if ($dryRun) {
    echo "Dry-run: nada foi alterado. Rode com --delete para executar.\n";
}
?>
