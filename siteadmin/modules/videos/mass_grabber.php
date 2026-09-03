<?php
defined('_VALID') or die('Restricted Access!');
Auth::checkAdmin();

require_once $config['BASE_DIR'] . '/include/function_video.php';
require_once $config['BASE_DIR'] . '/include/function_queue.php';
require_once $config['BASE_DIR'] . '/include/function_thumbs.php';
require_once $config['BASE_DIR'] . '/classes/filter.class.php';
require_once $config['BASE_DIR'] . '/classes/grabbers/mass/MassGrabberManager.php';

@set_time_limit(0);
@ini_set('max_execution_time', 0);
@ini_set('memory_limit', '512M');

$action = isset($_GET['a']) ? trim($_GET['a']) : 'dashboard';

// ============================================================
// TABLE CHECK — show install instructions if tables missing
// ============================================================
$tablesReady = MassGrabberManager::tablesExist();

if (!$tablesReady && $action !== 'dashboard') {
    // AJAX calls when tables missing — return error JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('status' => false, 'error' => 'Mass Grabber tables not found. Please run sql/install_mass_grabber.sql against your database.'));
    exit();
}

if (!$tablesReady) {
    // Page load — show install notice (no return! that kills parent scripts)
    $smarty->assign('mg_needs_install', true);
    $smarty->assign('grabbing', '');
    $smarty->assign('path', '');
    $smarty->assign('filesize', '');

} else {

$smarty->assign('mg_needs_install', false);

// ============================================================
// AJAX ENDPOINTS
// ============================================================

// --- AJAX: Scan / Discover ---
if ($action === 'scan') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $sourceId = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
    $filter = isset($_POST['filter']) ? trim($_POST['filter']) : 'videos';
    $query = isset($_POST['query']) ? trim($_POST['query']) : '';
    $timeframe = isset($_POST['timeframe']) ? trim($_POST['timeframe']) : '';
    $sort = isset($_POST['sort']) ? trim($_POST['sort']) : 'newest';

    if ($sourceId <= 0) {
        echo json_encode(array('status' => false, 'error' => 'Invalid source ID'));
        exit();
    }

    // Release any stale lock for this source
    MassGrabberManager::acquireSourceLock($sourceId);

    // Create a run record (status=RUNNING)
    $runMgr = new RunManager();
    $runId = $runMgr->create($sourceId, 'MANUAL');

    // Launch scan in background
    global $config;
    $scanScript = $config['BASE_DIR'] . '/scripts/grabber_scan.php';
    $optionsJson = json_encode(array(
        'source_id' => $sourceId,
        'run_id'    => $runId,
        'filter'    => $filter,
        'query'     => $query,
        'timeframe' => $timeframe,
        'sort'      => $sort,
        'max_pages' => 9999,
    ));
    $cmd = sprintf('%s %s %s %s > /dev/null 2>&1 &',
        escapeshellarg($config['phppath']),
        escapeshellarg($scanScript),
        escapeshellarg($sourceId),
        escapeshellarg(base64_encode($optionsJson))
    );
    @shell_exec($cmd);

    echo json_encode(array('status' => true, 'run_id' => $runId, 'message' => 'Scan started'));
    exit();
}

// --- AJAX: Check scan status ---
if ($action === 'scan_status') {
    header('Content-Type: application/json; charset=utf-8');
    $runId = isset($_GET['run_id']) ? intval($_GET['run_id']) : 0;
    if ($runId <= 0) {
        echo json_encode(array('status' => false, 'error' => 'Invalid run ID'));
        exit();
    }
    $runMgr2 = new RunManager();
    $run = $runMgr2->getById($runId);
    if (!$run) {
        echo json_encode(array('status' => false, 'error' => 'Run not found'));
        exit();
    }
    // Lazy watchdog: a run stuck RUNNING for 30+ min (e.g. the background
    // process crashed) is failed here so the UI never polls forever.
    if ($run['status'] === 'RUNNING' && intval($run['started_at']) > 0 &&
        (time() - intval($run['started_at'])) > 1800) {
        $runMgr2->update($runId, array(
            'status'        => 'FAILED',
            'finished_at'   => time(),
            'error_message' => 'Scan timed out after 30 minutes',
        ));
        $run['status'] = 'FAILED';
    }

    $isRunning = ($run['status'] === 'RUNNING');
    $discMgr = new DiscoveryManager();
    $counts = $discMgr->getStatusCounts(intval($run['source_id']));
    echo json_encode(array(
        'status'     => true,
        'run_status' => $run['status'],
        'running'    => $isRunning,
        'found'      => intval($run['found_count']),
        'new'        => intval($run['new_count']),
        'existing'   => intval($run['existing_count']),
        'queued'     => intval($run['queued_count']),
        'failed'     => intval($run['failed_count']),
        'counts'     => $counts,
    ));
    exit();
}

// --- AJAX: Get discovered videos ---
if ($action === 'get_discovered') {
    header('Content-Type: application/json; charset=utf-8');
    $sourceId = isset($_GET['source_id']) ? intval($_GET['source_id']) : 0;
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;
    $timeframe = isset($_GET['timeframe']) ? trim($_GET['timeframe']) : null;
    $sortBy = isset($_GET['sort']) ? trim($_GET['sort']) : 'newest';
    $page = 1;
    if (isset($_GET['limit']) && isset($_GET['offset'])) {
        $limit = max(1, intval($_GET['limit']));
        $offset = max(0, intval($_GET['offset']));
        $page = max(1, intval($offset / $limit) + 1);
    } else {
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = 30;
        $offset = ($page - 1) * $limit;
    }

    if ($sourceId <= 0) {
        echo json_encode(array('status' => false, 'error' => 'Invalid source ID'));
        exit();
    }

    $filters = array();
    if ($status) $filters['status'] = $status;
    if ($timeframe) $filters['timeframe'] = $timeframe;
    if ($sortBy) $filters['sort'] = $sortBy;

    $result = MassGrabberManager::discovery()->getDiscovered($sourceId, $filters, $limit, $offset);
    echo json_encode(array('status' => true, 'videos' => $result['videos'], 'total' => $result['total'], 'page' => $page));
    exit();
}

// --- AJAX: Get video preview info ---
if ($action === 'get_video_preview') {
    header('Content-Type: application/json; charset=utf-8');
    $videoId = isset($_GET['video_id']) ? intval($_GET['video_id']) : 0;
    if ($videoId <= 0) {
        echo json_encode(array('status' => false, 'error' => 'Invalid video ID'));
        exit();
    }

    $discMgr = new DiscoveryManager();
    $video = $discMgr->getById($videoId);
    if (!$video) {
        echo json_encode(array('status' => false, 'error' => 'Video not found'));
        exit();
    }

    $sourceUrl = !empty($video['source_url']) ? $video['source_url'] : $video['canonical_url'];
    if (empty($sourceUrl)) {
        echo json_encode(array('status' => false, 'error' => 'No source URL'));
        exit();
    }

    $embedUrl = '';
    $streamUrl = '';
    $host = strtolower(parse_url($sourceUrl, PHP_URL_HOST) ?: '');

    // YouTube
    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/', $sourceUrl, $m)) {
        $embedUrl = 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&rel=0';
    }
    // Vimeo
    elseif (preg_match('/vimeo\.com\/(\d+)/', $sourceUrl, $m)) {
        $embedUrl = 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1';
    }
    // Dailymotion
    elseif (preg_match('/dailymotion\.com\/video\/([a-zA-Z0-9]+)/', $sourceUrl, $m)) {
        $embedUrl = 'https://www.dailymotion.com/embed/video/' . $m[1] . '?autoplay=1';
    }
    // XFree - embed URL pattern: https://www.xfree.com/embed/{id}
    elseif (strpos($host, 'xfree') !== false) {
        if (preg_match('/[?&]id=(\d+)/', $sourceUrl, $m)) {
            $embedUrl = 'https://www.xfree.com/embed/' . $m[1];
        } elseif (preg_match('/\/video[\/-](\d+)/', $sourceUrl, $m)) {
            $embedUrl = 'https://www.xfree.com/embed/' . $m[1];
        }
        // Fallback: try to get video ID from page HTML
        if (empty($embedUrl)) {
            $html = @file_get_contents($sourceUrl);
            if ($html && preg_match('/"id"\s*:\s*"?(\d+)/', $html, $m)) {
                $embedUrl = 'https://www.xfree.com/embed/' . $m[1];
            }
        }
    }
    // Sonovinhasbr - get embed from page HTML
    elseif (strpos($host, 'sonovinhasbr') !== false) {
        $html = @file_get_contents($sourceUrl);
        if ($html) {
            if (preg_match('/<iframe[^>]+src="(https?:\/\/[^"]*player\.php[^"]*)"/i', $html, $m)) {
                $embedUrl = str_replace('&amp;', '&', $m[1]);
            } elseif (preg_match('/"embedUrl"\s*:\s*"([^"]+)"/i', $html, $m)) {
                $embedUrl = $m[1];
            }
        }
    }

    $previewData = array(
        'title'      => $video['title'],
        'thumbnail'  => $video['thumbnail_url'],
        'duration'   => $video['duration'],
        'embed_url'  => $embedUrl,
        'stream_url' => $streamUrl,
        'source_url' => $sourceUrl,
    );

    echo json_encode(array('status' => true, 'preview' => $previewData));
    exit();
}

// --- AJAX: Bulk grab selected videos ---
if ($action === 'bulk_grab') {
    header('Content-Type: application/json; charset=utf-8');
    $ids = isset($_POST['ids']) ? $_POST['ids'] : array();
    $sourceId = isset($_POST['source_id']) ? intval($_POST['source_id']) : 0;
    $runId = isset($_POST['run_id']) ? intval($_POST['run_id']) : 0;

    if (empty($ids) || !is_array($ids)) {
        echo json_encode(array('status' => false, 'error' => 'No videos selected'));
        exit();
    }

    $jobMgr = new JobManager();
    $result = $jobMgr->createBulk(array_map('intval', $ids), $sourceId, $runId);

    echo json_encode(array(
        'status'  => true,
        'created' => $result['created'],
        'skipped' => $result['skipped'],
        'message' => $result['created'] . ' jobs queued' . ($result['skipped'] > 0 ? ', ' . $result['skipped'] . ' skipped' : ''),
    ));
    exit();
}

// --- AJAX: Get source details ---
if ($action === 'get_source') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $sourceMgr2 = new SourceManager();
    $source = $sourceMgr2->getById($id);
    if (!$source) {
        echo json_encode(array('status' => false, 'error' => 'Source not found'));
        exit();
    }
    echo json_encode(array('status' => true, 'source' => $source));
    exit();
}

// --- AJAX: Save source ---
if ($action === 'save_source') {
    header('Content-Type: application/json; charset=utf-8');
    $sourceMgr = new SourceManager();
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    $data = array(
        'name'                => isset($_POST['name']) ? trim(strip_tags($_POST['name'])) : '',
        'provider'            => isset($_POST['provider']) ? trim($_POST['provider']) : '',
        'discovery_url'       => isset($_POST['discovery_url']) ? trim($_POST['discovery_url']) : '',
        'category_id'         => isset($_POST['category_id']) ? intval($_POST['category_id']) : 0,
        'quality'             => isset($_POST['quality']) ? trim($_POST['quality']) : 'best',
        'automatic_enabled'   => isset($_POST['automatic_enabled']) ? intval($_POST['automatic_enabled']) : 0,
        'discovery_enabled'   => isset($_POST['discovery_enabled']) ? intval($_POST['discovery_enabled']) : 1,
        'max_per_run'         => isset($_POST['max_per_run']) ? intval($_POST['max_per_run']) : 5,
        'max_pages'           => isset($_POST['max_pages']) ? intval($_POST['max_pages']) : 3,
        'schedule_type'       => isset($_POST['schedule_type']) ? trim($_POST['schedule_type']) : 'daily',
        'schedule_value'      => isset($_POST['schedule_value']) ? trim($_POST['schedule_value']) : '02:00',
        'enabled'             => isset($_POST['enabled']) ? intval($_POST['enabled']) : 1,
        'delay_seconds'       => isset($_POST['delay_seconds']) ? intval($_POST['delay_seconds']) : 1,
    );

    // Extract domain from URL
    if (!empty($data['discovery_url'])) {
        $parts = parse_url($data['discovery_url']);
        if ($parts) {
            $data['domain'] = isset($parts['host']) ? $parts['host'] : '';
        }
    }

    if (empty($data['name'])) {
        echo json_encode(array('status' => false, 'error' => 'Name is required'));
        exit();
    }

    if (empty($data['provider'])) {
        echo json_encode(array('status' => false, 'error' => 'Provider is required'));
        exit();
    }

    // Validate provider exists
    $provider = MassGrabberManager::getProviderByName($data['provider']);
    if (!$provider) {
        echo json_encode(array('status' => false, 'error' => 'Provider not found: ' . htmlspecialchars($data['provider'])));
        exit();
    }

    if ($id > 0) {
        $sourceMgr->update($id, $data);
        $msg = 'Source updated';
    } else {
        $id = $sourceMgr->create($data);
        $msg = 'Source created';
    }

    if ($data['automatic_enabled'] && $data['enabled']) {
        $sourceMgr->updateNextRun($id);
    }

    echo json_encode(array('status' => true, 'id' => $id, 'message' => $msg));
    exit();
}

// --- AJAX: Delete source ---
if ($action === 'delete_source') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) {
        echo json_encode(array('status' => false, 'error' => 'Invalid ID'));
        exit();
    }
    $sourceMgr = new SourceManager();
    $sourceMgr->delete($id);
    echo json_encode(array('status' => true, 'message' => 'Source deleted'));
    exit();
}

// --- AJAX: Toggle source ---
if ($action === 'toggle_source') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) {
        echo json_encode(array('status' => false, 'error' => 'Invalid ID'));
        exit();
    }
    $sourceMgr = new SourceManager();
    $newState = $sourceMgr->toggle($id);
    echo json_encode(array('status' => true, 'enabled' => $newState));
    exit();
}

// --- AJAX: Get jobs ---
if ($action === 'get_jobs') {
    header('Content-Type: application/json; charset=utf-8');
    $status = isset($_GET['status']) ? trim($_GET['status']) : null;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = 30;
    $offset = ($page - 1) * $limit;

    $filters = array();
    if ($status) $filters['status'] = $status;

    $jobMgr = new JobManager();
    $result = $jobMgr->getJobs($filters, $limit, $offset);
    echo json_encode(array('status' => true, 'jobs' => $result['jobs'], 'total' => $result['total'], 'page' => $page));
    exit();
}

// --- AJAX: Cancel job ---
if ($action === 'cancel_job') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $jobMgr = new JobManager();
    $jobMgr->cancel($id);
    echo json_encode(array('status' => true, 'message' => 'Job cancelled'));
    exit();
}

// --- AJAX: Pause job ---
if ($action === 'pause_job') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) { echo json_encode(array('status' => false, 'error' => 'Invalid job ID')); exit(); }
    $jobMgr = new JobManager();
    $jobMgr->pause($id);
    echo json_encode(array('status' => true, 'message' => 'Job paused'));
    exit();
}

// --- AJAX: Resume job (PAUSED -> PENDING) ---
if ($action === 'resume_job') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) { echo json_encode(array('status' => false, 'error' => 'Invalid job ID')); exit(); }
    $jobMgr = new JobManager();
    $jobMgr->resume($id);
    echo json_encode(array('status' => true, 'message' => 'Job resumed'));
    exit();
}

// --- AJAX: Retry failed job ---
if ($action === 'retry_job') {
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) { echo json_encode(array('status' => false, 'error' => 'Invalid job ID')); exit(); }
    $jobMgr = new JobManager();
    $jobMgr->retry($id);
    echo json_encode(array('status' => true, 'message' => 'Job queued for retry'));
    exit();
}

// --- AJAX: Process queue now (run cron in background) ---
if ($action === 'process_now') {
    header('Content-Type: application/json; charset=utf-8');
    global $config;
    $cronScript = $config['BASE_DIR'] . '/scripts/grabber_cron.php';
    $cmd = sprintf('%s %s > /dev/null 2>&1 &',
        escapeshellarg($config['phppath']),
        escapeshellarg($cronScript)
    );
    @shell_exec($cmd);
    echo json_encode(array('status' => true, 'message' => 'Queue processing started'));
    exit();
}

// --- AJAX: Pause all pending jobs ---
if ($action === 'pause_all') {
    header('Content-Type: application/json; charset=utf-8');
    $jobMgr = new JobManager();
    $count = $jobMgr->pauseAll();
    echo json_encode(array('status' => true, 'message' => $count . ' jobs paused'));
    exit();
}

// --- AJAX: Resume all paused jobs ---
if ($action === 'resume_all') {
    header('Content-Type: application/json; charset=utf-8');
    $jobMgr = new JobManager();
    $count = $jobMgr->resumeAll();
    echo json_encode(array('status' => true, 'message' => $count . ' jobs resumed'));
    exit();
}

// --- AJAX: Get run history ---
if ($action === 'get_runs') {
    header('Content-Type: application/json; charset=utf-8');
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $offset = ($page - 1) * $limit;

    $runMgr = new RunManager();
    $result = $runMgr->getAll(array(), $limit, $offset);
    echo json_encode(array('status' => true, 'runs' => $result['runs'], 'total' => $result['total'], 'page' => $page));
    exit();
}

// --- AJAX: Get logs ---
if ($action === 'get_logs') {
    header('Content-Type: application/json; charset=utf-8');
    $filters = array();
    if (isset($_GET['run_id'])) $filters['run_id'] = intval($_GET['run_id']);
    if (isset($_GET['job_id'])) $filters['job_id'] = intval($_GET['job_id']);
    if (isset($_GET['source_id'])) $filters['source_id'] = intval($_GET['source_id']);
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;

    $logger = new Logger();
    $result = $logger->getLogs($filters, $limit);
    echo json_encode(array('status' => true, 'logs' => $result['logs'], 'total' => $result['total']));
    exit();
}

// --- AJAX: Run manual scheduler tick ---
if ($action === 'run_scheduler') {
    header('Content-Type: application/json; charset=utf-8');
    $scheduler = new Scheduler();
    $result = $scheduler->run();
    echo json_encode(array('status' => true, 'result' => $result));
    exit();
}

// --- AJAX: Get dashboard stats ---
if ($action === 'get_stats') {
    header('Content-Type: application/json; charset=utf-8');
    $stats = MassGrabberManager::getDashboardStats();
    echo json_encode(array('status' => true, 'stats' => $stats));
    exit();
}

// --- AJAX: Get real-time status ---
if ($action === 'get_realtime_status') {
    header('Content-Type: application/json; charset=utf-8');
    require_once $config['BASE_DIR'] . '/include/function_grab_queue.php';
    $enabled = (grabber_setting('realtime_enabled', '0') === '1');
    echo json_encode(array('status' => true, 'enabled' => $enabled));
    exit();
}

// --- AJAX: Toggle real-time mode ---
if ($action === 'toggle_realtime') {
    header('Content-Type: application/json; charset=utf-8');
    require_once $config['BASE_DIR'] . '/include/function_grab_queue.php';
    if (!grabber_settings_exist()) {
        echo json_encode(array('status' => false, 'error' => 'Settings table not found. Please run sql/migration_grabber_settings.sql'));
        exit();
    }
    $current = grabber_setting('realtime_enabled', '0');
    $newVal = ($current === '1') ? '0' : '1';
    grabber_setting_set('realtime_enabled', $newVal);
    echo json_encode(array('status' => true, 'enabled' => ($newVal === '1'), 'message' => ($newVal === '1') ? 'Real-time processing enabled' : 'Real-time processing disabled'));
    exit();
}

// ============================================================
// PAGE LOADS (non-AJAX)
// ============================================================

$sourceMgr = new SourceManager();
$jobMgr = new JobManager();
$runMgr = new RunManager();
$logger = new Logger();

// Helper: human-readable time ago
function mg_time_ago($timestamp) {
    if (!$timestamp) return 'Never';
    $diff = time() - intval($timestamp);
    if ($diff < 0) return 'Just now';
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}
function mg_duration_text($started, $finished) {
    if (!$finished || !$started) return '-';
    $dur = intval($finished) - intval($started);
    if ($dur < 0) return '-';
    if ($dur < 60) return $dur . 's';
    return floor($dur / 60) . 'm ' . ($dur % 60) . 's';
}

// Default view
$view = isset($_GET['v']) ? trim($_GET['v']) : 'dashboard';

// --- Sources page data ---
if ($view === 'sources') {
    $sources = $sourceMgr->getAll();
    foreach ($sources as &$src) {
        $src['last_run_ago'] = mg_time_ago($src['last_run_at']);
    }
    unset($src);
    $providers = MassGrabberManager::getSupportedSites();
    $categories = get_categories();
    $smarty->assign('sources', $sources);
    $smarty->assign('providers', $providers);
    $smarty->assign('categories', $categories);
}

// --- Discover page data ---
if ($view === 'discover') {
    $sources = $sourceMgr->getAll(array('enabled' => 1));
    $smarty->assign('sources', $sources);
}

// --- Queue page data ---
if ($view === 'queue') {
    $counts = $jobMgr->getStatusCounts();
    $smarty->assign('job_counts', $counts);
}

// --- History page data ---
if ($view === 'history') {
    $runs = $runMgr->getAll(array(), 30, 0);
    foreach ($runs['runs'] as &$run) {
        $run['duration_text'] = mg_duration_text($run['started_at'], $run['finished_at']);
    }
    unset($run);
    $smarty->assign('runs', $runs['runs']);
    $smarty->assign('total_runs', $runs['total']);
}

// --- Dashboard (default) ---
$stats = MassGrabberManager::getDashboardStats();
$schedulerStatus = MassGrabberManager::scheduler()->getStatus();
$smarty->assign('stats', $stats);
$smarty->assign('scheduler_status', $schedulerStatus);
$smarty->assign('view', $view);

} // end if ($tablesReady)

// Common assigns
$smarty->assign('grabbing', '');
$smarty->assign('path', '');
$smarty->assign('filesize', '');
?>
