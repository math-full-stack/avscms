<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/providers/AbstractScrapeProvider.php';

/**
 * XfreeProvider - Discovers videos from xfree.com profile pages.
 *
 * xfree.com is protected by Cloudflare and is a Nuxt.js SPA.
 * We use a Python script with curl_cffi to bypass Cloudflare
 * and scrape the server-rendered HTML.
 */
class XfreeProvider extends AbstractScrapeProvider {

    protected $timeout = 180;

    protected $discoveryHint = 'XFree discovery works with profile URLs (e.g. https://www.xfree.com/USERNAME)';

    protected function getScriptName() {
        return 'xfree_scrape.py';
    }

    public function getSiteName() {
        return 'XFree';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/xfree\\.com/i', $url);
    }

    public function getExternalId($url) {
        if (preg_match('/[?&]id=(\\d+)/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/\\/video[\\/-](\\d+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}