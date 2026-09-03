<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/MassGrabberManager.php';

/**
 * DiscoveryManager - Manages the discovery of videos from sources.
 */
class DiscoveryManager {

    private $db = null;

    public function __construct() {
        global $conn;
        $this->db = $conn;
    }

    private function safeExec($sql) {
        try { return $this->db->Execute($sql); } catch (Exception $e) { return null; } catch (Throwable $e) { return null; }
    }

    /**
     * Run a discovery scan for a source.
     * Fetches videos in batches of 10 (one page) and stores them in DB.
     *
     * @param int   $sourceId
     * @param array $options  ['max_pages' => int, 'manual' => bool, 'filter' => string, 'query' => string]
     * @return array ['run_id' => int, 'found' => int, 'new' => int, 'existing' => int, 'total' => int]
     */
    public function scan($sourceId, $options = array()) {
        $sourceMgr = new SourceManager();
        $source = $sourceMgr->getById($sourceId);
        if (!$source) {
            return array('error' => 'Source not found');
        }

        if (empty($source['discovery_url'])) {
            return array('error' => 'No discovery URL configured');
        }

        // Acquire source lock
        if (!MassGrabberManager::acquireSourceLock($sourceId)) {
            return array('error' => 'Another discovery is already running for this source');
        }

        // Get provider
        $provider = MassGrabberManager::getProviderByName($source['provider']);
        if (!$provider) {
            $provider = MassGrabberManager::getDiscoveryProviderForUrl($source['discovery_url']);
        }
        if (!$provider) {
            MassGrabberManager::releaseSourceLock($sourceId, 'FAILED');
            return array('error' => 'No provider found for source: ' . $source['provider']);
        }

        // Create run record (reuse the one created by the caller when provided,
        // so the UI polls the same run that actually does the work)
        $runMgr = new RunManager();
        $runId = isset($options['run_id']) ? intval($options['run_id']) : 0;
        if ($runId <= 0) {
            $runId = $runMgr->create($sourceId, isset($options['manual']) && $options['manual'] ? 'MANUAL' : 'AUTOMATIC');
        } else {
            $runMgr->update($runId, array('status' => 'RUNNING'));
        }

        $logger = new Logger();
        $logger->log($runId, 0, $sourceId, 'INFO', 'SCAN_STARTED', 'Discovery scan started for: ' . $source['name']);

        // Fetch pages in batches of 10
        $maxPages = isset($options['max_pages']) ? intval($options['max_pages']) : 10;
        if ($maxPages < 1) $maxPages = 1;
        if ($maxPages > 9999) $maxPages = 9999;

        $allVideos = array();
        $totalFound = 0;
        $hasMore = true;
        $currentPage = 1;
        $dedupMgr = new DedupManager();
        $newCount = 0;
        $existingCount = 0;

        while ($currentPage <= $maxPages && $hasMore) {
            $discoverOpts = array(
                'page'     => $currentPage,
                'max_pages' => $maxPages - $currentPage + 1,
            );
            if (isset($options['filter'])) $discoverOpts['filter'] = $options['filter'];
            if (isset($options['query'])) $discoverOpts['query'] = $options['query'];
            if (isset($options['timeframe'])) $discoverOpts['timeframe'] = $options['timeframe'];
            if (isset($options['sort'])) $discoverOpts['sort'] = $options['sort'];

            $result = $provider->discover($source['discovery_url'], $discoverOpts);

            if (!$result['status']) {
                $logger->log($runId, 0, $sourceId, 'ERROR', 'PAGE_FAILED',
                    'Page ' . $currentPage . ' failed: ' . (isset($result['error']) ? $result['error'] : 'Unknown'));
                break;
            }

            $pageVideos = isset($result['videos']) ? $result['videos'] : array();
            if (empty($pageVideos)) {
                $hasMore = false;
                break;
            }

            $allVideos = array_merge($allVideos, $pageVideos);
            if ($currentPage === 1) {
                $totalFound = isset($result['total']) ? intval($result['total']) : 0;
            }

            $logger->log($runId, 0, $sourceId, 'INFO', 'PAGE_SCANNED',
                'Page ' . $currentPage . ': found ' . count($pageVideos) . ' videos');

            // Process and deduplicate each page immediately
            foreach ($pageVideos as $video) {
                $video = $this->normalizeVideo($video);
                $dedupResult = $dedupMgr->check($sourceId, $video);

                if ($dedupResult['is_duplicate']) {
                    $existingCount++;
                    if (!empty($dedupResult['discovered_id'])) {
                        $this->safeExec("UPDATE grabber_discovered_videos
                                            SET last_seen_at = " . time() . "
                                            WHERE id = " . intval($dedupResult['discovered_id']) . " LIMIT 1");
                    }
                } else {
                    $this->insertDiscovered($sourceId, $video, $runId);
                    $newCount++;
                }
            }

            $hasMore = !empty($result['has_more']);
            $currentPage++;

            // Small delay between pages to be polite
            if ($hasMore && intval($source['delay_seconds']) > 0) {
                sleep(intval($source['delay_seconds']));
            }
        }

        $totalProcessed = count($allVideos);
        $logger->log($runId, 0, $sourceId, 'INFO', 'SCAN_COMPLETE',
            "Processed $totalProcessed videos ($newCount new, $existingCount existing). Total available: $totalFound");

        // Update run record
        $runMgr->update($runId, array(
            'status'         => 'FINISHED',
            'finished_at'    => time(),
            'found_count'    => $totalProcessed,
            'new_count'      => $newCount,
            'existing_count' => $existingCount,
        ));

        MassGrabberManager::releaseSourceLock($sourceId, 'FINISHED');
        if ($newCount > 0 || $existingCount > 0) {
            $sourceMgr->recordSuccess($sourceId);
        }

        return array(
            'run_id'    => $runId,
            'found'     => $totalProcessed,
            'new'       => $newCount,
            'existing'  => $existingCount,
            'total'     => $totalFound,
            'pages'     => $currentPage - 1,
        );
    }

    /**
     * Insert a discovered video into the database.
     */
    public function insertDiscovered($sourceId, $video, $runId = 0) {
        $now = time();
        $metadata = json_encode(array(
            'title'         => $video['title'],
            'description'   => $video['description'],
            'tags'          => $video['tags'],
            'duration'      => $video['duration'],
            'thumbnail_url' => $video['thumbnail_url'],
        ));

        $sql = "INSERT INTO grabber_discovered_videos SET
                source_id = " . intval($sourceId) . ",
                external_id = " . $this->db->qStr($video['external_id']) . ",
                source_url = " . $this->db->qStr($video['source_url']) . ",
                canonical_url = " . $this->db->qStr($video['canonical_url']) . ",
                title = " . $this->db->qStr($video['title']) . ",
                description = " . $this->db->qStr($video['description']) . ",
                tags = " . $this->db->qStr($video['tags']) . ",
                duration = " . intval($video['duration']) . ",
                thumbnail_url = " . $this->db->qStr($video['thumbnail_url']) . ",
                metadata_json = " . $this->db->qStr($metadata) . ",
                status = 'NEW',
                first_seen_at = " . $now . ",
                last_seen_at = " . $now . ",
                run_id = " . intval($runId);

        $this->safeExec($sql);
        return intval($this->db->Insert_ID());
    }

    /**
     * Get discovered videos for a source, with optional filters.
     * 
     * @param int    $sourceId
     * @param array  $filters  ['status' => string, 'timeframe' => string, 'sort' => string]
     * @param int    $limit
     * @param int    $offset
     * @return array ['videos' => array, 'total' => int]
     */
    public function getDiscovered($sourceId, $filters = array(), $limit = 10, $offset = 0) {
        $where = "WHERE d.source_id = " . intval($sourceId);
        
        // Status filter
        $status = isset($filters['status']) ? trim($filters['status']) : null;
        if ($status) {
            $where .= " AND d.status = " . $this->db->qStr($status);
        }
        
        // Timeframe filter
        $timeframe = isset($filters['timeframe']) ? trim($filters['timeframe']) : null;
        if ($timeframe) {
            $timeframeMap = array(
                'today'     => 86400,        // 24 hours
                'week'      => 604800,       // 7 days
                'month'     => 2592000,      // 30 days
                '3months'   => 7776000,      // 90 days
            );
            if (isset($timeframeMap[$timeframe])) {
                $since = time() - $timeframeMap[$timeframe];
                $where .= " AND d.first_seen_at >= " . intval($since);
            }
        }

        // Get total count
        $countSql = "SELECT COUNT(*) AS cnt FROM grabber_discovered_videos d " . $where;
        $countRs = $this->safeExec($countSql);
        $total = 0;
        if ($countRs && !$countRs->EOF) {
            $total = intval($countRs->fields['cnt']);
        }

        // Sort order
        $sortBy = isset($filters['sort']) ? trim($filters['sort']) : 'newest';
        $orderByMap = array(
            'newest'    => 'd.id DESC',
            'oldest'    => 'd.id ASC',
            'duration'  => 'd.duration DESC',
            'title'     => 'd.title ASC',
        );
        $orderBy = isset($orderByMap[$sortBy]) ? $orderByMap[$sortBy] : 'd.id DESC';

        // Get page — JOIN with video table using video_id to detect existing imports
        $sql = "SELECT d.*, v.VID AS avs_video_id, v.active AS avs_active
                FROM grabber_discovered_videos d
                LEFT JOIN video v ON v.VID = d.video_id
                " . $where .
               " ORDER BY " . $orderBy . " LIMIT " . intval($limit) . " OFFSET " . intval($offset);
        $rs = $this->safeExec($sql);

        $videos = array();
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $row = $rs->fields;
                $row['duration_formatted'] = $this->formatDuration(intval($row['duration']));

                // Auto-update status based on AVS video table
                $avsVid = isset($row['avs_video_id']) ? intval($row['avs_video_id']) : 0;
                $avsActive = isset($row['avs_active']) ? intval($row['avs_active']) : -1;
                $currentStatus = $row['status'];
                $currentVideoId = isset($row['video_id']) ? intval($row['video_id']) : 0;
                $justReset = false;

                if ($currentVideoId === 0 && $avsVid > 0 && $currentStatus !== 'IMPORTED') {
                    if ($avsActive >= 1) {
                        $this->updateStatus($row['id'], 'IMPORTED', $avsVid);
                        $row['status'] = 'IMPORTED';
                    } else {
                        $this->updateStatus($row['id'], 'EXISTS', $avsVid);
                        $row['status'] = 'EXISTS';
                    }
                    $row['video_id'] = $avsVid;
                } elseif ($currentVideoId > 0 && $avsVid === 0 && $currentStatus === 'IMPORTED') {
                    $this->updateStatus($row['id'], 'NEW', 0);
                    $row['status'] = 'NEW';
                    $row['video_id'] = 0;
                    $justReset = true;
                }

                // Check completed job only if status wasn't just reset from IMPORTED
                if (!$justReset && ($row['status'] === 'NEW' || $row['status'] === 'QUEUED')) {
                    $jobRs = $this->safeExec("SELECT id, video_id FROM grabber_jobs
                                              WHERE discovered_video_id = " . intval($row['id']) . "
                                              AND status = 'COMPLETED' LIMIT 1");
                    if ($jobRs && !$jobRs->EOF) {
                        $completedVid = intval($jobRs->fields['video_id']);
                        $this->updateStatus($row['id'], 'IMPORTED', $completedVid);
                        $row['status'] = 'IMPORTED';
                        $row['video_id'] = $completedVid;
                    }
                }

                $row['video_id'] = isset($row['video_id']) ? intval($row['video_id']) : (isset($row['avs_video_id']) ? intval($row['avs_video_id']) : 0);
                $videos[] = $row;
                $rs->MoveNext();
            }
        }

        return array('videos' => $videos, 'total' => $total);
    }

    /**
     * Get status counts for a source.
     */
    public function getStatusCounts($sourceId) {
        $sql = "SELECT status, COUNT(*) AS cnt FROM grabber_discovered_videos
                WHERE source_id = " . intval($sourceId) . " GROUP BY status";
        $rs = $this->safeExec($sql);
        $counts = array('NEW' => 0, 'EXISTS' => 0, 'QUEUED' => 0, 'PROCESSING' => 0, 'IMPORTED' => 0, 'FAILED' => 0, 'SKIPPED' => 0);
        if ($rs && !$rs->EOF) {
            while (!$rs->EOF) {
                $counts[$rs->fields['status']] = intval($rs->fields['cnt']);
                $rs->MoveNext();
            }
        }
        return $counts;
    }

    /**
     * Get a discovered video by ID.
     */
    public function getById($id) {
        $sql = "SELECT * FROM grabber_discovered_videos WHERE id = " . intval($id) . " LIMIT 1";
        $rs = $this->safeExec($sql);
        if ($rs && !$rs->EOF) {
            return $rs->fields;
        }
        return null;
    }

    /**
     * Update status of a discovered video.
     */
    public function updateStatus($id, $status, $videoId = 0) {
        $sql = "UPDATE grabber_discovered_videos SET status = " . $this->db->qStr($status) .
               ", updated_at = " . time();
        if ($videoId > 0) {
            $sql .= ", video_id = " . intval($videoId);
        }
        $sql .= " WHERE id = " . intval($id) . " LIMIT 1";
        $this->safeExec($sql);
    }

    /**
     * Normalize video data from provider.
     */
    public function normalizeVideo($video) {
        return array(
            'external_id'   => isset($video['external_id']) ? trim($video['external_id']) : '',
            'source_url'    => isset($video['source_url']) ? trim($video['source_url']) : '',
            'canonical_url' => isset($video['canonical_url']) ? trim($video['canonical_url']) : trim($video['source_url']),
            'title'         => isset($video['title']) ? trim($video['title']) : '',
            'description'   => isset($video['description']) ? trim($video['description']) : '',
            'tags'          => isset($video['tags']) ? trim($video['tags']) : '',
            'duration'      => isset($video['duration']) ? intval($video['duration']) : 0,
            'thumbnail_url' => isset($video['thumbnail_url']) ? trim($video['thumbnail_url']) : '',
        );
    }

    private function formatDuration($seconds) {
        $hours = floor($seconds / 3600);
        $mins = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        if ($hours > 0) return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
        return sprintf('%02d:%02d', $mins, $secs);
    }
}
