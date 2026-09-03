<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/interfaces/DiscoveryProvider.php';
require_once dirname(__FILE__) . '/SourceManager.php';
require_once dirname(__FILE__) . '/DiscoveryManager.php';
require_once dirname(__FILE__) . '/DedupManager.php';
require_once dirname(__FILE__) . '/JobManager.php';
require_once dirname(__FILE__) . '/RunManager.php';
require_once dirname(__FILE__) . '/Logger.php';
require_once dirname(__FILE__) . '/Scheduler.php';

/**
 * MassGrabberManager - Central coordinator for the Mass Video Grabber system.
 *
 * Orchestrates Sources, Discovery, Dedup, Queue, and Scheduler.
 */
class MassGrabberManager {

    private static $providers = null;
    private static $db = null;
    private static $tablesReady = null;

    private static function db() {
        if (self::$db === null) {
            global $conn;
            self::$db = $conn;
        }
        return self::$db;
    }

    /**
     * Check if the mass grabber tables exist in the database.
     * @return bool
     */
    public static function tablesExist() {
        if (self::$tablesReady !== null) {
            return self::$tablesReady;
        }
        $db = self::db();
        $rs = $db->Execute("SHOW TABLES LIKE 'grabber_sources'");
        self::$tablesReady = ($rs && $rs->RecordCount() > 0);
        return self::$tablesReady;
    }

    /**
     * Execute a query safely, returning null on failure (e.g. missing table).
     * @param string $sql
     * @return ADORecordSet|null
     */
    private static function safeQuery($sql) {
        $db = self::db();
        try {
            return $db->Execute($sql);
        } catch (Exception $e) {
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // -------------------------------------------------------
    // Provider registry
    // -------------------------------------------------------

    /**
     * Initialize and register all discovery providers.
     */
    private static function initProviders() {
        if (self::$providers !== null) {
            return;
        }
        self::$providers = array();

        // Auto-load provider classes from providers/ directory
        $providerDir = dirname(__FILE__) . '/providers';
        if (is_dir($providerDir)) {
            $files = glob($providerDir . '/*.php');
            foreach ($files as $file) {
                $className = basename($file, '.php');
                require_once $file;
                if (class_exists($className)) {
                    $provider = new $className();
                    if ($provider instanceof DiscoveryProvider) {
                        self::$providers[] = $provider;
                    }
                }
            }
        }
    }

    /**
     * Get the discovery provider for a URL.
     * @param string $url
     * @return DiscoveryProvider|null
     */
    public static function getDiscoveryProviderForUrl($url) {
        self::initProviders();
        foreach (self::$providers as $provider) {
            if ($provider->canDiscover($url)) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Get all registered providers with their capabilities.
     * @return array
     */
    public static function getProviders() {
        self::initProviders();
        $result = array();
        foreach (self::$providers as $provider) {
            $result[] = array(
                'name'     => $provider->getSiteName(),
                'grab'     => $provider->supportsGrab(),
                'metadata' => $provider->supportsMetadata(),
                'versions' => $provider->supportsVersions(),
                'discover' => true,
            );
        }
        return $result;
    }

    /**
     * Get a specific provider by site name.
     * @param string $name
     * @return DiscoveryProvider|null
     */
    public static function getProviderByName($name) {
        self::initProviders();
        $nameLower = strtolower($name);
        foreach (self::$providers as $provider) {
            if (strtolower($provider->getSiteName()) === $nameLower) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Get supported sites with capabilities (for the admin UI).
     * @return array
     */
    public static function getSupportedSites() {
        $sites = array();
        $providers = self::getProviders();
        foreach ($providers as $p) {
            $caps = array();
            if ($p['grab']) $caps[] = 'Grab';
            if ($p['metadata']) $caps[] = 'Metadata';
            if ($p['discover']) $caps[] = 'Discovery';
            if ($p['versions']) $caps[] = 'Versions';
            $sites[] = array(
                'name'       => $p['name'],
                'capabilities' => $caps,
            );
        }
        return $sites;
    }

    // -------------------------------------------------------
    // Convenience accessors for sub-managers
    // -------------------------------------------------------

    public static function sources() {
        return new SourceManager();
    }

    public static function discovery() {
        return new DiscoveryManager();
    }

    public static function dedup() {
        return new DedupManager();
    }

    public static function jobs() {
        return new JobManager();
    }

    public static function runs() {
        return new RunManager();
    }

    public static function logger() {
        return new Logger();
    }

    public static function scheduler() {
        return new Scheduler();
    }

    // -------------------------------------------------------
    // Dashboard statistics
    // -------------------------------------------------------

    /**
     * Get dashboard statistics.
     * @return array
     */
    public static function getDashboardStats() {
        $stats = array(
            'sources'       => 0,
            'discovered'    => 0,
            'new_videos'    => 0,
            'existing'      => 0,
            'queued'        => 0,
            'processing'    => 0,
            'completed'     => 0,
            'failed'        => 0,
            'imported'      => 0,
            'last_run'      => 0,
            'last_run_text' => 'Never',
        );

        if (!self::tablesExist()) return $stats;

        // Source count
        $rs = self::safeQuery("SELECT COUNT(*) AS c FROM grabber_sources WHERE enabled = 1");
        if ($rs) $stats['sources'] = intval($rs->fields['c']);

        // Discovered videos count
        $rs = self::safeQuery("SELECT COUNT(*) AS c FROM grabber_discovered_videos");
        if ($rs) $stats['discovered'] = intval($rs->fields['c']);

        // Status breakdown
        $rs = self::safeQuery("SELECT status, COUNT(*) AS c FROM grabber_discovered_videos GROUP BY status");
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $s = $rs->fields['status'];
                $c = intval($rs->fields['c']);
                switch ($s) {
                    case 'NEW': $stats['new_videos'] = $c; break;
                    case 'EXISTS': $stats['existing'] = $c; break;
                    case 'QUEUED': $stats['queued'] = $c; break;
                    case 'PROCESSING': $stats['processing'] = $c; break;
                    case 'IMPORTED': $stats['imported'] = $c; break;
                    case 'FAILED': $stats['failed'] = $c; break;
                }
                $rs->MoveNext();
            }
        }

        // Job status breakdown
        $rs = self::safeQuery("SELECT status, COUNT(*) AS c FROM grabber_jobs GROUP BY status");
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $s = $rs->fields['status'];
                $c = intval($rs->fields['c']);
                switch ($s) {
                    case 'PENDING': $stats['queued'] = $c; break;
                    case 'PROCESSING': $stats['processing'] = $c; break;
                    case 'COMPLETED': $stats['completed'] = $c; break;
                    case 'FAILED': $stats['failed'] += $c; break;
                }
                $rs->MoveNext();
            }
        }

        // Last run
        $rs = self::safeQuery("SELECT id, finished_at FROM grabber_runs ORDER BY id DESC LIMIT 1");
        if ($rs && $rs->RecordCount() > 0 && $rs->fields['id']) {
            $stats['last_run'] = intval($rs->fields['finished_at']);
            $diff = time() - $stats['last_run'];
            if ($diff < 60) {
                $stats['last_run_text'] = $diff . ' sec ago';
            } elseif ($diff < 3600) {
                $stats['last_run_text'] = floor($diff / 60) . ' min ago';
            } elseif ($diff < 86400) {
                $stats['last_run_text'] = floor($diff / 3600) . ' hours ago';
            } else {
                $stats['last_run_text'] = floor($diff / 86400) . ' days ago';
            }
        }

        return $stats;
    }

    /**
     * Get recent run history for the dashboard.
     * @param int $limit
     * @return array
     */
    public static function getRunHistory($limit = 20) {
        if (!self::tablesExist()) return array();
        $runs = array();
        $rs = self::safeQuery("SELECT r.*, s.name AS source_name
                            FROM grabber_runs r
                            LEFT JOIN grabber_sources s ON r.source_id = s.id
                            ORDER BY r.id DESC LIMIT " . intval($limit));
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $runs[] = $rs->fields;
                $rs->MoveNext();
            }
        }
        return $runs;
    }

    /**
     * Get the last 7 days statistics (videos per day).
     * @return array
     */
    public static function getWeeklyStats() {
        if (!self::tablesExist()) return array();
        $stats = array();
        $since = time() - (7 * 86400);
        $rs = self::safeQuery("SELECT DATE(FROM_UNIXTIME(created_at)) AS day, COUNT(*) AS c
                            FROM grabber_discovered_videos
                            WHERE created_at >= " . intval($since) . "
                            GROUP BY day ORDER BY day ASC");
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $stats[] = array('day' => $rs->fields['day'], 'count' => intval($rs->fields['c']));
                $rs->MoveNext();
            }
        }
        return $stats;
    }

    // -------------------------------------------------------
    // Source lock
    // -------------------------------------------------------

    /**
     * Try to acquire a source lock (prevent concurrent discovery on same source).
     * @param int $sourceId
     * @return bool
     */
    public static function acquireSourceLock($sourceId) {
        if (!self::tablesExist()) return false;

        // ALWAYS force-finish any running scans for this source
        self::safeQuery("UPDATE grabber_runs SET status = 'FAILED', finished_at = " . time() . "
                        WHERE source_id = " . intval($sourceId) . "
                        AND status = 'RUNNING'");

        return true;
    }

    /**
     * Release source lock by finishing any running runs for a source.
     * @param int $sourceId
     * @param string $status  FINISHED|FAILED
     */
    public static function releaseSourceLock($sourceId, $status = 'FINISHED') {
        if (!self::tablesExist()) return;
        $db = self::db();
        self::safeQuery("UPDATE grabber_runs SET status = '" . $db->qStr($status) . "',
                      finished_at = " . time() . "
                      WHERE source_id = " . intval($sourceId) . "
                      AND status = 'RUNNING'");
    }
}
