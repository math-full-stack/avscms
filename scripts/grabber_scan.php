<?php
/**
 * Background Scan Script
 * 
 * Usage: php grabber_scan.php <source_id> [base64_options_json]
 * 
 * Called by the admin panel AJAX scan endpoint.
 * Runs in background, writes results to DB.
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

if ($argc < 2) {
    echo "Usage: php grabber_scan.php <source_id> [base64_options_json]\n";
    exit(1);
}

$sourceId = intval($argv[1]);
$options = array();

if ($argc >= 3) {
    $optionsJson = base64_decode($argv[2]);
    if ($optionsJson) {
        $decoded = json_decode($optionsJson, true);
        if ($decoded) {
            $options = $decoded;
        }
    }
}

if ($sourceId <= 0) {
    echo "Invalid source ID\n";
    exit(1);
}

$runId = isset($options['run_id']) ? intval($options['run_id']) : 0;
$filter = isset($options['filter']) ? $options['filter'] : 'videos';
$query = isset($options['query']) ? $options['query'] : '';

// Run the scan
$result = MassGrabberManager::discovery()->scan($sourceId, array(
    'max_pages' => isset($options['max_pages']) ? intval($options['max_pages']) : 1,
    'manual'    => true,
    'run_id'    => $runId,
    'filter'    => $filter,
    'query'     => $query,
));

echo json_encode($result) . "\n";
exit(0);
