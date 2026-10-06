<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/interfaces/DiscoveryProvider.php';
require_once dirname(__DIR__) . '/MassGrabberManager.php';

/**
 * TikTokProvider - Discovers videos from TikTok profiles, hashtags, and search.
 *
 * Uses yt-dlp --flat-playlist with --playlist-items for fast batch fetching.
 * Each scan fetches only 10 videos (one page) instead of the entire profile.
 *
 * Supported URL patterns:
 *   - https://www.tiktok.com/@username
 *   - https://www.tiktok.com/@username/video/1234567890
 *   - https://www.tiktok.com/tag/hashtag
 *   - https://www.tiktok.com/search?q=query
 *   - https://vm.tiktok.com/xxxxxx (short links - redirects to profile)
 */
class TikTokProvider implements DiscoveryProvider {

    private $pythonBinary = null;
    private $ytdlpScript = null;
    private $perPage = 10;

    public function __construct() {
        global $config;
        $this->ytdlpScript = $config['BASE_DIR'] . '/scripts/yt-dlp';
        $this->detectPython();
    }

    private function detectPython() {
        $candidates = array(
            '/opt/homebrew/bin/python3',
            '/usr/local/bin/python3',
            '/usr/bin/python3',
            'python3'
        );
        foreach ($candidates as $bin) {
            $check = @shell_exec("$bin --version 2>&1");
            if ($check && stripos($check, 'Python 3') !== false) {
                $this->pythonBinary = $bin;
                break;
            }
        }
        if (!$this->pythonBinary) {
            $this->pythonBinary = 'python3';
        }
    }

    private function getAuthArgs() {
        global $config;
        $args = '';
        $cookieFile = isset($config['grabber_cookies']) ? trim($config['grabber_cookies']) : '';
        if ($cookieFile && file_exists($cookieFile)) {
            $args .= ' --cookies ' . escapeshellarg($cookieFile);
        }
        $browser = isset($config['grabber_cookies_browser']) ? trim($config['grabber_cookies_browser']) : '';
        if ($browser) {
            $args .= ' --cookies-from-browser ' . escapeshellarg($browser);
        }
        return $args;
    }

    public function getSiteName() {
        return 'TikTok';
    }

    public function canDiscover($url) {
        return (bool) preg_match('#(^|//)(www\.|m\.)?(vm\.)?tiktok\.com/#i', $url);
    }

    public function supportsGrab() { return true; }
    public function supportsMetadata() { return true; }
    public function supportsVersions() { return true; }

    /**
     * Build the TikTok URL based on source + options.
     * Forces sort by date (newest first) where applicable.
     */
    private function buildUrl($sourceUrl, $options = array()) {
        $filter = isset($options['filter']) ? $options['filter'] : 'videos';
        $query  = isset($options['query']) ? trim($options['query']) : '';

        // If it's already a specific URL (hashtag, search), use as-is
        if (preg_match('/[?&](tag|search)=/i', $sourceUrl) || preg_match('/\/tag\//i', $sourceUrl)) {
            return $sourceUrl;
        }

        $base = rtrim($sourceUrl, '/');

        switch ($filter) {
            case 'search':
                if (empty($query)) {
                    // Fallback to profile videos if no query
                    $url = $base . '/video';
                } else {
                    $url = 'https://www.tiktok.com/search?q=' . urlencode($query);
                }
                break;
            case 'hashtag':
            case 'tag':
                if (empty($query)) {
                    $url = $base . '/video';
                } else {
                    $url = 'https://www.tiktok.com/tag/' . urlencode($query);
                }
                break;
            case 'likes':
                $url = $base . '/likes';
                break;
            case 'videos':
            default:
                $url = $base . '/video';
                break;
        }

        return $url;
    }

    /**
     * Discover videos for a specific page.
     * Fetches only 10 items at a time using --playlist-items.
     */
    public function discover($url, $options = array()) {
        $url = trim($url);
        if (!$this->canDiscover($url)) {
            return array(
                'status' => false, 'error' => 'URL not supported by TikTok provider',
                'videos' => array(), 'page' => 1, 'has_more' => false, 'total' => 0,
            );
        }

        $page = isset($options['page']) ? intval($options['page']) : 1;
        if ($page < 1) $page = 1;
        $perPage = $this->perPage;

        $fetchUrl = $this->buildUrl($url, $options);

        // Calculate playlist-items range for this page
        $start = ($page - 1) * $perPage + 1;
        $end = $page * $perPage;
        $playlistArg = ' --playlist-items ' . $start . '-' . $end;

        // Timeframe: use --dateafter to filter by upload date directly in yt-dlp
        $dateArg = '';
        $timeframe = isset($options['timeframe']) ? trim($options['timeframe']) : '';
        if ($timeframe) {
            $timeframeDays = array(
                'today'   => 1,
                'week'    => 7,
                'month'   => 30,
                '3months' => 90,
            );
            if (isset($timeframeDays[$timeframe])) {
                $dateAfter = date('Y%m%d', strtotime('-' . $timeframeDays[$timeframe] . ' days'));
                $dateArg = ' --dateafter ' . escapeshellarg($dateAfter);
            }
        }

        // Sort: reverse for oldest first
        $sortArg = '';
        $sort = isset($options['sort']) ? trim($options['sort']) : 'newest';
        if ($sort === 'oldest') {
            $sortArg = ' --playlist-reverse';
        }

        $cmd = sprintf(
            '%s %s --dump-single-json --no-warnings --skip-download --no-accept-language --flat-playlist%s%s %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->ytdlpScript),
            $this->getAuthArgs(),
            $playlistArg,
            $dateArg . $sortArg,
            escapeshellarg($fetchUrl)
        );

        $output = MassGrabberManager::execWithTimeout($cmd, 120);

        if ($output === false || trim($output) === '') {
            return array(
                'status' => false, 'error' => 'Failed to execute yt-dlp',
                'videos' => array(), 'page' => $page, 'has_more' => false, 'total' => 0,
            );
        }

        if (stripos($output, '[EXEC_TIMEOUT') !== false) {
            return array(
                'status' => false, 'error' => 'yt-dlp timed out after 120 seconds',
                'videos' => array(), 'page' => $page, 'has_more' => false, 'total' => 0,
            );
        }

        if (preg_match('/^ERROR:.*$/m', $output, $m)) {
            return array(
                'status' => false, 'error' => $m[0],
                'videos' => array(), 'page' => $page, 'has_more' => false, 'total' => 0,
            );
        }

        $jsonStart = strpos($output, '{');
        $jsonEnd = strrpos($output, '}');
        if ($jsonStart === false || $jsonEnd === false) {
            return array(
                'status' => false, 'error' => 'Invalid response from yt-dlp',
                'videos' => array(), 'page' => $page, 'has_more' => false, 'total' => 0,
            );
        }

        $jsonStr = substr($output, $jsonStart, ($jsonEnd - $jsonStart + 1));
        $data = json_decode($jsonStr, true);
        if (!$data) {
            return array(
                'status' => false, 'error' => 'Failed to parse yt-dlp JSON',
                'videos' => array(), 'page' => $page, 'has_more' => false, 'total' => 0,
            );
        }

        $videos = array();

        if (isset($data['entries']) && is_array($data['entries'])) {
            foreach ($data['entries'] as $entry) {
                $videoId = isset($entry['id']) ? $entry['id'] : '';
                if (empty($videoId)) continue;

                // TikTok video URLs
                $sourceUrl = 'https://www.tiktok.com/@' . (isset($entry['uploader']) ? $entry['uploader'] : 'unknown') . '/video/' . $videoId;
                $title = isset($entry['title']) ? trim($entry['title']) : '';
                $duration = isset($entry['duration']) ? intval($entry['duration']) : 0;
                $thumbnail = isset($entry['thumbnail']) ? $entry['thumbnail'] : '';
                if (empty($thumbnail)) {
                    $thumbnail = 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg'; // fallback
                }
                $uploader = isset($entry['uploader']) ? $entry['uploader'] : '';
                $viewCount = isset($entry['view_count']) ? intval($entry['view_count']) : 0;
                $likeCount = isset($entry['like_count']) ? intval($entry['like_count']) : 0;

                // Use description for tags if available
                $description = isset($entry['description']) ? trim($entry['description']) : '';
                $tags = '';
                if ($description !== '') {
                    // Extract hashtags from description
                    if (preg_match_all('/#([\p{L}\p{N}_]{2,50})/u', $description, $m)) {
                        $tagList = array();
                        foreach ($m[1] as $tag) {
                            $tag = strtolower(trim($tag));
                            if ($tag !== '' && !in_array($tag, $tagList, true)) {
                                $tagList[] = $tag;
                            }
                        }
                        $tags = implode(', ', array_slice($tagList, 0, 15));
                    }
                }
                if ($uploader !== '') {
                    $tags = ($tags ? $tags . ', ' : '') . strtolower($uploader);
                }

                $videos[] = array(
                    'external_id'       => $videoId,
                    'source_url'        => $sourceUrl,
                    'canonical_url'     => $sourceUrl,
                    'title'             => $title,
                    'description'       => $description,
                    'tags'              => $tags,
                    'duration'          => $duration,
                    'duration_formatted' => $this->formatDuration($duration),
                    'thumbnail_url'     => $thumbnail,
                    'uploader'          => $uploader,
                    'view_count'        => $viewCount,
                    'like_count'        => $likeCount,
                );
            }
        }

        // Determine total count from playlist_count if available
        $totalCount = isset($data['playlist_count']) ? intval($data['playlist_count']) : 0;

        // If we got fewer items than requested, we've reached the end
        $hasMore = count($videos) >= $perPage;

        // If we have a total, use it for accurate has_more
        if ($totalCount > 0) {
            $hasMore = ($page * $perPage) < $totalCount;
        }

        return array(
            'status'   => true,
            'videos'   => $videos,
            'page'     => $page,
            'has_more' => $hasMore,
            'total'    => $totalCount > 0 ? $totalCount : ($hasMore ? $page * $perPage + count($videos) : ($page - 1) * $perPage + count($videos)),
            'per_page' => $perPage,
        );
    }

    public function normalizeUrl($url) {
        $parts = parse_url($url);
        if (!$parts) return $url;
        $canonical = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['path'])) $canonical .= $parts['path'];
        if (isset($parts['query'])) {
            $params = array();
            parse_str($parts['query'], $params);
            $keep = array();
            // Keep relevant params for TikTok
            if (isset($params['q'])) $keep['q'] = $params['q']; // search
            if (isset($params['tag'])) $keep['tag'] = $params['tag']; // hashtag
            if (!empty($keep)) $canonical .= '?' . http_build_query($keep);
        }
        return $canonical;
    }

    public function getExternalId($url) {
        // https://www.tiktok.com/@username/video/1234567890
        if (preg_match('/\/video\/(\d+)/', $url, $m)) return $m[1];
        // https://vm.tiktok.com/xxxxxx
        if (preg_match('/vm\.tiktok\.com\/([a-zA-Z0-9]+)/', $url, $m)) return $m[1];
        // https://www.tiktok.com/@username?lang=en (profile)
        if (preg_match('/tiktok\.com\/@([a-zA-Z0-9_.-]+)/', $url, $m)) return '@' . $m[1];
        return null;
    }

    private function formatDuration($seconds) {
        $seconds = max(0, intval($seconds));
        $hours = floor($seconds / 3600);
        $mins = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        if ($hours > 0) return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
        return sprintf('%02d:%02d', $mins, $secs);
    }
}