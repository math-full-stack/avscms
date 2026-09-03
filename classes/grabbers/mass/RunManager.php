<?php
defined('_VALID') or die('Restricted Access!');

/**
 * RunManager - Manages discovery/grab run records.
 */
class RunManager {

    private $db = null;

    public function __construct() {
        global $conn;
        $this->db = $conn;
    }

    private function safeExec($sql) {
        try { return $this->db->Execute($sql); } catch (Exception $e) { return null; } catch (Throwable $e) { return null; }
    }

    /**
     * Create a new run record.
     * @param int    $sourceId
     * @param string $runType   MANUAL|AUTOMATIC
     * @return int   Run ID
     */
    public function create($sourceId, $runType = 'MANUAL') {
        $now = time();
        $sql = "INSERT INTO grabber_runs SET
                source_id = " . intval($sourceId) . ",
                run_type = " . $this->db->qStr($runType) . ",
                status = 'RUNNING',
                started_at = " . $now . ",
                created_at = " . $now;

        $this->safeExec($sql);
        return intval($this->db->Insert_ID());
    }

    /**
     * Update a run record.
     * @param int   $runId
     * @param array $data  Fields to update
     */
    public function update($runId, $data) {
        $sets = array();
        $intFields = array('source_id', 'started_at', 'finished_at', 'found_count',
                          'new_count', 'existing_count', 'queued_count',
                          'imported_count', 'failed_count');
        $strFields = array('status', 'run_type', 'error_message');

        foreach ($intFields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = $f . " = " . intval($data[$f]);
            }
        }
        foreach ($strFields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = $f . " = " . $this->db->qStr($data[$f]);
            }
        }

        if (!empty($sets)) {
            $this->safeExec("UPDATE grabber_runs SET " . implode(', ', $sets) . "
                                WHERE id = " . intval($runId) . " LIMIT 1");
        }
    }

    /**
     * Get a run by ID.
     * @param int $runId
     * @return array|null
     */
    public function getById($runId) {
        $rs = $this->safeExec("SELECT r.*, s.name AS source_name
                                  FROM grabber_runs r
                                  LEFT JOIN grabber_sources s ON r.source_id = s.id
                                  WHERE r.id = " . intval($runId) . " LIMIT 1");
        if ($rs && !$rs->EOF) {
            return $rs->fields;
        }
        return null;
    }

    /**
     * Get runs for a source.
     * @param int $sourceId
     * @param int $limit
     * @return array
     */
    public function getBySource($sourceId, $limit = 20) {
        $rs = $this->safeExec("SELECT r.*, s.name AS source_name
                                  FROM grabber_runs r
                                  LEFT JOIN grabber_sources s ON r.source_id = s.id
                                  WHERE r.source_id = " . intval($sourceId) . "
                                  ORDER BY r.id DESC
                                  LIMIT " . intval($limit));
        $runs = array();
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $runs[] = $rs->fields;
                $rs->MoveNext();
            }
        }
        return $runs;
    }

    /**
     * Get all runs with pagination.
     * @param array $filters  ['source_id' => int]
     * @param int   $limit
     * @param int   $offset
     * @return array ['runs' => [...], 'total' => int]
     */
    public function getAll($filters = array(), $limit = 30, $offset = 0) {
        $where = array('1=1');
        if (isset($filters['source_id'])) {
            $where[] = "r.source_id = " . intval($filters['source_id']);
        }
        $whereSql = implode(' AND ', $where);

        $countRs = $this->safeExec("SELECT COUNT(*) AS c FROM grabber_runs r WHERE $whereSql");
        $total = $countRs ? intval($countRs->fields['c']) : 0;

        $rs = $this->safeExec("SELECT r.*, s.name AS source_name
                                  FROM grabber_runs r
                                  LEFT JOIN grabber_sources s ON r.source_id = s.id
                                  WHERE $whereSql
                                  ORDER BY r.id DESC
                                  LIMIT " . intval($limit) . " OFFSET " . intval($offset));
        $runs = array();
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $runs[] = $rs->fields;
                $rs->MoveNext();
            }
        }

        return array('runs' => $runs, 'total' => $total);
    }

    /**
     * Check whether a source currently has an active (RUNNING) scan.
     * @param int $sourceId
     * @return bool
     */
    public function hasActiveRun($sourceId) {
        $rs = $this->safeExec("SELECT id FROM grabber_runs
                                  WHERE source_id = " . intval($sourceId) . "
                                  AND status = 'RUNNING' LIMIT 1");
        return ($rs && $rs->RecordCount() > 0);
    }

    /**
     * Whether a run has recent heartbeat activity (grabber_logs written while
     * the background scan is actually working, page by page).
     * @param int $runId
     * @param int $window Seconds (default 600 = 10 min)
     * @return bool
     */
    public function isAlive($runId, $window = 600) {
        $rs = $this->safeExec("SELECT id FROM grabber_logs
                                  WHERE run_id = " . intval($runId) . "
                                  AND created_at > " . (time() - intval($window)) . "
                                  LIMIT 1");
        return ($rs && $rs->RecordCount() > 0);
    }

    /**
     * Fail runs stuck in RUNNING for longer than $maxAge seconds with NO
     * recent activity (crashed/abandoned background processes). A run whose
     * process is still alive and logging (long full-catalog refresh scans can
     * legitimately run for over an hour) is never failed here.
     * @param int $maxAge Seconds (default 1800 = 30 min)
     * @param int $heartbeat Seconds without a log entry that means dead (default 600)
     * @return int  Number of runs failed
     */
    public function failStaleRuns($maxAge = 1800, $heartbeat = 600) {
        $cutoff = time() - max(60, intval($maxAge));
        $beatCutoff = time() - max(60, intval($heartbeat));
        $this->safeExec("UPDATE grabber_runs r
                            LEFT JOIN (
                                SELECT run_id, MAX(created_at) AS last_log
                                FROM grabber_logs WHERE run_id > 0 GROUP BY run_id
                            ) l ON l.run_id = r.id
                            SET r.status = 'FAILED', r.finished_at = " . time() . ",
                                r.error_message = 'Stale run timed out'
                          WHERE r.status = 'RUNNING'
                            AND r.started_at < " . intval($cutoff) . "
                            AND (l.last_log IS NULL OR l.last_log < " . intval($beatCutoff) . ")");
        return intval($this->db->Affected_Rows());
    }

    /**
     * Cleanup old runs.
     * @param int $days  Default 90
     * @return int  Deleted count
     */
    public function cleanup($days = 90) {
        $cutoff = time() - ($days * 86400);
        $this->safeExec("DELETE FROM grabber_runs WHERE started_at < " . intval($cutoff));
        return $this->db->Affected_Rows();
    }
}
