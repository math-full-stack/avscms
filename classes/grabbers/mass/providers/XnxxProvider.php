<?php
require_once dirname(__FILE__) . '/AbstractScrapeProvider.php';

class XnxxProvider extends AbstractScrapeProvider
{
    protected $timeout = 180;
    protected $discoveryHint = 'Ex.: https://www.xnxx.com/hits, https://www.xnxx.com/best ou https://www.xnxx.com/search/termo';

    protected function getScriptName()
    {
        return 'xnxx_scrape.py';
    }

    public function getSiteName()
    {
        return 'XNXX';
    }

    public function canDiscover($url)
    {
        return (bool) preg_match('/xnxx\.com/i', $url);
    }

    public function getExternalId($url)
    {
        if (preg_match('/video-([a-z0-9]+)/i', $url, $m)) {
            return $m[1];
        }
        return '';
    }
}
