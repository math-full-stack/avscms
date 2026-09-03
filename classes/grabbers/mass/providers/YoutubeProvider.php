<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/interfaces/DiscoveryProvider.php';

/**
 * YoutubeProvider - Discovers videos from YouTube channels, playlists, and search.
 *
 * Uses yt-dlp --flat-playlist with --playlist-items for fast batch fetching.
 * Each scan fetches only 10 videos (one page) instead of the entire channel.
 */
class YoutubeProvider implements DiscoveryProvider {

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
        return 'YouTube';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/(youtube\\.com|youtu\\.be)/i', $url);
    }

    public function supportsGrab() { return true; }
    public function supportsMetadata() { return true; }
    public function supportsVersions() { return true; }

    /**
     * Build the YouTube URL based on source + options.
     * Forces sort=date (newest first) for channel video pages.
     */
    private function buildUrl($sourceUrl, $options = array()) {
        $filter = isset($options['filter']) ? $options['filter'] : 'videos';
        $query  = isset($options['query']) ? trim($options['query']) : '';

        if (preg_match('/[?&]list=PL/i', $sourceUrl)) {
            return $sourceUrl;
        }

        $base = rtrim($sourceUrl, '/');

        switch ($filter) {
            case 'search':
                if (empty($query)) {
                    $url = $base . '/videos?view=0&sort=dd&shelf_id=0';
                } else {
                    $url = $base . '/search?query=' . urlencode($query);
                }
                break;
            case 'shorts':
                $url = $base . '/shorts';
                break;
            case 'releases':
                $url = $base . '/releases';
                break;
            case 'videos':
            default:
                $url = $base . '/videos?view=0&sort=dd&shelf_id=0';
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
                'status' => false, 'error' => 'URL not supported by YouTube provider',
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
            '%s %s --dump-single-json --no-warnings --skip-download --no-accept-language%s%s %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->ytdlpScript),
            $this->getAuthArgs(),
            $playlistArg,
            $dateArg . $sortArg,
            escapeshellarg($fetchUrl)
        );

        @set_time_limit(60);
        $output = @shell_exec($cmd);

        if (!$output) {
            return array(
                'status' => false, 'error' => 'Failed to execute yt-dlp',
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

                $sourceUrl = 'https://www.youtube.com/watch?v=' . $videoId;
                $title = isset($entry['title']) ? trim($entry['title']) : '';
                $duration = isset($entry['duration']) ? intval($entry['duration']) : 0;
                $thumbnail = isset($entry['thumbnail']) ? $entry['thumbnail'] : '';
                if (empty($thumbnail)) {
                    $thumbnail = 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg';
                }

                $videos[] = array(
                    'external_id'       => $videoId,
                    'source_url'        => $sourceUrl,
                    'canonical_url'     => $sourceUrl,
                    'title'             => $title,
                    'description'       => '',
                    'tags'              => '',
                    'duration'          => $duration,
                    'duration_formatted' => $this->formatDuration($duration),
                    'thumbnail_url'     => $thumbnail,
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
            if (isset($params['v'])) $keep['v'] = $params['v'];
            if (isset($params['list'])) $keep['list'] = $params['list'];
            if (!empty($keep)) $canonical .= '?' . http_build_query($keep);
        }
        return $canonical;
    }

    public function getExternalId($url) {
        if (preg_match('/[?&]v=([a-zA-Z0-9_-]{11})/', $url, $m)) return $m[1];
        if (preg_match('/youtu\\.be\\/([a-zA-Z0-9_-]{11})/', $url, $m)) return $m[1];
        if (preg_match('/embed\\/([a-zA-Z0-9_-]{11})/', $url, $m)) return $m[1];
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
