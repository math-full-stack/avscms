<?php
defined('_VALID') or die('Restricted Access!');

/**
 * Scheduler - Handles automatic scheduling, job recovery, and cleanup.
 */
class Scheduler {

    /**
     * Main scheduler entry point (called by CRON).
     *
     * 1. Find sources due for automatic discovery
     * 2. Run discovery for each
     * 3. Auto-queue new videos (up to max_per_run)
     * 4. Reset stale jobs
     * 5. Cleanup old data
     */
    public function run() {
        $sourceMgr = new SourceManager();
        $logger = new Logger();
        $jobMgr = new JobManager();
        $discMgr = new DiscoveryManager();
        $runMgr = new RunManager();

        // Fail runs stuck in RUNNING (crashed background processes) so they
        // neither block new scans nor keep the dashboard on "Scanning..."
        $staleRuns = $runMgr->failStaleRuns(1800);
        if ($staleRuns > 0) {
            $logger->info(0, 0, 0, 'STALE_RUN_FAILED', $staleRuns . ' stale runs failed');
        }

        // 1. Find due sources
        $dueSources = $sourceMgr->getDueSources();
        $results = array();

        foreach ($dueSources as $source) {
            $sourceId = intval($source['id']);
            $maxPerRun = intval($source['max_per_run']);
            if ($maxPerRun < 1) $maxPerRun = 5;

            // Skip if a scan is already running for this source (manual or cron)
            if ($runMgr->hasActiveRun($sourceId)) {
                $logger->info(0, 0, $sourceId, 'SCAN_SKIPPED',
                    'Automatic scan skipped: a scan is already running for: ' . $source['name']);
                $results[] = array(
                    'source_id' => $sourceId,
                    'name'      => $source['name'],
                    'skipped'   => 'already running',
                );
                $sourceMgr->updateNextRun($sourceId);
                continue;
            }

            // Run discovery
            $scanResult = MassGrabberManager::discovery()->scan($sourceId, array(
                'max_pages' => intval($source['max_pages']),
                'manual'    => false,
            ));

            if (isset($scanResult['error'])) {
                $sourceMgr->recordError($sourceId, $scanResult['error']);
                $results[] = array('source_id' => $sourceId, 'error' => $scanResult['error']);
                continue;
            }

            // Auto-queue new videos up to max_per_run
            $discovered = MassGrabberManager::discovery()->getDiscovered($sourceId, array('status' => 'NEW'), $maxPerRun);
            if (!empty($discovered['videos'])) {
                $ids = array();
                foreach ($discovered['videos'] as $v) {
                    $ids[] = intval($v['id']);
                }
                $queueResult = $jobMgr->createBulk($ids, $sourceId, isset($scanResult['run_id']) ? $scanResult['run_id'] : 0);

                $logger->info(isset($scanResult['run_id']) ? $scanResult['run_id'] : 0, 0, $sourceId,
                    'AUTO_QUEUED',
                    $queueResult['created'] . ' jobs auto-queued for: ' . $source['name']);

                // Update run stats
                if (!empty($scanResult['run_id'])) {
                    $runMgr = new RunManager();
                    $runMgr->update($scanResult['run_id'], array(
                        'queued_count' => $queueResult['created'],
                    ));
                }
            }

            // Update next run time
            $sourceMgr->updateNextRun($sourceId);

            $results[] = array(
                'source_id' => $sourceId,
                'name'      => $source['name'],
                'found'     => isset($scanResult['found']) ? $scanResult['found'] : 0,
                'new'       => isset($scanResult['new']) ? $scanResult['new'] : 0,
                'queued'    => !empty($discovered['videos']) ? count($discovered['videos']) : 0,
            );
        }

        // 2. Reset stale jobs (crash recovery)
        $staleReset = $jobMgr->resetStaleJobs(1800);
        if ($staleReset > 0) {
            $logger->info(0, 0, 0, 'STALE_RESET', $staleReset . ' stale jobs reset');
        }

        // 3. Cleanup old data
        $jobMgr->cleanup(90);
        $runMgr = new RunManager();
        $runMgr->cleanup(90);
        $logger->cleanup(30);

        return array(
            'sources_scanned' => count($dueSources),
            'results'         => $results,
            'stale_reset'     => $staleReset,
        );
    }

    /**
     * Get scheduler status for dashboard.
     * @return array
     */
    public function getStatus() {
        global $conn;
        $status = array(
            'next_source'     => null,
            'next_source_text' => 'None scheduled',
            'active_sources'  => 0,
            'pending_jobs'    => 0,
            'running_jobs'    => 0,
        );

        try {
            $rs = $conn->Execute("SELECT id, name, next_run_at FROM grabber_sources
                                  WHERE enabled = 1 AND automatic_enabled = 1 AND discovery_enabled = 1
                                  AND next_run_at > 0
                                  ORDER BY next_run_at ASC LIMIT 1");
            if ($rs && !$rs->EOF) {
                $status['next_source'] = $rs->fields;
                $diff = intval($rs->fields['next_run_at']) - time();
                if ($diff <= 0) {
                    $status['next_source_text'] = 'Due now';
                } elseif ($diff < 3600) {
                    $status['next_source_text'] = 'In ' . floor($diff / 60) . ' min';
                } else {
                    $status['next_source_text'] = 'In ' . floor($diff / 3600) . 'h ' . floor(($diff % 3600) / 60) . 'm';
                }
            }
            $rs2 = $conn->Execute("SELECT COUNT(*) AS c FROM grabber_sources WHERE enabled = 1 AND automatic_enabled = 1");
            $status['active_sources'] = $rs2 ? intval($rs2->fields['c']) : 0;
        } catch (Exception $e) {} catch (Throwable $e) {}

        $jobMgr = new JobManager();
        $counts = $jobMgr->getStatusCounts();
        $status['pending_jobs'] = $counts['PENDING'];
        $status['running_jobs'] = $counts['PROCESSING'];

        return $status;
    }
}
