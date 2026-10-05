<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/BlocklistManager.php';

/**
 * DedupManager - Multi-strategy deduplication for discovered videos.
 *
 * Strategy priority:
 *   0. global blocklist           (rejected videos never come back)
 *   1. source_id + external_id  (primary identity)
 *   2. source_id + canonical_url (normalized URL)
 *   3. video.source_url in AVS (bridge to existing videos)
 */
class DedupManager {

    private $db = null;

    public function __construct() {
        global $conn;
        $this->db = $conn;
    }

    private function safeExec($sql) {
        try { return $this->db->Execute($sql); } catch (Exception $e) { return null; } catch (Throwable $e) { return null; }
    }

    /**
     * Check if a video is a duplicate using multi-strategy dedup.
     *
     * @param int   $sourceId
     * @param array $video  ['external_id', 'source_url', 'canonical_url', 'title']
     * @return array ['is_duplicate' => bool, 'reason' => string, 'discovered_id' => int]
     */
    public function check($sourceId, $video) {
        // Strategy 0: global blocklist - a rejected video is reported as a
        // known duplicate so the scan neither inserts it nor refreshes it,
        // and it is counted as "existing" for the frontier stop.
        $blockMgr = new BlocklistManager();
        if ($blockMgr->matchVideo($video) > 0) {
            return array(
                'is_duplicate'  => true,
                'reason'        => 'BLOCKLIST',
                'discovered_id' => 0,
            );
        }

        // Strategy 1: source_id + external_id
        if (!empty($video['external_id'])) {
            $rs = $this->safeExec("SELECT id, status FROM grabber_discovered_videos
                                      WHERE source_id = " . intval($sourceId) . "
                                      AND external_id = " . $this->db->qStr($video['external_id']) . "
                                      LIMIT 1");
            if ($rs && !$rs->EOF) {
                return array(
                    'is_duplicate' => true,
                    'reason'       => 'EXTERNAL_ID',
                    'discovered_id' => intval($rs->fields['id']),
                );
            }
        }

        // Strategy 2: source_id + canonical_url
        if (!empty($video['canonical_url'])) {
            $rs = $this->safeExec("SELECT id, status FROM grabber_discovered_videos
                                      WHERE source_id = " . intval($sourceId) . "
                                      AND canonical_url = " . $this->db->qStr($video['canonical_url']) . "
                                      LIMIT 1");
            if ($rs && !$rs->EOF) {
                return array(
                    'is_duplicate' => true,
                    'reason'       => 'CANONICAL_URL',
                    'discovered_id' => intval($rs->fields['id']),
                );
            }
        }

        // Strategy 3: Check AVS video.source_url (cross-system dedup)
        if (!empty($video['source_url'])) {
            $vid = $this->matchAvsSourceUrl($video['source_url']);
            if ($vid > 0) {
                return array(
                    'is_duplicate' => true,
                    'reason'       => 'AVS_SOURCE_URL',
                    'discovered_id' => 0,
                    'video_id'     => $vid,
                );
            }
        }

        // Also check canonical URL against AVS source_url
        if (!empty($video['canonical_url']) && $video['canonical_url'] !== $video['source_url']) {
            $vid = $this->matchAvsSourceUrl($video['canonical_url']);
            if ($vid > 0) {
                return array(
                    'is_duplicate' => true,
                    'reason'       => 'AVS_CANONICAL_URL',
                    'discovered_id' => 0,
                    'video_id'     => $vid,
                );
            }
        }

        return array(
            'is_duplicate' => false,
            'reason'       => '',
            'discovered_id' => 0,
        );
    }

    /**
     * Procura um video do AVS com a mesma URL.
     *
     * A comparacao usa a MESMA normalizacao da BlocklistManager (lowercase +
     * trim + '/' final) e testa tambem a variante COM '/' para ficar dentro do
     * indice src_url - comparar a string crua dava falso negativo em
     * "HTTP://Site/Video" vs "http://site/video" e varria a tabela inteira.
     * Query string (utm_*, ?t=30) continua fora do alcance: quem pega isso e
     * o pHash do VideoDuplicate.
     *
     * @param  string $url
     * @return int    VID encontrado, ou 0
     */
    private function matchAvsSourceUrl($url) {
        $norm = BlocklistManager::normalizeUrl($url);
        if ($norm === '') return 0;

        $rs = $this->safeExec("SELECT VID FROM video
                                  WHERE source_url IN (" . $this->db->qStr($norm) . ", " . $this->db->qStr($norm . '/') . ")
                                  LIMIT 1");
        return ($rs && !$rs->EOF) ? intval($rs->fields['VID']) : 0;
    }

    /**
     * Check if a discovered video is already queued or imported.
     * @param int $discoveredVideoId
     * @return bool
     */
    public function isAlreadyQueued($discoveredVideoId) {
        $rs = $this->safeExec("SELECT status FROM grabber_discovered_videos
                                  WHERE id = " . intval($discoveredVideoId) . " LIMIT 1");
        if ($rs && !$rs->EOF) {
            $status = $rs->fields['status'];
            return in_array($status, array('QUEUED', 'PROCESSING', 'IMPORTED'));
        }
        return false;
    }

    /**
     * Check if a job already exists for this discovered video.
     * @param int $discoveredVideoId
     * @return bool
     */
    public function jobExists($discoveredVideoId) {
        $rs = $this->safeExec("SELECT id FROM grabber_jobs
                                  WHERE discovered_video_id = " . intval($discoveredVideoId) . "
                                  AND status IN ('PENDING', 'PROCESSING')
                                  LIMIT 1");
        return ($rs && !$rs->EOF);
    }

    /**
     * Find potential duplicates by title (informational only, not blocking).
     * @param int    $sourceId
     * @param string $title
     * @param int    $excludeId  Exclude this discovered video ID
     * @return array
     */
    public function findPotentialByTitle($title, $excludeId = 0) {
        if (empty($title)) return array();

        $rs = $this->safeExec("SELECT id, source_url, title, status
                                  FROM grabber_discovered_videos
                                  WHERE title = " . $this->db->qStr($title) . "
                                  AND id != " . intval($excludeId) . "
                                  LIMIT 5");
        $results = array();
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $results[] = $rs->fields;
                $rs->MoveNext();
            }
        }
        return $results;
    }
}
