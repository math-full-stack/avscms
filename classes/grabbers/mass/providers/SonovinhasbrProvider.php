<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/interfaces/DiscoveryProvider.php';

/**
 * SonovinhasbrProvider - Discovers videos from sonovinhasbr.com listing/category pages.
 *
 * sonovinhasbr.com is a WordPress site with standard HTML listings.
 * We use a Python script with requests + BeautifulSoup to parse pages.
 */
class SonovinhasbrProvider implements DiscoveryProvider {

    private $pythonBinary = null;
    private $scrapeScript = null;

    public function __construct() {
        global $config;
        $this->scrapeScript = $config['BASE_DIR'] . '/scripts/sonovinhasbr_scrape.py';
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
                // Verify requests module is available
                $test = @shell_exec("$bin -c 'import requests' 2>&1");
                if ($test === null || stripos($test, 'ModuleNotFoundError') === false) {
                    $this->pythonBinary = $bin;
                    break;
                }
            }
        }
        if (!$this->pythonBinary) {
            $this->pythonBinary = 'python3';
        }
    }

    public function getSiteName() {
        return 'SonovinhasBR';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/sonovinhasbr\.com/i', $url);
    }

    public function supportsGrab() {
        return true;
    }

    public function supportsMetadata() {
        return true;
    }

    public function supportsVersions() {
        return false;
    }

    public function discover($url, $options = array()) {
        $url = trim($url);
        if (!$this->canDiscover($url)) {
            return array(
                'status'   => false,
                'error'    => 'URL not supported by SonovinhasBR provider',
                'videos'   => array(),
                'page'     => 1,
                'has_more' => false,
                'total'    => 0,
            );
        }

        $maxPages = isset($options['max_pages']) ? intval($options['max_pages']) : 1;
        $page = isset($options['page']) ? intval($options['page']) : 1;

        $cmd = sprintf(
            '%s %s %s %d %d 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->scrapeScript),
            escapeshellarg($url),
            $page,
            $maxPages
        );

        $output = @shell_exec($cmd);
        if (!$output) {
            return array(
                'status'   => false,
                'error'    => 'Failed to execute scraper. Check that python3, requests and beautifulsoup4 are installed.',
                'hint'     => 'Install dependencies: pip3 install requests beautifulsoup4',
                'videos'   => array(),
                'page'     => $page,
                'has_more' => false,
                'total'    => 0,
            );
        }

        $data = json_decode(trim($output), true);
        if (!$data) {
            return array(
                'status'   => false,
                'error'    => 'Failed to parse scraper output: ' . substr($output, 0, 300),
                'videos'   => array(),
                'page'     => $page,
                'has_more' => false,
                'total'    => 0,
            );
        }

        if (isset($data['error'])) {
            return array(
                'status'   => false,
                'error'    => $data['error'],
                'hint'     => 'SonovinhasBR discovery works with listing/category URLs (e.g. https://www.sonovinhasbr.com/category/novinhas-gostosas/)',
                'videos'   => array(),
                'page'     => $page,
                'has_more' => false,
                'total'    => 0,
            );
        }

        $videos = isset($data['videos']) ? $data['videos'] : array();
        $totalCount = isset($data['total']) ? intval($data['total']) : count($videos);
        $hasMore = isset($data['has_more']) ? (bool) $data['has_more'] : false;

        return array(
            'status'   => true,
            'videos'   => $videos,
            'page'     => $page,
            'has_more' => $hasMore,
            'total'    => $totalCount,
        );
    }

    public function normalizeUrl($url) {
        $parts = parse_url($url);
        if (!$parts) return $url;
        $canonical = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['path'])) $canonical .= rtrim($parts['path'], '/');
        return $canonical;
    }

    public function getExternalId($url) {
        // WordPress post ID from player URL parameter
        if (preg_match('/[?&]v=(\d+)/', $url, $m)) {
            return $m[1];
        }
        // WordPress post ID from ?p= parameter
        if (preg_match('/[?&]p=(\d+)/', $url, $m)) {
            return $m[1];
        }
        // Extract from URL slug (last segment before trailing slash)
        if (preg_match('/\/([^\/]+)\/?$/', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
