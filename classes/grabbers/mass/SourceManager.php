<?php
defined('_VALID') or die('Restricted Access!');

/**
 * SourceManager - CRUD + operations for mass grabber sources.
 */
class SourceManager {

    private $db = null;

    public function __construct() {
        global $conn;
        $this->db = $conn;
    }

    /**
     * Execute query safely, return null on table-missing errors.
     */
    private function safeExec($sql) {
        try {
            return $this->db->Execute($sql);
        } catch (Exception $e) {
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // -------------------------------------------------------
    // CRUD
    // -------------------------------------------------------

    /**
     * Create a new source.
     * @param array $data
     * @return int  New source ID, or 0 on failure
     */
    public function create($data) {
        $now = time();
        $sql = "INSERT INTO grabber_sources SET
                name = " . $this->db->qStr($data['name']) . ",
                slug = " . $this->db->qStr($this->makeSlug($data['name'])) . ",
                domain = " . $this->db->qStr(isset($data['domain']) ? $data['domain'] : '') . ",
                provider = " . $this->db->qStr($data['provider']) . ",
                enabled = " . intval(isset($data['enabled']) ? $data['enabled'] : 1) . ",
                automatic_enabled = " . intval(isset($data['automatic_enabled']) ? $data['automatic_enabled'] : 0) . ",
                discovery_enabled = " . intval(isset($data['discovery_enabled']) ? $data['discovery_enabled'] : 1) . ",
                discovery_url = " . $this->db->qStr($data['discovery_url']) . ",
                category_id = " . intval(isset($data['category_id']) ? $data['category_id'] : 0) . ",
                quality = " . $this->db->qStr(isset($data['quality']) ? $data['quality'] : 'best') . ",
                max_per_run = " . intval(isset($data['max_per_run']) ? $data['max_per_run'] : 5) . ",
                max_pages = " . intval(isset($data['max_pages']) ? $data['max_pages'] : 3) . ",
                schedule_type = " . $this->db->qStr(isset($data['schedule_type']) ? $data['schedule_type'] : 'daily') . ",
                schedule_value = " . $this->db->qStr(isset($data['schedule_value']) ? $data['schedule_value'] : '02:00') . ",
                next_run_at = " . intval(isset($data['next_run_at']) ? $data['next_run_at'] : 0) . ",
                requests_per_minute = " . intval(isset($data['requests_per_minute']) ? $data['requests_per_minute'] : 30) . ",
                concurrency = " . intval(isset($data['concurrency']) ? $data['concurrency'] : 2) . ",
                delay_seconds = " . intval(isset($data['delay_seconds']) ? $data['delay_seconds'] : 1) . ",
                last_error = '',
                error_count = 0,
                created_at = " . $now . ",
                updated_at = " . $now;

        $this->safeExec($sql);
        return intval($this->db->Insert_ID());
    }

    /**
     * Update an existing source.
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public function update($id, $data) {
        $sets = array();
        $fields = array('name', 'domain', 'provider', 'discovery_url', 'quality',
                        'max_per_run', 'max_pages', 'schedule_type', 'schedule_value',
                        'requests_per_minute', 'concurrency', 'delay_seconds');

        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = $f . " = " . $this->db->qStr($data[$f]);
            }
        }

        $intFields = array('enabled', 'automatic_enabled', 'discovery_enabled',
                           'category_id', 'next_run_at');
        foreach ($intFields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = $f . " = " . intval($data[$f]);
            }
        }

        if (empty($sets)) return false;

        $sets[] = "updated_at = " . time();

        $sql = "UPDATE grabber_sources SET " . implode(', ', $sets) . " WHERE id = " . intval($id) . " LIMIT 1";
        $this->safeExec($sql);
        return true;
    }

    /**
     * Delete a source and its related data.
     * @param int $id
     * @return bool
     */
    public function delete($id) {
        $id = intval($id);
        $this->safeExec("DELETE FROM grabber_sources WHERE id = $id LIMIT 1");
        $this->safeExec("UPDATE grabber_discovered_videos SET status = 'SKIPPED' WHERE source_id = $id");
        $this->safeExec("DELETE FROM grabber_jobs WHERE source_id = $id");
        $this->safeExec("DELETE FROM grabber_logs WHERE source_id = $id");
        $this->safeExec("DELETE FROM grabber_runs WHERE source_id = $id");
        return true;
    }

    /**
     * Get a single source by ID.
     * @param int $id
     * @return array|null
     */
    public function getById($id) {
        $rs = $this->safeExec("SELECT * FROM grabber_sources WHERE id = " . intval($id) . " LIMIT 1");
        if ($rs && !$rs->EOF) {
            return $rs->fields;
        }
        return null;
    }

    /**
     * Get all sources, optionally filtered.
     * @param array $filters  ['enabled' => 0|1, 'provider' => string]
     * @return array
     */
    public function getAll($filters = array()) {
        $where = array('1=1');
        if (isset($filters['enabled'])) {
            $where[] = "enabled = " . intval($filters['enabled']);
        }
        if (isset($filters['provider'])) {
            $where[] = "provider = " . $this->db->qStr($filters['provider']);
        }
        $sql = "SELECT * FROM grabber_sources WHERE " . implode(' AND ', $where) . " ORDER BY name ASC";
        $rs = $this->safeExec($sql);
        $result = array();
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $result[] = $rs->fields;
                $rs->MoveNext();
            }
        }
        return $result;
    }

    /**
     * Get sources that are due for automatic execution.
     * @return array
     */
    public function getDueSources() {
        $now = time();
        $rs = $this->safeExec("SELECT * FROM grabber_sources
                                  WHERE enabled = 1
                                  AND automatic_enabled = 1
                                  AND discovery_enabled = 1
                                  AND discovery_url != ''
                                  AND next_run_at <= " . intval($now) . "
                                  ORDER BY next_run_at ASC");
        $result = array();
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $result[] = $rs->fields;
                $rs->MoveNext();
            }
        }
        return $result;
    }

    /**
     * Update the next_run_at for a source based on its schedule.
     * @param int $sourceId
     */
    public function updateNextRun($sourceId) {
        $source = $this->getById($sourceId);
        if (!$source) return;

        $nextRun = $this->calculateNextRun($source['schedule_type'], $source['schedule_value']);
        $this->update($sourceId, array(
            'last_run_at' => time(),
            'next_run_at' => $nextRun,
        ));
    }

    /**
     * Calculate next run timestamp based on schedule type/value.
     * @param string $type   daily|hourly|weekly|interval
     * @param string $value  e.g. "02:00", "60", "monday 02:00", "300"
     * @return int  Unix timestamp
     */
    private function calculateNextRun($type, $value) {
        $now = time();
        switch ($type) {
            case 'hourly':
                $minutes = intval($value);
                if ($minutes < 5) $minutes = 60;
                return $now + ($minutes * 60);

            case 'daily':
                $parts = explode(':', $value);
                $hour = isset($parts[0]) ? intval($parts[0]) : 2;
                $min  = isset($parts[1]) ? intval($parts[1]) : 0;
                $next = strtotime('tomorrow ' . str_pad($hour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($min, 2, '0', STR_PAD_LEFT));
                if ($next === false || $next <= $now) {
                    $next = $now + 86400;
                }
                return $next;

            case 'weekly':
                $next = strtotime('next ' . $value);
                if ($next === false || $next <= $now) {
                    $next = $now + (7 * 86400);
                }
                return $next;

            case 'interval':
                $seconds = intval($value);
                if ($seconds < 300) $seconds = 300;
                return $now + $seconds;

            default:
                return $now + 86400;
        }
    }

    /**
     * Update source error state.
     * @param int    $sourceId
     * @param string $error
     */
    public function recordError($sourceId, $error) {
        $this->safeExec("UPDATE grabber_sources SET
                            error_count = error_count + 1,
                            last_error = " . $this->db->qStr($error) . ",
                            updated_at = " . time() . "
                            WHERE id = " . intval($sourceId) . " LIMIT 1");

        // Disable automatic after 10 consecutive errors
        $rs = $this->safeExec("SELECT error_count FROM grabber_sources WHERE id = " . intval($sourceId) . " LIMIT 1");
        if ($rs && intval($rs->fields['error_count']) >= 10) {
            $this->safeExec("UPDATE grabber_sources SET automatic_enabled = 0, health_status = 'FAILED'
                                WHERE id = " . intval($sourceId) . " LIMIT 1");
        } elseif ($rs && intval($rs->fields['error_count']) >= 3) {
            $this->safeExec("UPDATE grabber_sources SET health_status = 'WARNING'
                                WHERE id = " . intval($sourceId) . " LIMIT 1");
        }
    }

    /**
     * Reset source error count on success.
     * @param int $sourceId
     */
    public function recordSuccess($sourceId) {
        $this->safeExec("UPDATE grabber_sources SET
                            error_count = 0,
                            last_error = '',
                            last_success_at = " . time() . ",
                            health_status = 'HEALTHY',
                            updated_at = " . time() . "
                            WHERE id = " . intval($sourceId) . " LIMIT 1");
    }

    /**
     * Toggle source enabled state.
     * @param int $id
     * @return bool  New state
     */
    public function toggle($id) {
        $source = $this->getById($id);
        if (!$source) return false;
        $newState = $source['enabled'] ? 0 : 1;
        $this->update($id, array('enabled' => $newState));
        return (bool)$newState;
    }

    /**
     * Generate a URL-friendly slug from a name.
     * @param string $name
     * @return string
     */
    private function makeSlug($name) {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug;
    }
}
