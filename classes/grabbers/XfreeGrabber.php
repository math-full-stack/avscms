<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/GrabberInterface.php';

/**
 * XfreeGrabber - Extrator de vídeos do xfree.com
 *
 * Utiliza o extractor "generic" do yt-dlp (já existente no AVS)
 * para extrair metadados e baixar vídeos de xfree.com.
 */
class XfreeGrabber implements GrabberInterface {

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
                // Verificar se a versão é >= 3.10 (requisito do yt-dlp)
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
        return 'XFree';
    }

    public function canHandle($url) {
        return (bool) preg_match('/xfree\.com/i', $url);
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o XFree.'
            );
        }

        // Usar yt-dlp com extractor nativo do xfree.com (melhor que generic)
        $cmd = sprintf(
            '%s %s --dump-single-json --no-warnings --skip-download --socket-timeout 30 %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->ytdlpScript),
            escapeshellarg($url)
        );

        $output = $this->runWithTimeout($cmd, 120);
        if (!$output) {
            return array(
                'status' => false,
                'error'  => 'Não foi possível extrair dados do vídeo. Verifique o link.'
            );
        }

        // yt-dlp pode misturar warnings no output; extrair JSON
        $jsonStart = strpos($output, '{');
        $jsonEnd   = strrpos($output, '}');
        if ($jsonStart === false || $jsonEnd === false) {
            return array(
                'status' => false,
                'error'  => 'Resposta inválida: ' . substr($output, 0, 300)
            );
        }

        $jsonStr = substr($output, $jsonStart, ($jsonEnd - $jsonStart + 1));
        $data = json_decode($jsonStr, true);

        if (!$data) {
            return array(
                'status' => false,
                'error'  => 'Erro ao decodificar dados do vídeo.'
            );
        }

        // Verificar se tem URL direta
        $videoUrl = isset($data['url']) ? $data['url'] : '';
        if (empty($videoUrl) && !empty($data['requested_downloads'])) {
            $videoUrl = $data['requested_downloads'][0]['url'] ?? '';
        }

        if (empty($videoUrl)) {
            return array(
                'status' => false,
                'error'  => 'Não foi possível encontrar a URL do vídeo.'
            );
        }

        $title       = isset($data['title']) ? $this->sanitizeText(trim($data['title'])) : '';
        $description = isset($data['description']) ? trim($data['description']) : '';
        $duration    = isset($data['duration']) ? (int)$data['duration'] : 0;
        $thumbnail   = isset($data['thumbnail']) ? $data['thumbnail'] : '';

        // Tags
        $tags = array();
        if (!empty($data['tags']) && is_array($data['tags'])) {
            $tags = array_filter($data['tags']);
        }
        $tagsStr = implode(', ', array_slice($tags, 0, 15));

        // Melhor thumbnail
        if (!empty($data['thumbnails']) && is_array($data['thumbnails'])) {
            $maxRes = 0;
            foreach ($data['thumbnails'] as $t) {
                $res = (isset($t['width']) ? (int)$t['width'] : 0) * (isset($t['height']) ? (int)$t['height'] : 0);
                if ($res >= $maxRes && !empty($t['url'])) {
                    $maxRes = $res;
                    $thumbnail = $t['url'];
                }
            }
        }

        // Duração formatada
        $durationFormatted = $this->formatDuration($duration);

        // Extrair qualidades disponíveis a partir dos formatos do yt-dlp
        $qualities = array('best' => 'Melhor Qualidade (Máxima)');
        if (!empty($data['formats']) && is_array($data['formats'])) {
            $heightsFound = array();
            foreach ($data['formats'] as $fmt) {
                if (isset($fmt['height']) && $fmt['height'] > 0
                    && isset($fmt['vcodec']) && $fmt['vcodec'] !== 'none') {
                    $h = (int)$fmt['height'];
                    if (!in_array($h, $heightsFound)) {
                        $heightsFound[] = $h;
                    }
                }
            }
            rsort($heightsFound);
            foreach ($heightsFound as $h) {
                $label = $h . 'p';
                if ($h >= 2160) $label .= ' (4K Ultra HD)';
                elseif ($h >= 1440) $label .= ' (2K Quad HD)';
                elseif ($h >= 1080) $label .= ' (Full HD)';
                elseif ($h >= 720) $label .= ' (HD)';
                elseif ($h >= 480) $label .= ' (SD)';
                $qualities[$h] = $label;
            }
        }

        // Usar melhor formato progressivo (muxado) para stream_url
        if (!empty($data['formats']) && is_array($data['formats'])) {
            $bestMuxed = null;
            $bestHeight = -1;
            foreach ($data['formats'] as $fmt) {
                if (empty($fmt['url']) || !isset($fmt['vcodec']) || $fmt['vcodec'] === 'none') {
                    continue;
                }
                if (strpos($fmt['url'], 'https://') !== 0) {
                    continue;
                }
                $h = isset($fmt['height']) ? (int)$fmt['height'] : 0;
                $isMp4 = (isset($fmt['ext']) && $fmt['ext'] === 'mp4');
                if ($h > $bestHeight || ($h === $bestHeight && $isMp4 && $bestMuxed && $bestMuxed['ext'] !== 'mp4')) {
                    $bestHeight = $h;
                    $bestMuxed = $fmt;
                }
            }
            if ($bestMuxed && !empty($bestMuxed['url'])) {
                $videoUrl = $bestMuxed['url'];
            }
        }

        // Embed URL
        $videoId = '';
        if (preg_match('/[?&]id=(\d+)/', $url, $m)) {
            $videoId = $m[1];
        }
        $embedUrl = $videoId ? ('https://www.xfree.com/embed/' . $videoId) : '';

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'XFree',
            'title'              => $title,
            'description'        => $description,
            'tags'               => $tagsStr,
            'duration'           => $duration,
            'duration_formatted' => $durationFormatted,
            'thumbnail'          => $thumbnail,
            'qualities'          => $qualities,
            'embed_url'          => $embedUrl,
            'stream_url'         => $videoUrl,
            'author'             => isset($data['uploader']) ? $data['uploader'] : '',
            'views'              => isset($data['view_count']) ? (int)$data['view_count'] : 0,
            'likes'              => isset($data['like_count']) ? (int)$data['like_count'] : 0,
        );
    }

    public function downloadVideo($url, $targetPath, $quality = 'best') {
        $url = trim($url);
        global $config;

        // Configuração de formato do yt-dlp
        if ($quality === 'best' || empty($quality)) {
            $formatSelector = 'best[ext=mp4]/best';
        } else {
            $h = (int)$quality;
            $formatSelector = "best[height<={$h}][ext=mp4]/best[height<={$h}]/best[ext=mp4]/best";
        }

        $cmd = sprintf(
            '%s %s -f %s --no-warnings --socket-timeout 30 -o %s %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->ytdlpScript),
            escapeshellarg($formatSelector),
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

        // Fallback: tentar download direto via URL extraída
        $info = $this->fetchInfo($url);
        if ($info['status'] && !empty($info['stream_url'])) {
            $dir = dirname($targetPath);
            if (!is_dir($dir)) @mkdir($dir, 0777, true);

            $ch = curl_init($info['stream_url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            curl_setopt($ch, CURLOPT_REFERER, 'https://www.xfree.com/');
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
            'error'  => 'Falha ao baixar vídeo do XFree: ' . $fullLog
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
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.xfree.com/');
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
     * Remove emojis e caracteres de 4 bytes que podem causar problemas
     * com bancos MySQL em charset utf8 (3 bytes)
     */
    private function sanitizeText($text) {
        // Remove emojis e símbolos Unicode de 4 bytes (U+1F000 a U+1FFFF)
        $text = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $text);
        // Remove outros caracteres de 4 bytes residuais
        $text = preg_replace('/[\x{20000}-\x{2FFFF}]/u', '', $text);
        // Remove variation selectors e combiners
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

    /**
     * Executa um comando com timeout total em segundos.
     * Retorna a saída ou false se timeout/error.
     */
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
                // Timeout — kill process
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
