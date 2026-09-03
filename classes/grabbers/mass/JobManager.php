<?php
defined('_VALID') or die('Restricted Access!');

/**
 * JobManager - Manages the grab job queue.
 */
class JobManager {

    private $db = null;

    public function __construct() {
        global $conn;
        $this->db = $conn;
    }

    private function safeExec($sql) {
        try { return $this->db->Execute($sql); } catch (Exception $e) { return null; } catch (Throwable $e) { return null; }
    }

    /**
     * Create a grab job for a discovered video.
     *
     * @param int   $discoveredVideoId
     * @param int   $sourceId
     * @param int   $runId
     * @param int   $priority  1=high, 10=normal, 100=low
     * @return int  Job ID, or 0 if already queued
     */
    public function create($discoveredVideoId, $sourceId, $runId = 0, $priority = 10) {
        // Check if already queued
        $dedupMgr = new DedupManager();
        if ($dedupMgr->isAlreadyQueued($discoveredVideoId)) {
            return 0;
        }
        if ($dedupMgr->jobExists($discoveredVideoId)) {
            return 0;
        }

        $now = time();
        $sql = "INSERT INTO grabber_jobs SET
                source_id = " . intval($sourceId) . ",
                discovered_video_id = " . intval($discoveredVideoId) . ",
                job_type = 'GRAB',
                status = 'PENDING',
                priority = " . intval($priority) . ",
                attempts = 0,
                max_attempts = 3,
                scheduled_at = " . $now . ",
                run_id = " . intval($runId) . ",
                created_at = " . $now . ",
                updated_at = " . $now;

        $this->safeExec($sql);
        $jobId = intval($this->db->Insert_ID());

        if ($jobId > 0) {
            // Update discovered video status
            $discMgr = new DiscoveryManager();
            $discMgr->updateStatus($discoveredVideoId, 'QUEUED');
        }

        return $jobId;
    }

    /**
     * Create grab jobs for multiple discovered videos (bulk).
     *
     * @param array $discoveredVideoIds
     * @param int   $sourceId
     * @param int   $runId
     * @return array ['created' => int, 'skipped' => int]
     */
    public function createBulk($discoveredVideoIds, $sourceId, $runId = 0) {
        $created = 0;
        $skipped = 0;
        $createdIds = array();
        $skippedIds = array();

        foreach ($discoveredVideoIds as $dvId) {
            $dvId = intval($dvId);
            if ($dvId <= 0) {
                continue;
            }
            $jobId = $this->create($dvId, $sourceId, $runId);
            if ($jobId > 0) {
                $created++;
                $createdIds[] = $dvId;
            } else {
                $skipped++;
                $skippedIds[] = $dvId;
            }
        }

        return array(
            'created'     => $created,
            'skipped'     => $skipped,
            'ids_created' => $createdIds,
            'ids_skipped' => $skippedIds,
        );
    }

    /**
     * Claim the next pending job atomically (for worker).
     *
     * @param string $workerPid  PID of the worker process
     * @return array|null  Job data, or null if no jobs available
     */
    public function claimNext($workerPid = '') {
        $now = time();
        $timeout = 1800; // 30 min

        // Find and claim next job
        $sql = "UPDATE grabber_jobs SET
                status = 'PROCESSING',
                started_at = " . $now . ",
                worker_pid = " . intval($workerPid) . ",
                attempts = attempts + 1,
                updated_at = " . $now . "
                WHERE status = 'PENDING'
                AND scheduled_at <= " . $now . "
                AND attempts < max_attempts
                ORDER BY priority ASC, scheduled_at ASC
                LIMIT 1";

        $this->safeExec($sql);

        // Get the claimed job
        $rs = $this->safeExec("SELECT j.*, d.external_id, d.source_url, d.canonical_url,
                                         d.title AS disc_title, d.description AS disc_description,
                                         d.tags AS disc_tags, d.duration AS disc_duration,
                                         d.thumbnail_url AS disc_thumbnail, d.metadata_json
                                  FROM grabber_jobs j
                                  LEFT JOIN grabber_discovered_videos d ON j.discovered_video_id = d.id
                                  WHERE j.status = 'PROCESSING'
                                  AND j.worker_pid = " . intval($workerPid) . "
                                  ORDER BY j.id ASC
                                  LIMIT 1");

        if ($rs && !$rs->EOF) {
            return $rs->fields;
        }

        return null;
    }

    /**
     * Mark a job as completed.
     * @param int $jobId
     * @param int $videoId  AVS video ID
     */
    public function complete($jobId, $videoId = 0) {
        $now = time();
        // Clear leftover error fields from earlier failed attempts (e.g. an
        // ALREADY_PROCESSING retry) so completed jobs look clean in the UI.
        $this->safeExec("UPDATE grabber_jobs SET
                            status = 'COMPLETED',
                            finished_at = " . $now . ",
                            video_id = " . intval($videoId) . ",
                            error_code = '',
                            error_message = '',
                            updated_at = " . $now . "
                            WHERE id = " . intval($jobId) . " LIMIT 1");

        // Update discovered video
        $rs = $this->safeExec("SELECT discovered_video_id FROM grabber_jobs WHERE id = " . intval($jobId) . " LIMIT 1");
        if ($rs && $rs->fields['discovered_video_id']) {
            $discMgr = new DiscoveryManager();
            $discMgr->updateStatus(intval($rs->fields['discovered_video_id']), 'IMPORTED', $videoId);
        }
    }

    /**
     * Mark a job as failed and schedule retry.
     * @param int    $jobId
     * @param string $errorCode
     * @param string $errorMessage
     */
    public function fail($jobId, $errorCode, $errorMessage) {
        $now = time();
        $rs = $this->safeExec("SELECT attempts, max_attempts FROM grabber_jobs WHERE id = " . intval($jobId) . " LIMIT 1");
        if (!$rs || $rs->EOF) return;

        $attempts = intval($rs->fields['attempts']);
        $maxAttempts = intval($rs->fields['max_attempts']);

        if ($attempts >= $maxAttempts) {
            // Final failure
            $this->safeExec("UPDATE grabber_jobs SET
                                status = 'FAILED',
                                finished_at = " . $now . ",
                                error_code = " . $this->db->qStr($errorCode) . ",
                                error_message = " . $this->db->qStr(substr($errorMessage, 0, 2000)) . ",
                                updated_at = " . $now . "
                                WHERE id = " . intval($jobId) . " LIMIT 1");

            // Update discovered video
            $rs2 = $this->safeExec("SELECT discovered_video_id FROM grabber_jobs WHERE id = " . intval($jobId) . " LIMIT 1");
            if ($rs2 && $rs2->fields['discovered_video_id']) {
                $discMgr = new DiscoveryManager();
                $discMgr->updateStatus(intval($rs2->fields['discovered_video_id']), 'FAILED');
            }
        } else {
            // Retry with backoff: attempt 1->2 = 5min, 2->3 = 30min
            $delay = ($attempts <= 1) ? 300 : 1800;
            $this->safeExec("UPDATE grabber_jobs SET
                                status = 'PENDING',
                                scheduled_at = " . ($now + $delay) . ",
                                error_code = " . $this->db->qStr($errorCode) . ",
                                error_message = " . $this->db->qStr(substr($errorMessage, 0, 2000)) . ",
                                updated_at = " . $now . "
                                WHERE id = " . intval($jobId) . " LIMIT 1");
        }
    }

    /**
     * Cancel a job.
     * @param int $jobId
     */
    public function cancel($jobId) {
        $now = time();
        $this->safeExec("UPDATE grabber_jobs SET
                            status = 'CANCELLED',
                            finished_at = " . $now . ",
                            updated_at = " . $now . "
                            WHERE id = " . intval($jobId) . " AND status IN ('PENDING', 'PROCESSING') LIMIT 1");

        // Reset discovered video status so it can be re-queued
        $rs = $this->safeExec("SELECT discovered_video_id FROM grabber_jobs WHERE id = " . intval($jobId) . " LIMIT 1");
        if ($rs && $rs->fields['discovered_video_id']) {
            $discMgr = new DiscoveryManager();
            $discMgr->updateStatus(intval($rs->fields['discovered_video_id']), 'NEW');
        }
    }

    /**
     * Pause a job (PENDING/PROCESSING -> PAUSED).
     */
    public function pause($jobId) {
        $now = time();
        $this->safeExec("UPDATE grabber_jobs SET status = 'PAUSED', updated_at = $now
                            WHERE id = " . intval($jobId) . " AND status IN ('PENDING','PROCESSING') LIMIT 1");
    }

    /**
     * Resume a paused job (PAUSED -> PENDING).
     */
    public function resume($jobId) {
        $now = time();
        $this->safeExec("UPDATE grabber_jobs SET status = 'PENDING', scheduled_at = $now, updated_at = $now
                            WHERE id = " . intval($jobId) . " AND status = 'PAUSED' LIMIT 1");
    }

    /**
     * Retry a failed job (FAILED -> PENDING, reset attempts).
     */
    public function retry($jobId) {
        $now = time();
        $this->safeExec("UPDATE grabber_jobs SET status = 'PENDING', attempts = 0, scheduled_at = $now,
                            started_at = 0, worker_pid = 0, error_code = NULL, error_message = '', updated_at = $now
                            WHERE id = " . intval($jobId) . " AND status = 'FAILED' LIMIT 1");
    }

    /**
     * Pause all pending jobs.
     * @return int  Number of jobs paused
     */
    public function pauseAll() {
        $now = time();
        $this->safeExec("UPDATE grabber_jobs SET status = 'PAUSED', updated_at = $now WHERE status = 'PENDING'");
        return $this->db->Affected_Rows();
    }

    /**
     * Resume all paused jobs.
     * @return int  Number of jobs resumed
     */
    public function resumeAll() {
        $now = time();
        $this->safeExec("UPDATE grabber_jobs SET status = 'PENDING', scheduled_at = $now, updated_at = $now WHERE status = 'PAUSED'");
        return $this->db->Affected_Rows();
    }

    /**
     * Reset stale processing jobs (crash recovery).
     * @param int $timeoutSeconds  Default 1800 (30 min)
     * @return int  Number of jobs reset
     */
    public function resetStaleJobs($timeoutSeconds = 1800) {
        $cutoff = time() - $timeoutSeconds;
        // Attempts are reset so a job whose PROCESSING run crashed on its last
        // attempt (attempts == max_attempts) can be claimed again - otherwise
        // it would sit PENDING forever and block the queue.
        $this->safeExec("UPDATE grabber_jobs SET
                            status = 'PENDING',
                            attempts = 0,
                            started_at = 0,
                            worker_pid = 0,
                            scheduled_at = " . time() . ",
                            error_code = '',
                            error_message = '',
                            updated_at = " . time() . "
                            WHERE status = 'PROCESSING'
                            AND started_at < " . intval($cutoff));

        $reset = $this->db->Affected_Rows();

        // Also revive zombie PENDING jobs: a PENDING job whose attempts hit
        // max_attempts can never be claimed again (claimNext requires
        // attempts < max_attempts). Such jobs only exist when a PROCESSING run
        // was reset without clearing attempts - give them a fresh shot.
        $this->safeExec("UPDATE grabber_jobs SET
                            attempts = 0,
                            error_code = '',
                            error_message = '',
                            scheduled_at = " . time() . ",
                            updated_at = " . time() . "
                            WHERE status = 'PENDING'
                            AND attempts >= max_attempts
                            AND scheduled_at <= " . time());
        $reset += $this->db->Affected_Rows();

        return $reset;
    }

    /**
     * Get jobs with optional filters.
     * @param array $filters  ['status', 'source_id', 'run_id']
     * @param int   $limit
     * @param int   $offset
     * @return array ['jobs' => [...], 'total' => int]
     */
    public function getJobs($filters = array(), $limit = 50, $offset = 0) {
        $where = array('1=1');
        if (isset($filters['status'])) {
            $where[] = "j.status = " . $this->db->qStr($filters['status']);
        }
        if (isset($filters['source_id'])) {
            $where[] = "j.source_id = " . intval($filters['source_id']);
        }
        if (isset($filters['run_id'])) {
            $where[] = "j.run_id = " . intval($filters['run_id']);
        }

        $whereSql = implode(' AND ', $where);

        $countRs = $this->safeExec("SELECT COUNT(*) AS c FROM grabber_jobs j WHERE $whereSql");
        $total = $countRs ? intval($countRs->fields['c']) : 0;

        $sql = "SELECT j.*, d.title AS disc_title, d.source_url AS disc_source_url,
                       s.name AS source_name
                FROM grabber_jobs j
                LEFT JOIN grabber_discovered_videos d ON j.discovered_video_id = d.id
                LEFT JOIN grabber_sources s ON j.source_id = s.id
                WHERE $whereSql
                ORDER BY j.created_at DESC
                LIMIT " . intval($limit) . " OFFSET " . intval($offset);

        $rs = $this->safeExec($sql);
        $jobs = array();
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $jobs[] = $rs->fields;
                $rs->MoveNext();
            }
        }

        return array('jobs' => $jobs, 'total' => $total);
    }

    /**
     * Get job status counts.
     * @return array
     */
    public function getStatusCounts() {
        $rs = $this->safeExec("SELECT status, COUNT(*) AS c FROM grabber_jobs GROUP BY status");
        $counts = array('PENDING' => 0, 'PROCESSING' => 0, 'PAUSED' => 0, 'COMPLETED' => 0, 'FAILED' => 0, 'CANCELLED' => 0);
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $counts[$rs->fields['status']] = intval($rs->fields['c']);
                $rs->MoveNext();
            }
        }
        return $counts;
    }

    /**
     * Count active (PENDING + PROCESSING) grab jobs.
     * @return int
     */
    public function activeCount() {
        $rs = $this->safeExec("SELECT COUNT(*) AS c FROM grabber_jobs
                                  WHERE status = 'PROCESSING'");
        return $rs ? intval($rs->fields['c']) : 0;
    }

    /**
     * Get a single job by ID.
     * @param int $jobId
     * @return array|null
     */
    public function getById($jobId) {
        $rs = $this->safeExec("SELECT j.*, d.title AS disc_title, d.source_url AS disc_source_url,
                                         d.external_id, d.thumbnail_url AS disc_thumbnail,
                                         s.name AS source_name
                                  FROM grabber_jobs j
                                  LEFT JOIN grabber_discovered_videos d ON j.discovered_video_id = d.id
                                  LEFT JOIN grabber_sources s ON j.source_id = s.id
                                  WHERE j.id = " . intval($jobId) . " LIMIT 1");
        if ($rs && !$rs->EOF) {
            return $rs->fields;
        }
        return null;
    }

    /**
     * Cleanup old jobs beyond retention period.
     * @param int $days  Default 90
     * @return int  Deleted count
     */
    public function cleanup($days = 90) {
        $cutoff = time() - ($days * 86400);
        $this->safeExec("DELETE FROM grabber_jobs
                            WHERE status IN ('COMPLETED', 'FAILED', 'CANCELLED')
                            AND finished_at < " . intval($cutoff));
        return $this->db->Affected_Rows();
    }
}
