<?php
define('_VALID', 1);
define('_CLI', true);
define('_ENTER', true);

// Args: VID, base64_url, quality, base64_thumb, jobId (optional)
if ($argc < 4) {
    echo "Usage: php grabber_worker.php <VID> <base64_url> <quality> [base64_thumb] [jobId]\n";
    exit(1);
}

$vid = (int)$argv[1];
$url = base64_decode($argv[2]);
$url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$quality = isset($argv[3]) ? $argv[3] : 'best';
$thumbUrl = isset($argv[4]) ? base64_decode($argv[4]) : '';
$thumbUrl = html_entity_decode($thumbUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$jobId = isset($argv[5]) ? (int)$argv[5] : 0;

$basedir = dirname(dirname(__FILE__));
require_once $basedir . '/include/config.php';
require_once $basedir . '/include/function_video.php';
require_once $basedir . '/include/function_queue.php';
require_once $basedir . '/include/function_thumbs.php';
require_once $basedir . '/classes/image.class.php';
require_once $basedir . '/classes/grabbers/GrabberManager.php';
require_once $basedir . '/classes/grabbers/mass/MassGrabberManager.php';
require_once $basedir . '/include/function_global.php';

@set_time_limit(0);
@ini_set('max_execution_time', 0);
@ini_set('memory_limit', '512M');

// Helper: marcar job como COMPLETED ou FAILED
$jobMgr = ($jobId > 0) ? new JobManager() : null;

function worker_complete_job($vid, $jobId, $jobMgr) {
    if ($jobId > 0 && $jobMgr) {
        $jobMgr->complete($jobId, $vid);
    }
}

function worker_fail_job($jobId, $jobMgr, $code, $msg) {
    if ($jobId > 0 && $jobMgr) {
        $jobMgr->fail($jobId, $code, $msg);
    }
}

// Verifica se vídeo existe
$sql = "SELECT * FROM video WHERE VID = " . intval($vid) . " LIMIT 1";
$rs = $conn->execute($sql);
if ($conn->Affected_Rows() != 1) {
    echo "VID $vid não encontrado, abortando.\n";
    worker_fail_job($jobId, $jobMgr, 'VIDEO_NOT_FOUND', "VID $vid não encontrado");
    exit(1);
}
$videoRow = $rs->fields;
$category = intval($videoRow['channel']);
$uid = intval($videoRow['UID']);
$tags = $videoRow['keyword'];
$title = $videoRow['title'];

// Log helper (robusto a permissão)
$logFile = $config['LOG_DIR'] . '/' . $vid . '.grabber.log';
function grabber_log($msg) {
    global $logFile;
    $dir = dirname($logFile);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
    @chmod($logFile, 0666);
}

grabber_log("Iniciando download VID=$vid URL=$url quality=$quality thumb=$thumbUrl");

// Atualizar last_update para impedir timeout
$conn->execute("UPDATE video SET last_update = " . time() . " WHERE VID = " . intval($vid) . " LIMIT 1");

// Valida grabber
$grabber = GrabberManager::getGrabberForUrl($url);
if (!$grabber) {
    grabber_log("Grabber não encontrado para URL $url");
    $conn->execute("UPDATE video SET active = '0', last_update = " . time() . " WHERE VID = " . intval($vid) . " LIMIT 1");
    worker_fail_job($jobId, $jobMgr, 'GRABBER_NOT_FOUND', 'Grabber não encontrado para URL: ' . $url);
    exit(1);
}

// Cria tmp dir se necessário
$tmpDir = $config['BASE_DIR'] . '/tmp';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

$uniqId = mt_rand() . '_' . time();
$tmpVideoDst = $tmpDir . '/grab_' . $uniqId . '.mp4';

grabber_log("Baixando para $tmpVideoDst");
$dlResult = $grabber->downloadVideo($url, $tmpVideoDst, $quality);

// Atualizar last_update após download
$conn->execute("UPDATE video SET last_update = " . time() . " WHERE VID = " . intval($vid) . " LIMIT 1");

if (!$dlResult['status'] || !file_exists($tmpVideoDst) || filesize($tmpVideoDst) < 1024) {
    $err = isset($dlResult['error']) ? $dlResult['error'] : 'Falha ao baixar';
    grabber_log("Falha download: $err");
    $conn->execute("UPDATE video SET active = '0', last_update = " . time() . " WHERE VID = " . intval($vid) . " LIMIT 1");
    @unlink($tmpVideoDst);
    worker_fail_job($jobId, $jobMgr, 'DOWNLOAD_FAILED', substr($err, 0, 500));
    exit(1);
}

$space = filesize($tmpVideoDst);
grabber_log("Download OK size=$space");

// Atualiza espaço e garante source_url salvo
$conn->execute("UPDATE video SET space = '" . $space . "', source_url = " . $conn->qStr($url) . " WHERE VID = " . intval($vid) . " LIMIT 1");

// Move para pasta definitiva
$vdoname = $vid . '.mp4';
$vdoPath = $config['VDO_DIR'] . '/' . $vdoname;
if (!is_dir($config['VDO_DIR'])) @mkdir($config['VDO_DIR'], 0777, true);

// Se já existe arquivo antigo, remove
if (file_exists($vdoPath)) @unlink($vdoPath);
@rename($tmpVideoDst, $vdoPath);
if (!file_exists($vdoPath)) {
    grabber_log("Falha ao mover para $vdoPath");
    $conn->execute("UPDATE video SET active = '0', last_update = " . time() . " WHERE VID = " . intval($vid) . " LIMIT 1");
    worker_fail_job($jobId, $jobMgr, 'MOVE_FAILED', 'Falha ao mover arquivo para ' . $vdoPath);
    exit(1);
}

$vkey = substr(md5($vid), 11, 20);
$conn->execute("UPDATE video SET vkey = '" . $vkey . "', vdoname = " . $conn->qStr($vdoname) . " WHERE VID = " . intval($vid) . " LIMIT 1");
grabber_log("Arquivo movido para $vdoPath vkey=$vkey");

// Re-mux para faststart (moov no início) para streaming direto; a conversão
// (copy-only) reaproveita o arquivo. Leva segundos, não minutos.
$fsTmp = $vdoPath . '.faststart.mp4';
$fsCmd = sprintf('%s -i %s -c copy -movflags +faststart -y %s 2>&1',
    $config['ffmpeg'], escapeshellarg($vdoPath), escapeshellarg($fsTmp));
exec($fsCmd, $fsOut, $fsCode);
if ($fsCode === 0 && file_exists($fsTmp) && filesize($fsTmp) > 1024) {
    @rename($fsTmp, $vdoPath);
    grabber_log("Faststart remux OK: $vdoPath");
} else {
    @unlink($fsTmp);
    grabber_log("Faststart remux falhou (code=$fsCode) - segue com arquivo original");
}

// 3. Thumbnail (robusto: webp e falhas não impedem fila)
if (!empty($thumbUrl)) {
    try {
        $tmbDir = get_thumb_dir($vid);
        if (!is_dir($tmbDir)) @mkdir($tmbDir, 0777, true);
        $tmpThumbFile = $tmpDir . '/thumb_' . $uniqId . '.jpg';
        if ($grabber->downloadThumbnail($thumbUrl, $tmpThumbFile)) {
            $dstThumb = $tmbDir . '/1.jpg';
            // Se for webp, converte para jpg temporariamente
            $isWebp = false;
            if (function_exists('mime_content_type')) {
                $mime = @mime_content_type($tmpThumbFile);
                if ($mime === 'image/webp') $isWebp = true;
            }
            if ($isWebp && function_exists('imagecreatefromwebp')) {
                // Converte webp -> jpg para VImageConv
                $im = @imagecreatefromwebp($tmpThumbFile);
                if ($im) {
                    @imagejpeg($im, $tmpThumbFile, 90);
                    @imagedestroy($im);
                }
            }
            $image = new VImageConv();
            $width = (int)$config['img_max_width'];
            $height = (int)$config['img_max_height'];
            if (copy($tmpThumbFile, $dstThumb)) {
                list($src_w, $src_h) = getimagesize($dstThumb);
                if ($src_w && $src_h) {
                    $aspect = $width / $height;
                    $src_aspect = $src_w / $src_h;
                    if ($aspect < $src_aspect) {
                        $tmp_h = $height;
                        $tmp_w = floor($tmp_h * $src_aspect);
                        $image->process($dstThumb, $dstThumb, 'EXACT', $tmp_w, $tmp_h);
                        $image->resize(true, true);
                        $x = floor(($tmp_w - $width) / 2);
                        $y = 0;
                    } else {
                        $tmp_w = $width;
                        $tmp_h = floor($tmp_w / $src_aspect);
                        $image->process($dstThumb, $dstThumb, 'EXACT', $tmp_w, $tmp_h);
                        $image->resize(true, true);
                        $x = 0;
                        $y = floor(($tmp_h - $height) / 2);
                    }
                    $image->process($dstThumb, $dstThumb, 'EXACT', $width, $height);
                    $image->crop($x, $y, $width, $height, true);
                    grabber_log("Thumbnail processada $dstThumb");
                }
            }
            @unlink($tmpThumbFile);
        } else {
            grabber_log("Falha ao baixar thumbnail $thumbUrl");
        }
    } catch (Exception $e) {
        grabber_log("Erro thumbnail (não bloqueante): " . $e->getMessage());
        @unlink($tmpThumbFile ?? '');
    } catch (Throwable $e) {
        grabber_log("Erro thumbnail throwable: " . $e->getMessage());
        @unlink($tmpThumbFile ?? '');
    }
}

// 4. Tags e contadores (só agora, após download OK)
if (!empty($tags)) {
    add_tags($tags);
}
$conn->execute("UPDATE channel SET total_videos = total_videos+1 WHERE CHID = " . intval($category) . " LIMIT 1");
$conn->execute("UPDATE signup SET total_videos = total_videos+1 WHERE UID = " . intval($uid) . " LIMIT 1");
grabber_log("Tags e contadores atualizados");

// Marca como aguardando na fila (3 = queued) antes de enfileirar
$conn->execute("UPDATE video SET active = '3' WHERE VID = " . intval($vid) . " LIMIT 1");
grabber_log("Status atualizado para 3 (Na Fila) VID=$vid");

// 5. Enfileiramento / Conversão
$cgi = (strpos(php_sapi_name(), 'cgi') !== false) ? 'env -i ' : '';
$cmd = $cgi . $config['phppath'] . " " . $config['BASE_DIR'] . "/scripts/convert_videos.php" . " " . $vdoname . " " . $vid . " " . $vdoPath;

if (isset($config['conversion_q']) && $config['conversion_q'] == '1') {
    // Usa função existente que já faz check_q e conversão em background
    insert_into_q_fp($vid, $vdoname, $vdoPath);
    grabber_log("Adicionado à fila conversion_queue_fp VID=$vid");
} else {
    log_conversion($config['LOG_DIR'] . '/' . $vid . '.log', $cmd);
    $lg = $config['LOG_DIR'] . '/' . $vid . '.log2';
    @unlink($lg);  // remove stale log: ">" over a file owned by another user fails silently and the conversion never starts
    $PID = shell_exec("$cmd > " . escapeshellarg($lg) . " 2>&1 & echo $!");
    grabber_log("Conversão iniciada em background PID=$PID CMD=$cmd");
}

grabber_log("Worker concluído VID=$vid");

// Marcar job como COMPLETED no final
worker_complete_job($vid, $jobId, $jobMgr);
grabber_log("Job #$jobId marcado como COMPLETED");

exit(0);
