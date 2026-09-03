<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/GrabberInterface.php';

/**
 * SonovinhasbrGrabber - Extrator de vídeos do sonovinhasbr.com
 *
 * WordPress site with custom ane-player plugin.
 * Extracts metadata from page HTML/JSON-LD and downloads via yt-dlp or direct URL.
 */
class SonovinhasbrGrabber implements GrabberInterface {

    private $pythonBinary = null;
    private $ytdlpScript = null;

    public function __construct() {
        global $config;

        $this->ytdlpScript = $config['BASE_DIR'] . '/scripts/yt-dlp';
        $this->detectPython();
    }

    private function detectPython() {
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

    public function getSiteName() {
        return 'SonovinhasBR';
    }

    public function canHandle($url) {
        return (bool) preg_match('/sonovinhasbr\.com/i', $url);
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o SonovinhasBR.'
            );
        }

        // Fetch the video page HTML
        $html = $this->fetchHtml($url);
        if (!$html) {
            return array(
                'status' => false,
                'error'  => 'Não foi possível acessar a página do vídeo.'
            );
        }

        $title       = '';
        $description = '';
        $thumbnail   = '';
        $duration    = 0;
        $embedUrl    = '';
        $tags        = array();

        // 1. Try VideoObject JSON-LD
        if (preg_match('/<script[^>]*type="application\/ld\+json"[^>]*>\s*\{[^}]*"@type"\s*:\s*"VideoObject".*?\}<\/script>/si', $html, $voMatch)) {
            $voJson = trim(preg_replace('/^<script[^>]*>/', '', preg_replace('/<\/script>$/', '', $voMatch[0])));
            $vo = json_decode($voJson, true);
            if ($vo) {
                $title       = isset($vo['name']) ? $vo['name'] : '';
                $description = isset($vo['description']) ? $vo['description'] : '';
                $thumbnail   = isset($vo['thumbnailUrl']) ? $vo['thumbnailUrl'] : '';
                $embedUrl    = isset($vo['embedUrl']) ? $vo['embedUrl'] : '';
                $duration    = $this->parseIsoDuration(isset($vo['duration']) ? $vo['duration'] : '');
            }
        }

        // 2. Fallback: meta tags
        if (empty($title) && preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
            $title = str_replace(' - Só Novinhas BR', '', trim($m[1]));
        }
        if (empty($description) && preg_match('/<meta[^>]+name="description"[^>]+content="([^"]+)"/i', $html, $m)) {
            $description = trim($m[1]);
        }
        if (empty($thumbnail) && preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            $thumbnail = trim($m[1]);
        }

        // 3. Extract embed URL from iframe if not found in JSON-LD
        if (empty($embedUrl) && preg_match('/<iframe[^>]+src="(https?:\/\/[^"]*player\.php[^"]*)"/i', $html, $m)) {
            $embedUrl = str_replace('&amp;', '&', $m[1]);
        }

        // 4. Extract tags
        if (preg_match_all('/<a[^>]+rel="tag"[^>]*>([^<]+)<\/a>/i', $html, $tagMatches)) {
            $tags = array_map('trim', $tagMatches[1]);
        }

        $videoId = '';
        if (preg_match('/[?&]v=(\d+)/', $url, $m)) {
            $videoId = $m[1];
        } elseif (preg_match('/[?&]v=(\d+)/', $embedUrl, $m)) {
            $videoId = $m[1];
        }

        // Try to get stream URL from the player page
        $streamUrl = '';
        if (!empty($embedUrl)) {
            $streamUrl = $this->extractStreamUrl($embedUrl);
        }

        $tagsStr = implode(', ', array_slice($tags, 0, 15));
        $durationFormatted = $this->formatDuration($duration);
        $qualities = array('best' => 'Melhor Qualidade (Máxima)');

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'SonovinhasBR',
            'title'              => $this->sanitizeText($title),
            'description'        => $description,
            'tags'               => $tagsStr,
            'duration'           => $duration,
            'duration_formatted' => $durationFormatted,
            'thumbnail'          => $thumbnail,
            'qualities'          => $qualities,
            'embed_url'          => $embedUrl,
            'stream_url'         => $streamUrl,
            'author'             => '',
            'views'              => 0,
            'likes'              => 0,
        );
    }

    public function downloadVideo($url, $targetPath, $quality = 'best') {
        $url = trim($url);

        // First, get info to find stream URL
        $info = $this->fetchInfo($url);

        // Strategy 1: Try yt-dlp on the page URL
        $cmd = sprintf(
            '%s %s -f "best[ext=mp4]/best" --no-warnings --socket-timeout 30 -o %s %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->ytdlpScript),
            escapeshellarg($targetPath),
            escapeshellarg($url)
        );

        $output = $this->runWithTimeout($cmd, 300);

        if (file_exists($targetPath) && filesize($targetPath) > 1024) {
            return array(
                'status'    => true,
                'file_path' => $targetPath,
                'size'      => filesize($targetPath)
            );
        }

        // Strategy 2: Try yt-dlp on the embed URL
        if ($info['status'] && !empty($info['embed_url'])) {
            $cmd = sprintf(
                '%s %s -f "best[ext=mp4]/best" --no-warnings --socket-timeout 30 -o %s %s 2>&1',
                escapeshellarg($this->pythonBinary),
                escapeshellarg($this->ytdlpScript),
                escapeshellarg($targetPath),
                escapeshellarg($info['embed_url'])
            );
            $output = $this->runWithTimeout($cmd, 300);

            if (file_exists($targetPath) && filesize($targetPath) > 1024) {
                return array(
                    'status'    => true,
                    'file_path' => $targetPath,
                    'size'      => filesize($targetPath)
                );
            }
        }

        // Strategy 3: Direct curl download from stream URL
        if ($info['status'] && !empty($info['stream_url'])) {
            $dir = dirname($targetPath);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);

            $ch = curl_init($info['stream_url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_REFERER, 'https://www.sonovinhasbr.com/');
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode == 200 && !empty($data) && file_put_contents($targetPath, $data) !== false) {
                return array(
                    'status'    => true,
                    'file_path' => $targetPath,
                    'size'      => filesize($targetPath)
                );
            }
        }

        $fullLog = $output;
        if (strlen($fullLog) > 3000) {
            $fullLog = substr($fullLog, 0, 1500) . "\n... [truncado] ...\n" . substr($fullLog, -1500);
        }

        return array(
            'status' => false,
            'error'  => 'Falha ao baixar vídeo do SonovinhasBR: ' . $fullLog
        );
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
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.sonovinhasbr.com/');
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
     * Fetch page HTML via curl
     */
    private function fetchHtml($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
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
     * Extract the actual video stream URL from the player.php page
     */
    private function extractStreamUrl($playerUrl) {
        $html = $this->fetchHtml($playerUrl);
        if (!$html) return '';

        // Look for video source URLs in the player page
        // Common patterns: .m3u8, .mp4, video sources
        $patterns = array(
            '/(?:file|src|source|url)\s*[:=]\s*["\']([^"\']+\.(?:mp4|m3u8)[^"\']*)["\']/i',
            '/<source[^>]+src="([^"]+\.(?:mp4|m3u8)[^"]*)"/i',
            '/video_url\s*[:=]\s*["\']([^"\']+)["\']/i',
            '/"videoUrl"\s*:\s*"([^"]+)"/i',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $url = $m[1];
                $url = str_replace('\\/', '/', $url);
                if (strpos($url, 'http') === 0) {
                    return $url;
                }
            }
        }

        return '';
    }

    /**
     * Parse ISO 8601 duration (PT00H02M00S) to seconds
     */
    private function parseIsoDuration($iso) {
        if (empty($iso)) return 0;
        if (preg_match('/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $iso, $m)) {
            $hours = (int)($m[1] ?? 0);
            $mins  = (int)($m[2] ?? 0);
            $secs  = (int)($m[3] ?? 0);
            return $hours * 3600 + $mins * 60 + $secs;
        }
        return 0;
    }

    private function sanitizeText($text) {
        $text = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $text);
        $text = preg_replace('/[\x{20000}-\x{2FFFF}]/u', '', $text);
        $text = preg_replace('/[\x{FE00}-\x{FE0F}\x{200D}\x{20E3}]/u', '', $text);
        return trim($text);
    }

    private function formatDuration($seconds) {
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

    private function runWithTimeout($cmd, $timeoutSeconds = 300) {
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
                proc_terminate($process, 9);
                proc_close($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                return false;
            }

            foreach ($read as $stream) {
                $chunk = @fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $output .= $chunk;
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }
}
