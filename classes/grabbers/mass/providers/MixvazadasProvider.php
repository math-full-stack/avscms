<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/providers/AbstractScrapeProvider.php';

/**
 * MixvazadasProvider - Discovers videos from mixvazadas.com listing pages.
 *
 * mixvazadas.com é um site Astro-based com HTML server-rendered, sem Cloudflare.
 * O scraper Python (curl_cffi + stdlib) lê a grade de vídeos das páginas de
 * listagem (/page/{N}) e enriquece cada item com meta tags da página do vídeo.
 */
class MixvazadasProvider extends AbstractScrapeProvider {

    protected $timeout = 120;

    protected $discoveryHint = 'Mixvazadas discovery works with listing/category URLs (e.g. https://mixvazadas.com/page/1 or https://mixvazadas.com/tag/novinha-18+)';

    protected function getScriptName() {
        return 'mixvazadas_scrape.py';
    }

    public function getSiteName() {
        return 'Mixvazadas';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/mixvazadas\.com/i', $url);
    }

    public function getExternalId($url) {
        // Slug do URL de vídeo: /video/{slug}
        if (preg_match('/\/video\/([^\/\?]+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
