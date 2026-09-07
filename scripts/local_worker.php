<?php
/**
 * Local Worker — processes videos marked for local processing (processor=local).
 *
 * Run on the PC that has FFmpeg installed and database access:
 *   php scripts/local_worker.php [loop] [limit]
 *
 *   loop   = run continuously (default: process one job and exit)
 *   limit  = max jobs per loop iteration (default: 1)
 *
 * What it does:
 *   1. Finds videos with active=3 that are NOT in any conversion queue
 *      (i.e., skipped by the server because processor=local).
 *   2. Marks them active=2 (processing).
 *   3. Runs the full FFmpeg encoding ladder (same as convert_videos.php).
 *   4. Saves output to h264/, updates formats/lformats in DB.
 *   5. Calls postConversion() to finalize metadata and activate the video.
 *   6. Repeats (if loop) or exits.
 *
 * Prerequisites:
 *   - FFmpeg and FFProbe accessible via $config['ffmpeg'] / $config['ffprobe']
 *   - Database accessible from the PC (same DB as the VM)
 *   - Media files accessible via $config['VDO_DIR'] and $config['H264_DIR']
 */

define('_VALID', 1);
define('_ENTER', true);
define('_CLI', true);

$basedir = dirname(__FILE__);
require $basedir. '/../include/config.php';
require $basedir. '/../include/function_video.php';
require $basedir. '/../include/function_conversion.php';

// Parse CLI args.
$loopMode = isset($argv[1]) && $argv[1] === 'loop';
$maxJobs  = isset($argv[2]) ? max(1, intval($argv[2])) : 1;

$nl = "=========================================================\n";
$processor = isset($config['processor']) ? $config['processor'] : 'ffmpeg';

echo $nl;
echo "  AVS Local Worker\n";
echo $nl;
echo "  Processor config: $processor\n";
echo "  Loop mode:        ".($loopMode ? 'yes' : 'no')."\n";
echo "  Max jobs/loop:    $maxJobs\n";
echo "  FFmpeg:           ".$config['ffmpeg']."\n";
echo "  H264 dir:         ".$config['H264_DIR']."\n";
echo $nl;

if ($processor === 'ffmpeg') {
    echo "[Local Worker] Processor is set to 'ffmpeg' (server mode). Nothing to do locally.\n";
    echo "               Change to 'local' in Settings → Video Conversion → Conversion Engine.\n";
    exit(0);
}

$processed = 0;

do {
    // Find one video: active=3, has vdoname, NOT in any conversion queue.
    $sql = "SELECT v.VID, v.vdoname, v.title, v.UID, v.cut, v.cut_out
            FROM video v
            WHERE v.active = '3'
              AND v.vdoname IS NOT NULL AND v.vdoname != ''
              AND NOT EXISTS (
                  SELECT 1 FROM conversion_queue_fp q WHERE q.VID = v.VID
                  UNION
                  SELECT 1 FROM conversion_queue_sp q WHERE q.VID = v.VID
              )
            ORDER BY v.last_update ASC
            LIMIT 1";
    $rs = $conn->execute($sql);

    if (!$rs || $rs->EOF) {
        echo "[Local Worker] No pending jobs found.\n";
        break;
    }

    $video = $rs->fields;
    $vid       = intval($video['VID']);
    $vdoname   = $video['vdoname'];
    $videoPath = $config['VDO_DIR'].'/'.$vdoname;

    echo $nl;
    echo "  Processing VID $vid: $vdoname\n";
    echo $nl;

    // Verify file exists.
    if (!file_exists($videoPath) || !is_file($videoPath) || filesize($videoPath) < 100) {
        echo "  [ERROR] Source file missing: $videoPath — skipping.\n";
        $conn->execute("UPDATE video SET active = '0', last_update = '".time()."' WHERE VID = ".$vid." LIMIT 1");
        continue;
    }

    // Mark as processing (active=2).
    $conn->execute("UPDATE video SET active = '2', last_update = '".time()."' WHERE VID = ".$vid." LIMIT 1");

    // Probe the source.
    $ffp_data = get_ffprobe_data($videoPath);
    $video_info = ffpInfo($ffp_data);
    $video_info['UID']        = $video['UID'];
    $video_info['title']      = $video['title'];
    $video_info['video_name'] = $vdoname;
    $video_info['video_path'] = $videoPath;

    echo "  Source: ".$video_info['width']."x".$video_info['height']." ".$video_info['codec_name']." — ".$video_info['duration']."s\n";

    // Run encoding ladder.
    $encodings = getEncodings();
    foreach ($encodings as $encoding) {
        convert($encoding, $vid, $vdoname, $video_info);
    }

    // Generate thumbnails.
    postThumbs($vid, $videoPath);

    // Finalize metadata, activate video, cleanup.
    postConversion($vid, $videoPath);

    echo "  [OK] VID $vid processed.\n";
    $processed++;

    if ($processed >= $maxJobs) {
        break;
    }

    // Brief pause before next job.
    if ($loopMode) {
        sleep(1);
    }

} while ($loopMode);

echo $nl;
echo "  Done. Processed $processed job(s).\n";
echo $nl;
exit(0);
?>
