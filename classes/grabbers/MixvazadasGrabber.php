<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/AbstractGrabber.php';

/**
 * MixvazadasGrabber - Extrator de vídeos do mixvazadas.com
 *
 * Site Astro-based (SSG) com HTML server-rendered, sem Cloudflare.
 * A página do vídeo expõe meta tags (og:title, og:description, og:image)
 * e o player com <source src> apontando para MP4 direto (qu.ax).
 * Tags ficam em links <a href="/tag/{tag}" class="tag">.
 *
 * Estratégia de download:
 *   1. Download direto do MP4 (qu.ax) via curl — mais rápido e confiável.
 *   2. Fallback: yt-dlp no extractor genérico.
 */
class MixvazadasGrabber extends AbstractGrabber {
    use DownloadStrategy;

    public function __construct() {
        $this->referer = 'https://mixvazadas.com/';
        parent::__construct();
    }

    public function getSiteName() {
        return 'Mixvazadas';
    }

    public function canHandle($url) {
        return (bool) preg_match('/mixvazadas\.com/i', $url);
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o Mixvazadas.'
            );
        }

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
        $streamUrl   = '';
        $streamUrl2  = '';
        $tags        = array();

        // 1. Meta tags — fonte primária de metadata
        if (preg_match('/<meta[^>]+property="og:title"[^>]+content="([^"]+)"/i', $html, $m)) {
            $raw = trim($m[1]);
            $title = preg_replace('/^Mixvazadas\s*-\s*/i', '', $raw);
        }
        if (empty($title) && preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
            $raw = trim($m[1]);
            $title = preg_replace('/^Mixvazadas\s*-\s*/i', '', $raw);
        }

        if (preg_match('/<meta[^>]+property="og:description"[^>]+content="([^"]+)"/i', $html, $m)) {
            $description = trim($m[1]);
        }
        if (empty($description) && preg_match('/<meta[^>]+name="description"[^>]+content="([^"]+)"/i', $html, $m)) {
            $description = trim($m[1]);
        }

        if (preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            $thumbnail = trim($m[1]);
            if (strpos($thumbnail, '/') === 0) {
                $thumbnail = 'https://mixvazadas.com' . $thumbnail;
            }
        }

        // 2. Stream URL — <source src="https://qu.ax/...mp4">
        if (preg_match('/<source[^>]+src="(https?:\/\/[^"]+\.mp4[^"]*)"/i', $html, $m)) {
            $streamUrl = trim($m[1]);
        }

        // 3. Stream URL backup — variável JS secondaryVideoUrl
        if (preg_match('/secondaryVideoUrl\s*=\s*["\']([^"\']+)["\']/', $html, $m)) {
            $streamUrl2 = trim($m[1]);
        }

        // 4. Fallback: yt-dlp probe (se nenhum source encontrado no HTML)
        if (empty($streamUrl)) {
            $data = $this->probeYtdlp($url, 120);
            if (is_array($data)) {
                if ($title === '') {
                    $title = isset($data['title']) ? $this->sanitizeText(trim($data['title'])) : '';
                }
                if ($thumbnail === '') {
                    $thumbnail = isset($data['thumbnail']) ? $data['thumbnail'] : '';
                }
                if ($duration == 0) {
                    $duration = isset($data['duration']) ? (int) $data['duration'] : 0;
                }
                $streamUrl = $this->pickBestMuxedUrl($data);
            }
        }

        // 5. Tags: links com href="/tag/..."
        if (preg_match_all('/<a[^>]+href="\/tag\/[^"]*"[^>]*class="tag[^"]*"[^>]*>([^<]+)<\/a>/i', $html, $tagMatches)) {
            $tags = array_map('trim', $tagMatches[1]);
        } elseif (preg_match_all('/<a[^>]+class="tag[^"]*"[^>]+href="\/tag\/[^"]*"[^>]*>([^<]+)<\/a>/i', $html, $tagMatches)) {
            $tags = array_map('trim', $tagMatches[1]);
        }

        // Fallback: extrair slug do URL como ID
        $videoId = '';
        if (preg_match('/\/video\/([^\/\?]+)/', $url, $m)) {
            $videoId = $m[1];
        }

        if ($title === '' && $streamUrl === '') {
            return array(
                'status' => false,
                'error'  => 'Não foi possível extrair o vídeo (página bloqueada ou player protegido).'
            );
        }

        $tagsStr = implode(', ', array_slice(array_filter($tags), 0, 15));
        $qualities = array('best' => 'Melhor Qualidade (Máxima)');

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'Mixvazadas',
            'title'              => $this->sanitizeText($title),
            'description'        => $description,
            'tags'               => $tagsStr,
            'duration'           => $duration,
            'duration_formatted' => $this->formatDuration($duration),
            'thumbnail'          => $thumbnail,
            'qualities'          => $qualities,
            'embed_url'          => '',
            'stream_url'         => $streamUrl,
            'stream_url_backup'  => $streamUrl2,
            'author'             => '',
            'views'              => 0,
            'likes'              => 0,
        );
    }

    public function downloadVideo($url, $targetPath, $quality = 'best') {
        $url = trim($url);
        $info = $this->fetchInfo($url);

        // Se temos stream_url_backup, tentar primeiro o primário e usar backup como fallback
        if (!empty($info['stream_url_backup']) && !empty($info['stream_url'])) {
            $result = $this->tryDirectDownloadSingle($info['stream_url'], $targetPath);
            if ($result['status']) return $result;

            $result = $this->tryDirectDownloadSingle($info['stream_url_backup'], $targetPath);
            if ($result['status']) return $result;
        }

        return $this->downloadVideoStandard($url, $targetPath, $quality, $info);
    }

    /**
     * Tenta download direto de um único stream URL via curl.
     */
    private function tryDirectDownloadSingle($streamUrl, $targetPath) {
        if (preg_match('/\.(m3u8|m3u|mpd|pls|xspf)(\?|#|$)/i', $streamUrl)) {
            return array('status' => false);
        }
        if ($this->downloadDirect($streamUrl, $targetPath)
            && file_exists($targetPath) && filesize($targetPath) > 1024) {
            $head = @file_get_contents($targetPath, false, null, 0, 16);
            if ($head !== false && strncmp(ltrim($head), '#EXTM3U', 7) === 0) {
                @unlink($targetPath);
                return array('status' => false);
            }
            return array(
                'status'    => true,
                'file_path' => $targetPath,
                'size'      => filesize($targetPath)
            );
        }
        @unlink($targetPath);
        return array('status' => false);
    }
}
