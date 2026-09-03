<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/GrabberInterface.php';

class YoutubeGrabber implements GrabberInterface {

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
                $this->pythonBinary = $bin;
                break;
            }
        }

        if (!$this->pythonBinary) {
            $this->pythonBinary = 'python3';
        }
    }

    private function getCookiesArg() {
        global $config;

        $cookieFile = isset($config['grabber_cookies']) ? trim($config['grabber_cookies']) : '';
        if ($cookieFile && file_exists($cookieFile)) {
            return ' --cookies ' . escapeshellarg($cookieFile);
        }

        $browser = isset($config['grabber_cookies_browser']) ? trim($config['grabber_cookies_browser']) : '';
        if ($browser) {
            return ' --cookies-from-browser ' . escapeshellarg($browser);
        }

        return '';
    }

    private function getAuthArgs() {
        return $this->getCookiesArg() . $this->getClientArg();
    }

    private function ensurePotProvider() {
        global $config;

        $mode = isset($config['grabber_auth_mode']) ? (int)$config['grabber_auth_mode'] : 3;
        if ($mode !== 3) {
            return;
        }

        $port = 4416;
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($conn) {
            fclose($conn);
            return;
        }

        $script = $config['BASE_DIR'] . '/scripts/start_pot_provider.sh';
        if (file_exists($script)) {
            @shell_exec('bash ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
            usleep(1500000);
        }
    }

    private function getClientArg() {
        global $config;

        // 1 = Cookie File, 2 = Browser, 3 = PO Token (bgutil), 0 = desativado
        $mode = isset($config['grabber_auth_mode']) ? (int)$config['grabber_auth_mode'] : 3;

        if ($mode === 3) {
            $client = isset($config['grabber_player_client']) ? trim($config['grabber_player_client']) : 'mweb';
            $jsRuntime = isset($config['grabber_js_runtime']) && $config['grabber_js_runtime']
                ? ' --js-runtimes ' . escapeshellarg($config['grabber_js_runtime'])
                : ' --js-runtimes node';
            return $jsRuntime . ' --extractor-args ' . escapeshellarg("youtube:player_client={$client};fetch_pot=always");
        }

        return '';
    }

    public function getSiteName() {
        return 'YouTube';
    }

    public function canHandle($url) {
        return (bool) preg_match('/(youtube\.com|youtu\.be)/i', $url);
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o YouTube.'
            );
        }

        $this->ensurePotProvider();

        // NOTE: no --accept-language here - this yt-dlp build does not support
        // that flag (it errors out), which made every YouTube grab fail.
        $cmd = sprintf(
            '%s %s --dump-single-json --no-warnings --skip-download --socket-timeout 30%s %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->ytdlpScript),
            $this->getAuthArgs(),
            escapeshellarg($url)
        );

        $output = $this->runWithTimeout($cmd, 120);
        if (!$output) {
            return array(
                'status' => false,
                'error'  => 'Não foi possível extrair dados do YouTube. Verifique o link e a conexão.'
            );
        }

        $jsonStart = strpos($output, '{');
        $jsonEnd   = strrpos($output, '}');
        if ($jsonStart === false || $jsonEnd === false) {
            return array(
                'status' => false,
                'error'  => 'Resposta inválida do YouTube: ' . substr($output, 0, 200)
            );
        }

        $jsonStr = substr($output, $jsonStart, ($jsonEnd - $jsonStart + 1));
        $data = json_decode($jsonStr, true);

        if (!$data || !isset($data['id'])) {
            return array(
                'status' => false,
                'error'  => 'Erro ao decodificar informações do vídeo do YouTube.'
            );
        }

        $title       = isset($data['title']) ? trim($data['title']) : '';
        $description = isset($data['description']) ? trim($data['description']) : '';
        $duration    = isset($data['duration']) ? (int)$data['duration'] : 0;
        $thumbnail   = isset($data['thumbnail']) ? $data['thumbnail'] : '';

        // Obter tags
        $tags = array();
        if (!empty($data['tags']) && is_array($data['tags'])) {
            $tags = $data['tags'];
        } elseif (!empty($data['categories']) && is_array($data['categories'])) {
            $tags = $data['categories'];
        }
        $tagsStr = implode(', ', array_slice($tags, 0, 15));

        // Obter melhores thumbnails
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

        // Formatar duração (mm:ss ou hh:mm:ss)
        $durationFormatted = $this->formatDuration($duration);

        // Extrair qualidades de vídeo disponíveis
        $qualities = array('best' => 'Melhor Qualidade (Máxima)');
        if (!empty($data['formats']) && is_array($data['formats'])) {
            $heightsFound = array();
            foreach ($data['formats'] as $fmt) {
                if (isset($fmt['height']) && $fmt['height'] > 0 && isset($fmt['vcodec']) && $fmt['vcodec'] !== 'none') {
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

        $videoId  = isset($data['id']) ? $data['id'] : '';
        $embedUrl = $videoId ? ('https://www.youtube-nocookie.com/embed/' . $videoId . '?autoplay=1&enablejsapi=1&rel=0') : '';
        
        // Stream direto (googlevideo) é instável: expira em ~6h, sem CORS e só existe muxado em baixas qualidades.
        // Para preview, priorizamos embed (youtube-nocookie) que é sempre válido. Stream fica como fallback.
        $streamUrl = '';
        if (!empty($data['url']) && filter_var($data['url'], FILTER_VALIDATE_URL)) {
            $streamUrl = $data['url'];
        } elseif (!empty($data['formats']) && is_array($data['formats'])) {
            // Busca o melhor formato progressivo (muxado) com vídeo+áudio, maior altura, https
            $bestMuxed = null;
            $bestHeight = -1;
            foreach ($data['formats'] as $fmt) {
                if (empty($fmt['url']) || !isset($fmt['vcodec']) || $fmt['vcodec'] === 'none' || !isset($fmt['acodec']) || $fmt['acodec'] === 'none') {
                    continue;
                }
                if (strpos($fmt['url'], 'https://') !== 0) {
                    continue;
                }
                $h = isset($fmt['height']) ? (int)$fmt['height'] : 0;
                // Prefere mp4 para compatibilidade com <video>
                $isMp4 = (isset($fmt['ext']) && $fmt['ext'] === 'mp4');
                // Se já temos um, só troca se for maior altura ou se for mp4 e o anterior não for
                if ($h > $bestHeight || ($h === $bestHeight && $isMp4 && $bestMuxed && $bestMuxed['ext'] !== 'mp4')) {
                    $bestHeight = $h;
                    $bestMuxed = $fmt;
                }
            }
            if ($bestMuxed && !empty($bestMuxed['url'])) {
                $streamUrl = $bestMuxed['url'];
            }
        }

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'YouTube',
            'title'              => $title,
            'description'        => $description,
            'tags'               => $tagsStr,
            'duration'           => $duration,
            'duration_formatted' => $durationFormatted,
            'thumbnail'          => $thumbnail,
            'qualities'          => $qualities,
            'embed_url'          => $embedUrl,
            'stream_url'         => $streamUrl
        );
    }

    public function downloadVideo($url, $targetPath, $quality = 'best') {
        $url = trim($url);
        global $config;

        $this->ensurePotProvider();

        // Detecta localização do ffmpeg para garantir merge (necessário quando baixa vídeo+áudio separados)
        $ffmpegBin = isset($config['ffmpeg']) && $config['ffmpeg'] && file_exists($config['ffmpeg']) ? $config['ffmpeg'] : trim(shell_exec('which ffmpeg 2>&1'));
        $ffmpegLocationArg = '';
        if ($ffmpegBin && file_exists($ffmpegBin)) {
            $ffmpegLocationArg = ' --ffmpeg-location ' . escapeshellarg(dirname($ffmpegBin));
        } elseif ($ffmpegBin && file_exists(trim($ffmpegBin))) {
            $ffmpegLocationArg = ' --ffmpeg-location ' . escapeshellarg(dirname(trim($ffmpegBin)));
        }

        // Configuração de formato do yt-dlp
        // Baixa melhor video+audio e junta em mp4
        if ($quality === 'best' || empty($quality)) {
            $formatSelector = 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best';
        } else {
            $h = (int)$quality;
            $formatSelector = "bestvideo[height<={$h}][ext=mp4]+bestaudio[ext=m4a]/bestvideo[height<={$h}]+bestaudio/best[height<={$h}]/best";
        }

        $cmd = sprintf(
            '%s %s -f %s --merge-output-format mp4 --no-warnings --socket-timeout 30%s%s -o %s %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->ytdlpScript),
            escapeshellarg($formatSelector),
            $ffmpegLocationArg,
            $this->getAuthArgs(),
            escapeshellarg($targetPath),
            escapeshellarg($url)
        );

        $output = shell_exec($cmd);

        if (file_exists($targetPath) && filesize($targetPath) > 1024) {
            return array(
                'status'    => true,
                'file_path' => $targetPath,
                'size'      => filesize($targetPath)
            );
        }

        // Se o merge falhou mas os arquivos separados existem (f399 / f140), tenta merge manual via ffmpeg
        $dir = dirname($targetPath);
        $base = basename($targetPath, '.mp4');
        $patternVideo = $dir . '/' . $base . '.f*.mp4';
        $patternAudio = $dir . '/' . $base . '.f*.m4a';
        $videoParts = glob($patternVideo);
        $audioParts = glob($patternAudio);
        // Também tenta padrão yt-dlp sem f: .mp4 e .m4a separados com sufixo
        if (!$videoParts) $videoParts = glob($dir . '/' . $base . '*.mp4');
        if (!$audioParts) $audioParts = glob($dir . '/' . $base . '*.m4a');
        // Filtra para não pegar o próprio target se já existir
        $videoParts = array_filter($videoParts, function($p) use ($targetPath) { return $p !== $targetPath && filesize($p) > 1024; });
        $audioParts = array_filter($audioParts, function($p) { return filesize($p) > 1024; });
        if (!empty($videoParts) && !empty($audioParts) && $ffmpegBin && file_exists($ffmpegBin)) {
            $vPart = reset($videoParts);
            $aPart = reset($audioParts);
            $cmdMerge = sprintf('%s -y -i %s -i %s -c copy %s 2>&1',
                escapeshellarg($ffmpegBin),
                escapeshellarg($vPart),
                escapeshellarg($aPart),
                escapeshellarg($targetPath)
            );
            $mergeOutput = shell_exec($cmdMerge);
            if (file_exists($targetPath) && filesize($targetPath) > 1024) {
                @unlink($vPart);
                @unlink($aPart);
                // Limpa outros temporários
                foreach (glob($dir . '/' . $base . '.*') as $tmp) {
                    if ($tmp !== $targetPath && strpos(basename($tmp), $base) === 0) @unlink($tmp);
                }
                return array(
                    'status'    => true,
                    'file_path' => $targetPath,
                    'size'      => filesize($targetPath)
                );
            }
            // Anexa saída do merge ao log para debug
            $output .= "\n[manual ffmpeg merge] $cmdMerge\n$mergeOutput";
        }

        // Tenta fallback sem restrição de extensão para garantir o download (progressivo, sem necessidade de ffmpeg)
        $cmdFallback = sprintf(
            '%s %s -f "best[ext=mp4]/best" --no-warnings --socket-timeout 30%s%s -o %s %s 2>&1',
            escapeshellarg($this->pythonBinary),
            escapeshellarg($this->ytdlpScript),
            $ffmpegLocationArg,
            $this->getAuthArgs(),
            escapeshellarg($targetPath),
            escapeshellarg($url)
        );
        $outputFallback = shell_exec($cmdFallback);

        if (file_exists($targetPath) && filesize($targetPath) > 1024) {
            return array(
                'status'    => true,
                'file_path' => $targetPath,
                'size'      => filesize($targetPath)
            );
        }

        // Limpa arquivos temporários fragmentados para não acumular
        foreach (glob($dir . '/' . $base . '.*') as $tmp) {
            if ($tmp !== $targetPath && filesize($tmp) < 1024) @unlink($tmp);
        }

        $fullLog = $output . "\n--- fallback ---\n" . $outputFallback;
        // Limita tamanho do erro para não estourar sessão, mas mantém últimas 2000 chars com causa real
        if (strlen($fullLog) > 5000) {
            $fullLog = substr($fullLog, 0, 2000) . "\n... [truncado] ...\n" . substr($fullLog, -2000);
        }
        return array(
            'status' => false,
            'error'  => 'Falha ao baixar vídeo do YouTube: ' . $fullLog
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
     * Run a command with a hard timeout and return its combined output.
     * Returns false on timeout/spawn error (same semantics as XfreeGrabber).
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

            // Stop as soon as the command exits instead of spinning to timeout.
            $status = proc_get_status($process);
            if (!$status['running']) {
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

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }
}
