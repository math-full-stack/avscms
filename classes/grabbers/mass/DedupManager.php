<?php
defined('_VALID') or die('Restricted Access!');

/**
 * DedupManager - Multi-strategy deduplication for discovered videos.
 *
 * Strategy priority:
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
            $rs = $this->safeExec("SELECT VID FROM video
                                      WHERE source_url = " . $this->db->qStr($video['source_url']) . "
                                      LIMIT 1");
            if ($rs && !$rs->EOF) {
                return array(
                    'is_duplicate' => true,
                    'reason'       => 'AVS_SOURCE_URL',
                    'discovered_id' => 0,
                    'video_id'     => intval($rs->fields['VID']),
                );
            }
        }

        // Also check canonical URL against AVS source_url
        if (!empty($video['canonical_url']) && $video['canonical_url'] !== $video['source_url']) {
            $rs = $this->safeExec("SELECT VID FROM video
                                      WHERE source_url = " . $this->db->qStr($video['canonical_url']) . "
                                      LIMIT 1");
            if ($rs && !$rs->EOF) {
                return array(
                    'is_duplicate' => true,
                    'reason'       => 'AVS_CANONICAL_URL',
                    'discovered_id' => 0,
                    'video_id'     => intval($rs->fields['VID']),
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
