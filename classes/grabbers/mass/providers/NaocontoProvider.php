<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/providers/AbstractScrapeProvider.php';

/**
 * NaocontoProvider - Discovers videos from naoconto.com listing pages.
 *
 * WordPress site with server-rendered HTML listings. The Python scraper reads
 * post blocks from listing pages and enriches each item with metadata from
 * individual video pages.
 */
class NaocontoProvider extends AbstractScrapeProvider {

    protected $timeout = 60;

    protected $discoveryHint = 'NaoConto discovery works with listing/category URLs (e.g. https://www.naoconto.com/ or https://www.naoconto.com/categoria/safada/)';

    protected function getScriptName() {
        return 'naoconto_scrape.py';
    }

    public function getSiteName() {
        return 'NaoConto';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/naoconto\\.com/i', $url);
    }

    public function getExternalId($url) {
        // Slug from URL: /YYYY/MM/slug.html
        if (preg_match('/\\/(\\d{4})\\/(\\d{2})\\/([^\\.]+)\\.html/', $url, $m)) {
            return $m[1] . $m[2] . '_' . $m[3];
        }
        return null;
    }
}
