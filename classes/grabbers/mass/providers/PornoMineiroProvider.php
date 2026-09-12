<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/providers/AbstractScrapeProvider.php';

/**
 * PornoMineiroProvider - Discovers videos from pornomineiro.com listing pages.
 *
 * WordPress site with server-rendered HTML listings. The Python scraper reads
 * video cards from listing/category pages and enriches each item with
 * JSON-LD VideoObject metadata from individual video pages.
 */
class PornoMineiroProvider extends AbstractScrapeProvider {

    protected $timeout = 60;

    protected $discoveryHint = 'PornoMineiro discovery works with listing/category URLs (e.g. https://www.pornomineiro.com/ or https://www.pornomineiro.com/videos/novinhas/)';

    protected function getScriptName() {
        return 'pornomineiro_scrape.py';
    }

    public function getSiteName() {
        return 'PornoMineiro';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/pornomineiro\\.com/i', $url);
    }

    public function getExternalId($url) {
        // Slug from URL: /videos/{category}/{slug}/
        if (preg_match('/\\/videos\\/[^/]+\\/([^/]+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
