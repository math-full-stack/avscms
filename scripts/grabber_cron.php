<?php
/**
 * Mass Video Grabber - Unified Cron Script
 *
 * Usage: * * * * * php /path/to/avs/scripts/grabber_cron.php
 *
 * This single CRON entry handles:
 *   1. Scheduler (discover + auto-queue)
 *   2. Queue Worker (process grab jobs)
 *   3. Cleanup (old logs, runs, jobs)
 *   4. Health checks (stale job recovery)
 */

define('_VALID', 1);
define('_CLI', true);
define('_ENTER', true);

$basedir = dirname(dirname(__FILE__));
require_once $basedir . '/include/config.php';
require_once $basedir . '/include/function_video.php';
require_once $basedir . '/include/function_queue.php';
require_once $basedir . '/include/function_thumbs.php';
require_once $basedir . '/include/function_global.php';
require_once $basedir . '/classes/image.class.php';
require_once $basedir . '/classes/grabbers/GrabberManager.php';
require_once $basedir . '/classes/grabbers/mass/MassGrabberManager.php';

@set_time_limit(0);
@ini_set('max_execution_time', 0);
@ini_set('memory_limit', '512M');

$startTime = time();
$pid = getmypid();

// Single-instance guard: concurrent runs (web "Process Now" clicks, cron
// overlap) must never claim/reset the same jobs at the same time.
$lockFile = $config['LOG_DIR'] . '/grabber_cron.lock';
$lockH = @fopen($lockFile, 'c');
if ($lockH && !flock($lockH, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Another grabber_cron instance is already running - skipping.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Mass Grabber Cron started (PID: $pid)\n";

// ============================================================
// 1. RUN SCHEDULER (discover new videos, auto-queue)
// ============================================================
echo "[" . date('Y-m-d H:i:s') . "] Running scheduler...\n";

$scheduler = new Scheduler();
$schedulerResult = $scheduler->run();

echo "[" . date('Y-m-d H:i:s') . "] Scheduler: " . $schedulerResult['sources_scanned'] . " sources scanned, " . $schedulerResult['stale_reset'] . " stale jobs reset\n";

// ============================================================
// 2. PROCESS GRAB QUEUE (run workers)
// ============================================================
echo "[" . date('Y-m-d H:i:s') . "] Processing grab queue...\n";

$jobMgr = new JobManager();
$logger = new Logger();

// Max concurrent grabs (independent from FFmpeg conversion queue)
$maxConcurrent = 3;

// Count active grabs
$activeCount = $jobMgr->activeCount();
$available = $maxConcurrent - $activeCount;

if ($available > 0) {
    // Process jobs one at a time
    for ($i = 0; $i < $available; $i++) {
        $job = $jobMgr->claimNext($pid);
        if (!$job) break;

        $jobId = intval($job['id']);
        $sourceId = intval($job['source_id']);
        $discVideoId = intval($job['discovered_video_id']);

        echo "[" . date('Y-m-d H:i:s') . "] Processing job #$jobId (disc_video=$discVideoId)\n";

        $logger->info(0, $jobId, $sourceId, 'JOB_STARTED', "Job #$jobId started (attempt " . $job['attempts'] . ")");

        // Get source settings
        $sourceMgr = new SourceManager();
        $source = $sourceMgr->getById($sourceId);
        $quality = $source ? $source['quality'] : 'best';

        // Get the video URL from discovered data
        $sourceUrl = isset($job['source_url']) ? $job['source_url'] : '';
        if (empty($sourceUrl) && isset($job['disc_source_url'])) $sourceUrl = $job['disc_source_url'];
        if (empty($sourceUrl)) {
            $jobMgr->fail($jobId, 'NO_SOURCE_URL', 'No source URL found for discovered video');
            $logger->error(0, $jobId, $sourceId, 'JOB_FAILED', 'No source URL');
            continue;
        }

        // Find a grabber that can handle this URL
        $grabber = GrabberManager::getGrabberForUrl($sourceUrl);
        if (!$grabber) {
            $jobMgr->fail($jobId, 'PROVIDER_UNSUPPORTED', 'No grabber available for URL: ' . $sourceUrl);
            $logger->error(0, $jobId, $sourceId, 'JOB_FAILED', 'No grabber for URL: ' . $sourceUrl);
            continue;
        }

        // Get metadata from the source
        $logger->info(0, $jobId, $sourceId, 'FETCHING_METADATA', 'Fetching metadata for: ' . $sourceUrl);
        $info = $grabber->fetchInfo($sourceUrl);

        if (!$info['status']) {
            $jobMgr->fail($jobId, 'METADATA_FAILED', 'Failed to fetch metadata: ' . (isset($info['error']) ? $info['error'] : 'Unknown'));
            $logger->error(0, $jobId, $sourceId, 'METADATA_FAILED', $info['error']);
            continue;
        }

        // Determine user and category
        $uid = 1; // Default admin user
        $categoryId = $source ? intval($source['category_id']) : 0;
        if ($categoryId <= 0) {
            $categoryId = 1; // Default category
        }

        $title = $info['title'];
        $description = isset($info['description']) ? $info['description'] : '';
        $tags = isset($info['tags']) ? $info['tags'] : '';
        $duration = isset($info['duration']) ? intval($info['duration']) : 0;
        $thumbUrl = isset($info['thumbnail']) ? $info['thumbnail'] : '';
        if (empty($title)) $title = 'Untitled Video';

        // Dedup: check if a video with this source_url already exists
        $existingVid = 0;
        $existingRs = $conn->Execute("SELECT VID, active FROM video WHERE source_url = " . $conn->qStr($sourceUrl) . " ORDER BY VID DESC LIMIT 1");
        if ($existingRs && !$existingRs->EOF) {
            $existingActive = intval($existingRs->fields['active']);
            $existingVid = intval($existingRs->fields['VID']);
            // If video is already being downloaded (active=2) or in queue (active=3), skip
            if ($existingActive == 2 || $existingActive == 3) {
                $logger->info(0, $jobId, $sourceId, 'SKIPPED', "VID $existingVid already downloading/queued for this URL, skipping");
                $jobMgr->fail($jobId, 'ALREADY_PROCESSING', "VID $existingVid already processing for this URL");
                continue;
            }
            // If video exists but is inactive (failed download), reuse it
            $logger->info(0, $jobId, $sourceId, 'REUSING_VID', "Reusing existing VID $existingVid for this URL (was active=$existingActive)");
        }

        if ($existingVid > 0) {
            // Reuse existing video record — reset it for new download
            $vid = $existingVid;
            $conn->Execute("UPDATE video SET
                active = '2',
                title = " . $conn->qStr($title) . ",
                channel = " . intval($categoryId) . ",
                keyword = " . $conn->qStr($tags) . ",
                description = " . $conn->qStr($description) . ",
                duration = '" . $duration . "',
                last_update = " . time() . "
                WHERE VID = " . intval($vid) . " LIMIT 1");
        } else {
            // Insert new AVS video record (active=2 = grabbing)
            $sql = "INSERT INTO video SET
                    UID = " . intval($uid) . ",
                    title = " . $conn->qStr($title) . ",
                    channel = " . intval($categoryId) . ",
                    keyword = " . $conn->qStr($tags) . ",
                    description = " . $conn->qStr($description) . ",
                    space = '0',
                    duration = '" . $duration . "',
                    addtime = '" . time() . "',
                    adddate = '" . date('Y-m-d') . "',
                    vkey = '" . mt_rand() . "',
                    type = 'public',
                    source_url = " . $conn->qStr($sourceUrl) . ",
                    active = '2'";
            $conn->Execute($sql);
            $vid = intval($conn->Insert_ID());
        }

        if ($vid <= 0) {
            $jobMgr->fail($jobId, 'AVS_IMPORT_FAILED', 'Failed to create video record in AVS');
            $logger->error(0, $jobId, $sourceId, 'AVS_IMPORT_FAILED', 'Could not insert video record');
            continue;
        }

        // Set vkey and vdoname
        $vkey = substr(md5($vid), 11, 20);
        $vdoname = $vid . '.mp4';
        $conn->Execute("UPDATE video SET vkey = '" . $vkey . "', vdoname = " . $conn->qStr($vdoname) . " WHERE VID = " . intval($vid) . " LIMIT 1");

        $logger->info(0, $jobId, $sourceId, 'VIDEO_CREATED', "AVS video record created: VID=$vid");

        // Invoke existing grabber_worker.php for download + conversion
        $encodedUrl = base64_encode($sourceUrl);
        $encodedThumb = base64_encode($thumbUrl);
        $workerScript = $config['BASE_DIR'] . '/scripts/grabber_worker.php';

        if (file_exists($workerScript)) {
            $cmd = sprintf('%s %s %d %s %s %s %d',
                escapeshellarg($config['phppath']),
                escapeshellarg($workerScript),
                $vid,
                escapeshellarg($encodedUrl),
                escapeshellarg($quality),
                escapeshellarg($encodedThumb),
                $jobId
            );

            $logger->info(0, $jobId, $sourceId, 'WORKER_SPAWNED', "Invoking grabber_worker.php for VID=$vid");

            // Run in background
            $lg = $config['LOG_DIR'] . '/' . $vid . '.mass_grab.log';
            @shell_exec("$cmd > " . escapeshellarg($lg) . " 2>&1 &");
        } else {
            $jobMgr->fail($jobId, 'WORKER_NOT_FOUND', 'grabber_worker.php not found');
            $logger->error(0, $jobId, $sourceId, 'WORKER_NOT_FOUND', 'Worker script not found: ' . $workerScript);
            $conn->Execute("UPDATE video SET active = '0' WHERE VID = " . intval($vid) . " LIMIT 1");
            continue;
        }

        // Worker will mark job as COMPLETED when download finishes
        $logger->info(0, $jobId, $sourceId, 'JOB_QUEUED', "Job #$jobId queued for download, VID=$vid (worker handles completion)");

        echo "[" . date('Y-m-d H:i:s') . "] Job #$jobId -> VID $vid queued for download\n";
    }
}

// ============================================================
// 2b. PUMP CONVERSION QUEUE (honors the panel's Max Simultaneous Conversions)
// ============================================================
// Grabbed videos are handed to the AVS conversion queue (conversion_queue_fp)
// by the workers. Stock check_q() only starts ONE queued conversion per call,
// so a batch of grabs would otherwise drain slowly. Start up to the free
// slots of the configured q_limit (Max Simultaneous Conversions) here, so the
// mass-grabber conversions run in parallel - capped by the same setting used
// by Settings > Video Conversion.
$convStarted = 0;
if (function_exists('pump_conversion_queue')) {
    $convStarted = pump_conversion_queue();
}
if ($convStarted > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Conversion queue: started " . $convStarted . " conversion(s) (max simultaneous: " . intval(isset($config['q_limit']) ? $config['q_limit'] : 1) . ")\n";
}

// ============================================================
// 3. CLEANUP
// ============================================================
$deletedJobs = $jobMgr->cleanup(90);
$runMgr = new RunManager();
$deletedRuns = $runMgr->cleanup(90);
$deletedLogs = $logger->cleanup(30);

if ($deletedJobs > 0 || $deletedRuns > 0 || $deletedLogs > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Cleanup: $deletedJobs jobs, $deletedRuns runs, $deletedLogs logs deleted\n";
}

// ============================================================
// 4. STUCK VIDEOS CLEANUP (vídeos presos em active=2 ou 3)
// ============================================================
// IMPORTANTE: nunca resetar vídeos que estão nas filas de conversão
// (conversion_queue_fp/sp), mesmo com last_update antigo — arquivos
// grandes (100-400MB) levam mais de 30 min para converter e o
// check_q() só atualiza last_update ao INICIAR a conversão, não durante.
// Resetar no meio da conversão órfã o ffmpeg e trava o vídeo em loop.
$stuckTimeout = 1800; // 30 minutos (worker agora tem timeout de 5 min no yt-dlp)
$stuckCutoff = time() - $stuckTimeout;

// Reset vídeos presos em active=2 (baixando) por mais de 30 min,
// SOMENTE se não estiverem em nenhuma fila de conversão
$sql = "UPDATE video SET active = '0' WHERE active = '2' AND last_update > 0 AND last_update < " . $stuckCutoff
     . " AND VID NOT IN (SELECT VID FROM conversion_queue_fp)"
     . " AND VID NOT IN (SELECT VID FROM conversion_queue_sp)";
$conn->Execute($sql);
$stuckReset = $conn->Affected_Rows();

// Reset vídeos presos em active=3 (na fila) por mais de 30 min,
// SOMENTE se não estiverem em nenhuma fila de conversão (órfãos de verdade).
// Se estiverem na fila, a conversão ainda pode estar rodando.
$sql = "UPDATE video SET active = '0' WHERE active = '3' AND last_update > 0 AND last_update < " . $stuckCutoff
     . " AND VID NOT IN (SELECT VID FROM conversion_queue_fp)"
     . " AND VID NOT IN (SELECT VID FROM conversion_queue_sp)";
$conn->Execute($sql);
$stuckReset += $conn->Affected_Rows();

// Reset jobs PROCESSING há mais de 30 min (crash recovery)
$stuckReset += $jobMgr->resetStaleJobs($stuckTimeout);

if ($stuckReset > 0) {
    echo "[" . date('Y-m-d H:i:s') . "] Stuck cleanup: $stuckReset items reset (videos stuck active=2/3 + stale jobs)\n";
}

// ============================================================
// DONE
// ============================================================
$elapsed = time() - $startTime;
echo "[" . date('Y-m-d H:i:s') . "] Mass Grabber Cron finished in {$elapsed}s\n";
exit(0);
