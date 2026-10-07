<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/providers/AbstractScrapeProvider.php';

/**
 * CnnamadorProvider - Discovers videos from cnnamador.com listing pages.
 *
 * KVS (Kernel Video Sharing) site with server-rendered listings. The Python
 * scraper reads video cards from the /latest-updates/ pagination and enriches
 * each item with JSON-LD + `flashvars` metadata from individual video pages.
 */
class CnnamadorProvider extends AbstractScrapeProvider {

    protected $timeout = 120;

    protected $discoveryHint = 'Cnnamador discovery works with listing URLs (e.g. https://cnnamador.com/latest-updates/ or https://cnnamador.com/tag/amadoras/)';

    protected function getScriptName() {
        return 'cnnamador_scrape.py';
    }

    public function getSiteName() {
        return 'Cnnamador';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/cnnamador\.com/i', $url);
    }

    public function getExternalId($url) {
        // Slug from URL: https://cnnamador.com/{slug}/
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return null;
        }
        $slug = trim($path, '/');
        if ($slug === '') {
            return null;
        }
        $parts = explode('/', $slug);
        $slug = end($parts);
        // Listing pages end with a page number; those are not video ids.
        if ($slug !== '' && !ctype_digit($slug)) {
            return $slug;
        }
        return null;
    }
}
