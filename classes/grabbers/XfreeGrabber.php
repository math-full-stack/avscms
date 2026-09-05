<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/AbstractGrabber.php';

/**
 * XfreeGrabber - Extrator de vídeos do xfree.com
 *
 * Utiliza o extractor "generic" do yt-dlp (já existente no AVS)
 * para extrair metadados e baixar vídeos de xfree.com.
 */
class XfreeGrabber extends AbstractGrabber {

    public function __construct() {
        $this->referer = 'https://www.xfree.com/';
        parent::__construct();
    }

    public function getSiteName() {
        return 'XFree';
    }

    public function canHandle($url) {
        return (bool) preg_match('/xfree\\.com/i', $url);
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
        if (preg_match('/[?&]id=(\\d+)/', $url, $m)) {
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

        // Configuração de formato do yt-dlp
        if ($quality === 'best' || empty($quality)) {
            $formatSelector = 'best[ext=mp4]/best';
        } else {
            $h = (int)$quality;
            $formatSelector = "best[height<={$h}][ext=mp4]/best[height<={$h}]/best[ext=mp4]/best";
        }

        $output = $this->downloadWithYtdlp($url, $targetPath, $formatSelector);

        if (file_exists($targetPath) && filesize($targetPath) > 1024) {
            return array(
                'status'    => true,
                'file_path' => $targetPath,
                'size'      => filesize($targetPath)
            );
        }

        // Fallback: tentar download direto via URL extraída
        $info = $this->fetchInfo($url);
        if ($info['status'] && !empty($info['stream_url'])
            && $this->downloadDirect($info['stream_url'], $targetPath)
            && file_exists($targetPath) && filesize($targetPath) > 1024) {
            return array(
                'status'    => true,
                'file_path' => $targetPath,
                'size'      => filesize($targetPath)
            );
        }

        return array(
            'status' => false,
            'error'  => 'Falha ao baixar vídeo do XFree: ' . $this->truncateLog($output)
        );
    }
}