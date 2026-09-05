<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__DIR__) . '/interfaces/DiscoveryProvider.php';
require_once dirname(__DIR__) . '/MassGrabberManager.php';

/**
 * AbstractScrapeProvider - Base comum para os providers de discovery que
 * delegam o scraping a um script Python (curl_cffi + stdlib).
 *
 * Concentra o boilerplate que era duplicado entre XfreeProvider e
 * SonovinhasbrProvider: detecção de Python, execução do scraper com timeout,
 * parsing do JSON de saída, capabilities e normalizeUrl.
 *
 * Cada site implementa apenas o que lhe é específico:
 * getSiteName(), canDiscover(), getExternalId() e o path do script de scrape.
 */
abstract class AbstractScrapeProvider implements DiscoveryProvider {

    /** Script Python responsável pelo scraping do site. */
    protected $scrapeScript = '';

    /** Timeout (segundos) para a execução do scraper. */
    protected $timeout = 60;

    /** Dica exibida na UI quando o provider falha (formato de URL aceito). */
    protected $discoveryHint = '';

    private $pythonBinary = null;

    public function __construct() {
        global $config;

        if ($this->scrapeScript === '') {
            $this->scrapeScript = $config['BASE_DIR'] . '/scripts/' . $this->getScriptName();
        }
        $this->detectPython();
    }

    /** Nome do arquivo do script Python (ex: 'xfree_scrape.py'). */
    abstract protected function getScriptName();

    /** Timeout padrão: subclasses podem sobrescrever em $timeout. */
    protected function getTimeout() {
        return $this->timeout;
    }

    private function detectPython() {
        // curl_cffi vive nas site-packages do python do sistema (brew/system),
        // então qualquer Python 3 encontrado aqui roda o scraper.
        $candidates = array(
            '/opt/homebrew/bin/python3',
            '/usr/local/bin/python3',
            '/usr/bin/python3',
            'python3'
        );
        foreach ($candidates as $bin) {
            $check = @shell_exec("$bin --version 2>&1");
            if ($check && stripos($check, 'Python 3') !== false) {
                $this->pythonBinary = $bin;
                break;
            }
        }
        if (!$this->pythonBinary) {
            $this->pythonBinary = 'python3';
        }
    }

    public function supportsGrab() {
        return true;
    }

    public function supportsMetadata() {
        return true;
    }

    public function supportsVersions() {
        return false;
    }

    public function discover($url, $options = array()) {
        $url = trim($url);
        if (!$this->canDiscover($url)) {
            return $this->errorResult(
                'URL not supported by ' . $this->getSiteName() . ' provider',
                $this->discoveryHint
            );
        }

        $maxPages = isset($options['max_pages']) ? intval($options['max_pages']) : 1;
        $page = isset($options['page']) ? intval($options['page']) : 1;

        $cmd = sprintf(
            '%s %s %s %d %d 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->scrapeScript),
            escapeshellarg($url),
            $page,
            $maxPages
        );

        $output = MassGrabberManager::execWithTimeout($cmd, $this->getTimeout());
        if ($output === false || trim($output) === '') {
            return $this->errorResult(
                'Failed to execute scraper. Check that python3 and curl_cffi are installed.',
                'Install curl_cffi: pip3 install curl_cffi'
            );
        }

        $output = trim($output);
        if (stripos($output, '[EXEC_TIMEOUT') !== false) {
            return $this->errorResult(
                'Scraper timed out after ' . $this->getTimeout() . ' seconds. Try again later.'
            );
        }

        $data = json_decode($output, true);
        if (!$data) {
            return $this->errorResult(
                'Failed to parse scraper output: ' . substr($output, 0, 300)
            );
        }

        if (isset($data['error'])) {
            return $this->errorResult($data['error'], $this->discoveryHint);
        }

        $videos = isset($data['videos']) ? $data['videos'] : array();
        $totalCount = isset($data['total']) ? intval($data['total']) : count($videos);
        $hasMore = isset($data['has_more']) ? (bool) $data['has_more'] : false;

        return array(
            'status'   => true,
            'videos'   => $videos,
            'page'     => $page,
            'has_more' => $hasMore,
            'total'    => $totalCount,
        );
    }

    public function normalizeUrl($url) {
        $parts = parse_url($url);
        if (!$parts) return $url;
        $canonical = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['path'])) $canonical .= rtrim($parts['path'], '/');
        return $canonical;
    }

    /** Monta o resultado de erro padrão usado por todos os providers. */
    protected function errorResult($error, $hint = '') {
        $result = array(
            'status'   => false,
            'error'    => $error,
            'videos'   => array(),
            'page'     => 1,
            'has_more' => false,
            'total'    => 0,
        );
        if (!empty($hint)) {
            $result['hint'] = $hint;
        }
        return $result;
    }
}