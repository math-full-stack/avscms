<?php
define('_VALID', 1);
define('_CLI', true);
define('_ENTER', true);
define('_CONSOLE', true); // CLI: skip web sessions (and their extra DB connection)

// Usage: php reprocess_videos.php <VID1> <VID2> ...
if ($argc < 2) {
    echo "Usage: php reprocess_videos.php <VID1> [VID2] [VID3] ...\n";
    echo "Example: php reprocess_videos.php 143 146 135 131 126 124 122\n";
    exit(1);
}

$basedir = dirname(dirname(__FILE__));
require_once $basedir . '/include/config.php';
require_once $basedir . '/include/function_video.php';
require_once $basedir . '/include/function_queue.php';

if (!isset($config['conversion_q']) || $config['conversion_q'] != '1') {
    echo "Conversion queue is disabled. Reprocessamento bloqueado.\n";
    exit(0);
}

$vids = array();
for ($i = 1; $i < $argc; $i++) {
    $vid = intval($argv[$i]);
    if ($vid > 0) {
        $vids[] = $vid;
    }
}

if (empty($vids)) {
    echo "No valid video IDs provided.\n";
    exit(1);
}

echo "=== AVSCMS Video Reprocess Script ===\n";
echo "Videos to reprocess: " . implode(', ', $vids) . "\n\n";

$success = 0;
$skipped = 0;
$failed = 0;

foreach ($vids as $vid) {
    echo "--- Processing VID $vid ---\n";
    
    // Check if video exists
    $sql = "SELECT VID, source_url, active, formats FROM video WHERE VID = " . intval($vid) . " LIMIT 1";
    $rs = $conn->execute($sql);
    
    if ($conn->Affected_Rows() != 1) {
        echo "  SKIP: Video not found in database\n";
        $skipped++;
        continue;
    }
    
    $row = $rs->fields;
    $sourceUrl = trim($row['source_url'] ?? '');
    $currentState = $row['active'];
    $currentFormats = $row['formats'];
    
    if (empty($sourceUrl)) {
        echo "  SKIP: No source_url saved\n";
        $skipped++;
        continue;
    }
    
    // Check if already in conversion queue
    $qfp = $conn->execute("SELECT status FROM conversion_queue_fp WHERE VID = " . intval($vid) . " LIMIT 1");
    if ($qfp && $conn->Affected_Rows() == 1) {
        echo "  SKIP: Already in FP queue (status=" . $qfp->fields['status'] . ")\n";
        $skipped++;
        continue;
    }
    
    $qsp = $conn->execute("SELECT status FROM conversion_queue_sp WHERE VID = " . intval($vid) . " LIMIT 1");
    if ($qsp && $conn->Affected_Rows() == 1) {
        echo "  SKIP: Already in SP queue (status=" . $qsp->fields['status'] . ")\n";
        $skipped++;
        continue;
    }
    
    // Reset video: active=2 (downloading), clear formats
    $conn->execute("UPDATE video SET active = '2', formats = NULL, lformats = NULL, last_update = " . time() . " WHERE VID = " . intval($vid) . " LIMIT 1");
    echo "  Reset video (active=2, formats cleared)\n";
    
    // Launch grabber_worker
    $worker = $config['BASE_DIR'] . '/scripts/grabber_worker.php';
    if (file_exists($worker)) {
        $encodedUrl = base64_encode($sourceUrl);
        $encodedThumb = base64_encode('');
        
        $cmd = sprintf('%s %s %d %s %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($config['phppath']),
            escapeshellarg($worker),
            intval($vid),
            escapeshellarg($encodedUrl),
            escapeshellarg('best'),
            escapeshellarg($encodedThumb)
        );
        
        $logFile = $config['LOG_DIR'] . '/' . intval($vid) . '.grabber.log';
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " - Reprocessamento via script CLI. URL: $sourceUrl\n", FILE_APPEND);
        
        $pid = @shell_exec($cmd);
        echo "  Launched grabber_worker (PID=$pid)\n";
        $success++;
    } else {
        // Restore previous state if worker not found
        $conn->execute("UPDATE video SET active = '" . intval($currentState) . "' WHERE VID = " . intval($vid) . " LIMIT 1");
        echo "  FAILED: grabber_worker.php not found\n";
        $failed++;
    }
    
    echo "\n";
}

echo "=== Summary ===\n";
echo "Success: $success\n";
echo "Skipped: $skipped\n";
echo "Failed: $failed\n";
echo "Total: " . count($vids) . "\n";

exit(0);
?>