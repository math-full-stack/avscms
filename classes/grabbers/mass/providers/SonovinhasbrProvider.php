<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/providers/AbstractScrapeProvider.php';

/**
 * SonovinhasbrProvider - Discovers videos from sonovinhasbr.com listing/category pages.
 *
 * sonovinhasbr.com is a WordPress site with standard HTML listings.
 * We use a Python script with curl_cffi (stdlib parsing) - the same native
 * toolchain as the XFree provider.
 */
class SonovinhasbrProvider extends AbstractScrapeProvider {

    protected $timeout = 60;

    protected $discoveryHint = 'SonovinhasBR discovery works with listing/category URLs (e.g. https://www.sonovinhasbr.com/category/novinhas-gostosas/)';

    protected function getScriptName() {
        return 'sonovinhasbr_scrape.py';
    }

    public function getSiteName() {
        return 'SonovinhasBR';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/sonovinhasbr\\.com/i', $url);
    }

    public function getExternalId($url) {
        // WordPress post ID from player URL parameter
        if (preg_match('/[?&]v=(\\d+)/', $url, $m)) {
            return $m[1];
        }
        // WordPress post ID from ?p= parameter
        if (preg_match('/[?&]p=(\\d+)/', $url, $m)) {
            return $m[1];
        }
        // Extract from URL slug (last segment before trailing slash)
        if (preg_match('/\\/([^\\/]+)\\/?$/', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}