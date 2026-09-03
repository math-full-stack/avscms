<?php
defined('_VALID') or die('Restricted Access!');

/**
 * Logger - Centralized logging for the Mass Video Grabber system.
 */
class Logger {

    private $db = null;

    public function __construct() {
        global $conn;
        $this->db = $conn;
    }

    private function safeExec($sql) {
        try { return $this->db->Execute($sql); } catch (Exception $e) { return null; } catch (Throwable $e) { return null; }
    }

    /**
     * Write a log entry.
     *
     * @param int    $runId
     * @param int    $jobId
     * @param int    $sourceId
     * @param string $level    DEBUG|INFO|WARNING|ERROR
     * @param string $event    Event code (e.g. SCAN_STARTED, JOB_FAILED)
     * @param string $message  Human-readable message
     * @param mixed  $context  Optional context data (will be JSON encoded)
     */
    public function log($runId, $jobId, $sourceId, $level, $event, $message, $context = null) {
        $contextStr = '';
        if ($context !== null) {
            $contextStr = is_string($context) ? $context : json_encode($context);
        }

        $sql = "INSERT INTO grabber_logs SET
                run_id = " . intval($runId) . ",
                job_id = " . intval($jobId) . ",
                source_id = " . intval($sourceId) . ",
                level = " . $this->db->qStr($level) . ",
                event = " . $this->db->qStr($event) . ",
                message = " . $this->db->qStr(substr($message, 0, 5000)) . ",
                context = " . $this->db->qStr(substr($contextStr, 0, 5000)) . ",
                created_at = " . time();

        $this->safeExec($sql);
    }

    /**
     * Convenience methods for each log level.
     */
    public function debug($runId, $jobId, $sourceId, $event, $message, $context = null) {
        $this->log($runId, $jobId, $sourceId, 'DEBUG', $event, $message, $context);
    }

    public function info($runId, $jobId, $sourceId, $event, $message, $context = null) {
        $this->log($runId, $jobId, $sourceId, 'INFO', $event, $message, $context);
    }

    public function warning($runId, $jobId, $sourceId, $event, $message, $context = null) {
        $this->log($runId, $jobId, $sourceId, 'WARNING', $event, $message, $context);
    }

    public function error($runId, $jobId, $sourceId, $event, $message, $context = null) {
        $this->log($runId, $jobId, $sourceId, 'ERROR', $event, $message, $context);
    }

    /**
     * Get logs with filters.
     * @param array $filters  ['run_id', 'job_id', 'source_id', 'level']
     * @param int   $limit
     * @param int   $offset
     * @return array ['logs' => [...], 'total' => int]
     */
    public function getLogs($filters = array(), $limit = 100, $offset = 0) {
        $where = array('1=1');
        if (isset($filters['run_id'])) {
            $where[] = "l.run_id = " . intval($filters['run_id']);
        }
        if (isset($filters['job_id'])) {
            $where[] = "l.job_id = " . intval($filters['job_id']);
        }
        if (isset($filters['source_id'])) {
            $where[] = "l.source_id = " . intval($filters['source_id']);
        }
        if (isset($filters['level'])) {
            $where[] = "l.level = " . $this->db->qStr($filters['level']);
        }

        $whereSql = implode(' AND ', $where);

        $countRs = $this->safeExec("SELECT COUNT(*) AS c FROM grabber_logs l WHERE $whereSql");
        $total = $countRs ? intval($countRs->fields['c']) : 0;

        $rs = $this->safeExec("SELECT l.*, s.name AS source_name
                                  FROM grabber_logs l
                                  LEFT JOIN grabber_sources s ON l.source_id = s.id
                                  WHERE $whereSql
                                  ORDER BY l.id DESC
                                  LIMIT " . intval($limit) . " OFFSET " . intval($offset));
        $logs = array();
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $logs[] = $rs->fields;
                $rs->MoveNext();
            }
        }

        return array('logs' => $logs, 'total' => $total);
    }

    /**
     * Get logs for a specific job (timeline view).
     * @param int $jobId
     * @return array
     */
    public function getJobLogs($jobId) {
        $rs = $this->safeExec("SELECT l.*, s.name AS source_name
                                  FROM grabber_logs l
                                  LEFT JOIN grabber_sources s ON l.source_id = s.id
                                  WHERE l.job_id = " . intval($jobId) . "
                                  ORDER BY l.id ASC");
        $logs = array();
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $logs[] = $rs->fields;
                $rs->MoveNext();
            }
        }
        return $logs;
    }

    /**
     * Cleanup old logs.
     * @param int $days  Default 30
     * @return int  Deleted count
     */
    public function cleanup($days = 30) {
        $cutoff = time() - ($days * 86400);
        $this->safeExec("DELETE FROM grabber_logs WHERE created_at < " . intval($cutoff));
        return $this->db->Affected_Rows();
    }
}
