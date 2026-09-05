<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/providers/AbstractScrapeProvider.php';

/**
 * PornolandiaProvider - Discovers videos from pornolandia.xxx listing pages.
 *
 * pornolandia.xxx é um tube em HTML simples (sem SPA, sem Cloudflare).
 * O scraper Python (curl_cffi + stdlib) lê a grade de vídeos das páginas de
 * listagem (/videos?page=N ou /videos/{categoria}/N) e enriquece cada item
 * com o JSON-LD VideoObject da página do vídeo (duração, tags, descrição).
 */
class PornolandiaProvider extends AbstractScrapeProvider {

    protected $timeout = 60;

    protected $discoveryHint = 'Pornolandia discovery works with listing/category URLs (e.g. https://www.pornolandia.xxx/videos?page=1 or https://www.pornolandia.xxx/videos/novinhas/)';

    protected function getScriptName() {
        return 'pornolandia_scrape.py';
    }

    public function getSiteName() {
        return 'Pornolandia';
    }

    public function canDiscover($url) {
        return (bool) preg_match('/pornolandia\\.xxx/i', $url);
    }

    public function getExternalId($url) {
        // ID numérico do URL de vídeo: /video/{id}/{slug}
        if (preg_match('/\\/video\\/(\\d+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}