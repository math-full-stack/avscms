<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/providers/AbstractScrapeProvider.php';

/**
 * PornoBrasilProvider - Discovers videos from pornobrasil.com listing pages.
 *
 * Tube site with server-rendered HTML listings. The Python scraper reads
 * video cards from listing pages and enriches each item with metadata from
 * individual video pages.
 */
class PornoBrasilProvider extends AbstractScrapeProvider {

    protected $timeout = 60;

    protected $discoveryHint = 'PornoBrasil discovery works with listing/category URLs (e.g. https://pornobrasil.com/ or https://pornobrasil.com/videos/novinhas/)';

    protected function getScriptName() {
        return 'pornobrasil_scrape.py';
    }

    public function getSiteName() {
        return 'PornoBrasil';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/pornobrasil\\.com/i', $url);
    }

    public function getExternalId($url) {
        if (preg_match('/video[s]?(\\d+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
