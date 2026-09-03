<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/interfaces/DiscoveryProvider.php';
require_once dirname(__DIR__) . '/MassGrabberManager.php';

/**
 * XfreeProvider - Discovers videos from xfree.com profile pages.
 *
 * xfree.com is protected by Cloudflare and is a Nuxt.js SPA.
 * We use a Python script with curl_cffi to bypass Cloudflare
 * and scrape the server-rendered HTML.
 */
class XfreeProvider implements DiscoveryProvider {

    private $pythonBinary = null;
    private $scrapeScript = null;

    public function __construct() {
        global $config;
        $this->scrapeScript = $config['BASE_DIR'] . '/scripts/xfree_scrape.py';
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

    public function getSiteName() {
        return 'XFree';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/xfree\.com/i', $url);
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
                'error'    => 'URL not supported by XFree provider',
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

        $output = MassGrabberManager::execWithTimeout($cmd, 180);
        if ($output === false || trim($output) === '') {
            return array(
                'status'   => false,
                'error'    => 'Failed to execute scraper. Check that python3 and curl_cffi are installed.',
                'hint'     => 'Install curl_cffi: pip3 install curl_cffi',
                'videos'   => array(),
                'page'     => $page,
                'has_more' => false,
                'total'    => 0,
            );
        }

        $output = trim($output);
        if (stripos($output, '[EXEC_TIMEOUT') !== false) {
            return array(
                'status'   => false,
                'error'    => 'Scraper timed out after 180 seconds. Try again later.',
                'videos'   => array(),
                'page'     => $page,
                'has_more' => false,
                'total'    => 0,
            );
        }

        $data = json_decode($output, true);
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
                'hint'     => 'XFree discovery works with profile URLs (e.g. https://www.xfree.com/USERNAME)',
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
        if (preg_match('/[?&]id=(\d+)/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/\/video[\/-](\d+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
