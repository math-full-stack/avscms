<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/providers/AbstractScrapeProvider.php';

/**
 * BuceteiroProvider - Discovers videos from buceteiro.com listing pages.
 *
 * buceteiro.com é um WordPress com Yoast SEO. O scraper Python (curl_cffi)
 * parseia o sitemap.xml para descobrir URLs de vídeos e enriquece cada item
 * com o JSON-LD VideoObject da página do vídeo (duração, tags, descrição).
 *
 * Descoberta: sitemap (post-sitemap{N}.xml) ou paginação /page/{N}/.
 */
class BuceteiroProvider extends AbstractScrapeProvider {

    protected $timeout = 120;

    protected $discoveryHint = 'Buceteiro discovery works with sitemap or listing URLs (e.g. https://buceteiro.com/sitemap.xml or https://buceteiro.com/page/1/)';

    protected function getScriptName() {
        return 'buceteiro_scrape.py';
    }

    public function getSiteName() {
        return 'Buceteiro';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/buceteiro\.com/i', $url);
    }

    public function getExternalId($url) {
        // Slug do URL de vídeo: /{slug}/
        if (preg_match('/\/([^\/\?]+)\/?$/', $url, $m) && $m[1] !== 'page' && $m[1] !== 'sitemap.xml') {
            return $m[1];
        }
        return null;
    }
}
