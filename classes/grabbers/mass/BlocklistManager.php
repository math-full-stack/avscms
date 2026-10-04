<?php
defined('_VALID') or die('Restricted Access!');

/**
 * BlocklistManager - Global blocklist of rejected videos.
 *
 * One entry = one URL (or external_id) the admin analyzed and does not want.
 * Three enforcement points keep it effective:
 *   1. DedupManager::check()      - the scan treats blocked videos as already
 *                                   known, so they are never inserted again;
 *   2. DiscoveryManager::getDiscovered() - the Discover list filters them out;
 *   3. JobManager::create()       - blocked videos can never be queued.
 * Matching is global (URL/external_id), not per source.
 */
class BlocklistManager {

    const TABLE = 'grabber_blocklist';

    private $db = null;
    private static $tableReady = null;
    private static $index = null;

    public function __construct() {
        global $conn;
        $this->db = $conn;
    }

    private function safeExec($sql) {
        try { return $this->db->Execute($sql); } catch (Exception $e) { return null; } catch (Throwable $e) { return null; }
    }

    /**
     * Normalize a URL. MUST stay in sync with excludeSql() below:
     *   PHP: strtolower(rtrim(trim($url), '/'))
     *   SQL: TRIM(TRAILING '/' FROM LOWER(TRIM(col)))
     */
    public static function normalizeUrl($url) {
        $url = strtolower(trim((string) $url));
        if ($url !== '') {
            $url = rtrim($url, '/');
        }
        return $url;
    }

    public static function hashUrl($url) {
        $norm = self::normalizeUrl($url);
        return $norm === '' ? '' : md5($norm);
    }

    public function tableExists() {
        if (self::$tableReady === null) {
            $rs = $this->safeExec("SHOW TABLES LIKE '" . self::TABLE . "'");
            self::$tableReady = ($rs && !$rs->EOF);
        }
        return self::$tableReady;
    }

    /**
     * SQL fragment hiding every blocked row from a query on $alias.
     * Returns '' until the migration has created the table, so a missing
     * table degrades to "nothing blocked" instead of a SQL error.
     *
     * @param  string $alias  table alias used by the caller (must expose
     *                        canonical_url, source_url, external_id)
     * @return string
     */
    public function excludeSql($alias = 'd') {
        if (!$this->tableExists()) return '';
        $cacheKey = preg_replace('/[^a-z0-9_]/i', '', $alias);
        if ($cacheKey === '') $cacheKey = 'd';
        static $cache = array();
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];

        $cache[$cacheKey] = " AND NOT EXISTS (
                SELECT 1 FROM " . self::TABLE . " bx
                WHERE bx.url_hash IN (
                      MD5(TRIM(TRAILING '/' FROM LOWER(TRIM({$cacheKey}.canonical_url))))
                    , MD5(TRIM(TRAILING '/' FROM LOWER(TRIM({$cacheKey}.source_url))))
                )
                OR (bx.external_id <> '' AND bx.external_id = {$cacheKey}.external_id)
            )";
        return $cache[$cacheKey];
    }

    /**
     * Full blocklist loaded once per request (admin-curated, small table).
     * @return array ['hash' => [md5 => id], 'ext' => [external_id => id]]
     */
    private function index() {
        if (self::$index !== null) return self::$index;
        self::$index = array('hash' => array(), 'ext' => array());
        if (!$this->tableExists()) return self::$index;

        $rs = $this->safeExec("SELECT id, url_hash, external_id FROM " . self::TABLE);
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                if ($rs->fields['url_hash'] !== '') {
                    self::$index['hash'][$rs->fields['url_hash']] = intval($rs->fields['id']);
                }
                if ($rs->fields['external_id'] !== '') {
                    self::$index['ext'][$rs->fields['external_id']] = intval($rs->fields['id']);
                }
                $rs->MoveNext();
            }
        }
        return self::$index;
    }

    /** Drop the cached index (after add/remove in this request). */
    public function invalidate() {
        self::$index = null;
    }

    /**
     * Is this provider video on the blocklist?
     * @param  array $video  needs canonical_url/source_url/external_id
     * @return int  blocklist id, or 0 when not blocked
     */
    public function matchVideo($video) {
        $idx = $this->index();
        if (empty($idx['hash']) && empty($idx['ext'])) return 0;

        foreach (array('canonical_url', 'source_url') as $key) {
            if (empty($video[$key])) continue;
            $h = self::hashUrl($video[$key]);
            if ($h !== '' && isset($idx['hash'][$h])) return intval($idx['hash'][$h]);
        }
        if (!empty($video['external_id']) && isset($idx['ext'][$video['external_id']])) {
            return intval($idx['ext'][$video['external_id']]);
        }
        return 0;
    }

    /**
     * Is a discovered row blocked (by its URL or external_id)?
     * @param  int $discoveredId
     * @return bool
     */
    public function isDiscoveredBlocked($discoveredId) {
        if ($discoveredId <= 0 || !$this->tableExists()) return false;
        $rs = $this->safeExec("SELECT canonical_url, source_url, external_id
                                FROM grabber_discovered_videos
                                WHERE id = " . intval($discoveredId) . " LIMIT 1");
        if (!$rs || $rs->EOF) return false;
        return $this->matchVideo($rs->fields) > 0;
    }

    /**
     * Add an entry.
     *
     * @param  array $data ['url','external_id','title','reason','source_id','discovered_id']
     * @return int  new id, or 0 when nothing was added (duplicate/missing table)
     */
    public function add($data) {
        if (!$this->tableExists()) return 0;

        $raw = trim(isset($data['url']) ? $data['url'] : '');
        $url = self::normalizeUrl($raw);
        $ext = trim(isset($data['external_id']) ? $data['external_id'] : '');
        $hash = ($url !== '') ? md5($url) : '';
        if ($hash === '' && $ext !== '') {
            // No URL to key on: hash the external id so the UNIQUE key still
            // rejects duplicates (it can never collide with a URL hash).
            $hash = md5('ext:' . $ext);
        }
        if ($hash === '') return 0;

        // Already blocked?
        $idx = $this->index();
        if (isset($idx['hash'][$hash])) return 0;
        if ($ext !== '' && isset($idx['ext'][$ext])) return 0;

        // The stored URL keeps the original casing (display only) - matching
        // always goes through url_hash.
        $sql = "INSERT INTO " . self::TABLE . " SET
                url = " . $this->db->qStr(substr($raw, 0, 500)) . ",
                url_hash = " . $this->db->qStr($hash) . ",
                external_id = " . $this->db->qStr(substr($ext, 0, 255)) . ",
                title = " . $this->db->qStr(substr(isset($data['title']) ? $data['title'] : '', 0, 500)) . ",
                reason = " . $this->db->qStr(substr(isset($data['reason']) ? $data['reason'] : '', 0, 60)) . ",
                source_id = " . intval(isset($data['source_id']) ? $data['source_id'] : 0) . ",
                discovered_id = " . intval(isset($data['discovered_id']) ? $data['discovered_id'] : 0) . ",
                created_at = " . time();

        $this->safeExec($sql);

        // Read back by hash instead of trusting Insert_ID: a rejected INSERT
        // would otherwise return a stale id from an earlier query.
        $rs = $this->safeExec("SELECT id FROM " . self::TABLE . "
                                WHERE url_hash = " . $this->db->qStr($hash) . " LIMIT 1");
        if (!$rs || $rs->EOF) return 0;

        $id = intval($rs->fields['id']);
        $this->invalidate();
        return $id;
    }

    /**
     * Remove an entry (the video becomes visible/queueable again).
     * @param  int $id
     * @return bool
     */
    public function remove($id) {
        if ($id <= 0 || !$this->tableExists()) return false;
        $this->safeExec("DELETE FROM " . self::TABLE . " WHERE id = " . intval($id) . " LIMIT 1");
        if ($this->db->Affected_Rows() < 1) return false;
        $this->invalidate();
        return true;
    }

    public function count($q = '') {
        if (!$this->tableExists()) return 0;
        $rs = $this->safeExec("SELECT COUNT(*) AS c FROM " . self::TABLE . " b" . $this->searchWhere($q));
        return $rs ? intval($rs->fields['c']) : 0;
    }

    /**
     * Paginated listing for the Blocklist admin tab.
     * @param  array $filters ['q' => string]
     * @return array ['items' => array, 'total' => int]
     */
    public function getAll($filters = array(), $limit = 20, $offset = 0) {
        $q = isset($filters['q']) ? trim($filters['q']) : '';
        $items = array();
        $total = $this->count($q);
        if ($total <= 0 || !$this->tableExists()) {
            return array('items' => $items, 'total' => $total);
        }

        $sql = "SELECT b.*, s.name AS source_name
                FROM " . self::TABLE . " b
                LEFT JOIN grabber_sources s ON s.id = b.source_id
                " . $this->searchWhere($q) . "
                ORDER BY b.id DESC
                LIMIT " . intval($limit) . " OFFSET " . intval($offset);
        $rs = $this->safeExec($sql);
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $items[] = $rs->fields;
                $rs->MoveNext();
            }
        }
        return array('items' => $items, 'total' => $total);
    }

    private function searchWhere($q) {
        if ($q === '') return '';
        $like = $this->db->qStr('%' . $q . '%');
        return " WHERE (b.title LIKE " . $like . " OR b.url LIKE " . $like . " OR b.external_id LIKE " . $like . ")";
    }
}
