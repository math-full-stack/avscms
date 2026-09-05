<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/GrabberInterface.php';

/**
 * AbstractGrabber - Base comum para os grabbers de vídeo.
 *
 * Concentra os helpers compartilhados por todos os grabbers (detecção de
 * Python, execução com timeout, download de thumbnail, saneamento de texto,
 * formatação de duração, parsing ISO 8601 e fetch de HTML), evitando a
 * duplicação que existia entre XfreeGrabber e SonovinhasbrGrabber.
 *
 * Cada site implementa apenas a parte que lhe é específica:
 * getSiteName(), canHandle(), fetchInfo() e downloadVideo().
 */
abstract class AbstractGrabber implements GrabberInterface {

    /** Referer usado nos downloads (curl) e fetch de HTML. */
    protected $referer = '';

    protected $pythonBinary = null;
    protected $ytdlpScript = null;

    public function __construct() {
        global $config;

        $this->ytdlpScript = $config['BASE_DIR'] . '/scripts/yt-dlp';
        $this->detectPython();
    }

    /**
     * Localiza um Python 3.10+ no sistema (requisito do yt-dlp).
     */
    protected function detectPython() {
        $candidates = array(
            '/opt/homebrew/bin/python3',
            '/usr/local/bin/python3',
            '/usr/bin/python3',
            'python3'
        );

        foreach ($candidates as $bin) {
            $check = @shell_exec("$bin --version 2>&1");
            if ($check && stripos($check, 'Python 3') !== false) {
                if (preg_match('/Python 3\.(\d+)/', $check, $m) && (int)$m[1] >= 10) {
                    $this->pythonBinary = $bin;
                    break;
                }
            }
        }

        if (!$this->pythonBinary) {
            $this->pythonBinary = 'python3';
        }
    }

    /**
     * Executa um comando com timeout total em segundos.
     * Retorna a saída ou false se timeout/erro.
     */
    protected function runWithTimeout($cmd, $timeoutSeconds = 300) {
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);

        $output = '';
        $start = time();

        while (true) {
            $read = array($pipes[1], $pipes[2]);
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 1);

            if ($ready === false) {
                break;
            }

            if (time() - $start >= $timeoutSeconds) {
                // Timeout — kill process
                proc_terminate($process, 9);
                proc_close($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                return false;
            }

            foreach ($read as $stream) {
                $chunk = @fread($stream, 8192);
                if ($chunk !== false && $chunk !== '') {
                    $output .= $chunk;
                }
            }

            // Command finished (all streams at EOF): stop instead of spinning
            // until the timeout. EOF on stdout usually means the process exited.
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Drain whatever is still buffered, then stop.
                foreach (array($pipes[1], $pipes[2]) as $stream) {
                    $chunk = @stream_get_contents($stream);
                    if ($chunk !== false && $chunk !== '') {
                        $output .= $chunk;
                    }
                    @fclose($stream);
                }
                proc_close($process);
                return $output;
            }
        }

        // Loop ended without the process exiting (select error) - fall through
        // with whatever output was captured.
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

    /**
     * Executa o yt-dlp em modo info (dump JSON) e devolve o JSON decodificado.
     *
     * Usado por grabbers cujos sites exigem o perfil TLS/extração do yt-dlp
     * (ex.: XFree e SonovinhasBR atrás de proteção) — o fetch de HTML via curl
     * pode ser bloqueado onde o yt-dlp consegue extrair o player.
     *
     * @param string $url
     * @param int    $timeoutSeconds
     * @return array|null JSON decodificado; null em falha/timeout/resposta inválida
     */
    protected function probeYtdlp($url, $timeoutSeconds = 120)
    {
        $cmd = sprintf(
            '%s %s --dump-single-json --no-warnings --skip-download --socket-timeout 30 %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->ytdlpScript),
            escapeshellarg($url)
        );

        $output = $this->runWithTimeout($cmd, $timeoutSeconds);
        if (!$output) {
            return null;
        }

        // O yt-dlp pode misturar warnings no output; extrair o trecho JSON.
        $jsonStart = strpos($output, '{');
        $jsonEnd   = strrpos($output, '}');
        if ($jsonStart === false || $jsonEnd === false) {
            return null;
        }

        $data = json_decode(substr($output, $jsonStart, $jsonEnd - $jsonStart + 1), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Escolhe a melhor URL progressiva (muxada) do JSON do yt-dlp para o
     * player HTML5 — prefere o mp4 muxado de maior altura e cai para a URL
     * principal quando não há lista de formats.
     *
     * @param array $data JSON do yt-dlp (dump-single-json)
     * @return string
     */
    protected function pickBestMuxedUrl($data)
    {
        if (!is_array($data)) {
            return '';
        }

        $fallbackUrl = '';
        if (!empty($data['url'])) {
            $fallbackUrl = (string) $data['url'];
        } elseif (!empty($data['requested_downloads'][0]['url'])) {
            $fallbackUrl = (string) $data['requested_downloads'][0]['url'];
        }

        if (empty($data['formats']) || !is_array($data['formats'])) {
            return $fallbackUrl;
        }

        $bestMuxed  = null;
        $bestHeight = -1;
        foreach ($data['formats'] as $fmt) {
            if (empty($fmt['url']) || !isset($fmt['vcodec']) || $fmt['vcodec'] === 'none') {
                continue;
            }
            if (strpos($fmt['url'], 'https://') !== 0) {
                continue;
            }
            $h      = isset($fmt['height']) ? (int) $fmt['height'] : 0;
            $isMp4  = (isset($fmt['ext']) && $fmt['ext'] === 'mp4');
            if ($h > $bestHeight || ($h === $bestHeight && $isMp4 && $bestMuxed && (!isset($bestMuxed['ext']) || $bestMuxed['ext'] !== 'mp4'))) {
                $bestHeight = $h;
                $bestMuxed  = $fmt;
            }
        }

        if ($bestMuxed && !empty($bestMuxed['url'])) {
            return (string) $bestMuxed['url'];
        }

        return $fallbackUrl;
    }

    public function downloadThumbnail($thumbUrl, $targetPath) {
        if (empty($thumbUrl)) {
            return false;
        }

        $ch = curl_init($thumbUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
        if (!empty($this->referer)) {
            curl_setopt($ch, CURLOPT_REFERER, $this->referer);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $imageData = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200 && !empty($imageData)) {
            $dir = dirname($targetPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            return (bool) file_put_contents($targetPath, $imageData);
        }

        return false;
    }

    /**
     * Fetch page HTML via curl.
     */
    protected function fetchHtml($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
        if (!empty($this->referer)) {
            curl_setopt($ch, CURLOPT_REFERER, $this->referer);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200 && !empty($html)) {
            return $html;
        }
        return false;
    }

    /**
     * Remove emojis e caracteres de 4 bytes que podem causar problemas
     * com bancos MySQL em charset utf8 (3 bytes).
     */
    protected function sanitizeText($text) {
        $text = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $text);
        $text = preg_replace('/[\x{20000}-\x{2FFFF}]/u', '', $text);
        $text = preg_replace('/[\x{FE00}-\x{FE0F}\x{200D}\x{20E3}]/u', '', $text);
        return trim($text);
    }

    /**
     * Formata segundos como HH:MM:SS ou MM:SS.
     */
    protected function formatDuration($seconds) {
        $seconds = (int)$seconds;
        $hours   = floor($seconds / 3600);
        $mins    = floor(($seconds % 3600) / 60);
        $secs    = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
        } else {
            return sprintf('%02d:%02d', $mins, $secs);
        }
    }

    /**
     * Converte duração ISO 8601 (PT00H02M00S) para segundos.
     */
    protected function parseIsoDuration($iso) {
        if (empty($iso)) return 0;
        if (preg_match('/PT(?:(\\d+)H)?(?:(\\d+)M)?(?:(\\d+)S)?/', $iso, $m)) {
            $hours = (int)($m[1] ?? 0);
            $mins  = (int)($m[2] ?? 0);
            $secs  = (int)($m[3] ?? 0);
            return $hours * 3600 + $mins * 60 + $secs;
        }
        return 0;
    }

    /**
     * Executa yt-dlp para baixar o vídeo no caminho alvo.
     * Retorna a saída do comando (ou false em timeout).
     */
    protected function downloadWithYtdlp($url, $targetPath, $formatSelector) {
        $cmd = sprintf(
            '%s %s -f %s --no-warnings --socket-timeout 30 -o %s %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->ytdlpScript),
            escapeshellarg($formatSelector),
            escapeshellarg($targetPath),
            escapeshellarg($url)
        );

        return $this->runWithTimeout($cmd, 300);
    }

    /**
     * Baixa um arquivo direto via curl (stream_url) para o caminho alvo.
     */
    protected function downloadDirect($streamUrl, $targetPath) {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        $ch = curl_init($streamUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        if (!empty($this->referer)) {
            curl_setopt($ch, CURLOPT_REFERER, $this->referer);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200 && !empty($data) && file_put_contents($targetPath, $data) !== false) {
            return true;
        }

        return false;
    }

    /**
     * Trunca logs longos para a mensagem de erro final.
     */
    protected function truncateLog($output, $maxLen = 3000) {
        if (strlen($output) > $maxLen) {
            return substr($output, 0, 1500) . "\n... [truncado] ...\n" . substr($output, -1500);
        }
        return $output;
    }
}