<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/AbstractGrabber.php';

/**
 * SonovinhasbrGrabber - Extrator de vídeos do sonovinhasbr.com
 *
 * WordPress site with custom ane-player plugin.
 * Extracts metadata from page HTML/JSON-LD and downloads via yt-dlp or direct URL.
 */
class SonovinhasbrGrabber extends AbstractGrabber {

    public function __construct() {
        $this->referer = 'https://www.sonovinhasbr.com/';
        parent::__construct();
    }

    public function getSiteName() {
        return 'SonovinhasBR';
    }

    public function canHandle($url) {
        return (bool) preg_match('/sonovinhasbr\\.com/i', $url);
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
        if (preg_match('/<script[^>]*type="application\\/ld\\+json"[^>]*>\\s*\\{[^}]*"@type"\\s*:\\s*"VideoObject".*?\\}<\\/script>/si', $html, $voMatch)) {
            $voJson = trim(preg_replace('/^<script[^>]*>/', '', preg_replace('/<\\/script>$/', '', $voMatch[0])));
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
        if (empty($title) && preg_match('/<title>([^<]+)<\\/title>/i', $html, $m)) {
            $title = str_replace(' - Só Novinhas BR', '', trim($m[1]));
        }
        if (empty($description) && preg_match('/<meta[^>]+name="description"[^>]+content="([^"]+)"/i', $html, $m)) {
            $description = trim($m[1]);
        }
        if (empty($thumbnail) && preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            $thumbnail = trim($m[1]);
        }

        // 3. Extract embed URL from iframe if not found in JSON-LD
        if (empty($embedUrl) && preg_match('/<iframe[^>]+src="(https?:\\/\\/[^"]*player\\.php[^"]*)"/i', $html, $m)) {
            $embedUrl = str_replace('&amp;', '&', $m[1]);
        }

        // 4. Extract tags
        if (preg_match_all('/<a[^>]+rel="tag"[^>]*>([^<]+)<\\/a>/i', $html, $tagMatches)) {
            $tags = array_map('trim', $tagMatches[1]);
        }

        $videoId = '';
        if (preg_match('/[?&]v=(\\d+)/', $url, $m)) {
            $videoId = $m[1];
        } elseif (preg_match('/[?&]v=(\\d+)/', $embedUrl, $m)) {
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
        $output = $this->downloadWithYtdlp($url, $targetPath, 'best[ext=mp4]/best');

        if (file_exists($targetPath) && filesize($targetPath) > 1024) {
            return array(
                'status'    => true,
                'file_path' => $targetPath,
                'size'      => filesize($targetPath)
            );
        }

        // Strategy 2: Try yt-dlp on the embed URL
        if ($info['status'] && !empty($info['embed_url'])) {
            $output = $this->downloadWithYtdlp($info['embed_url'], $targetPath, 'best[ext=mp4]/best');

            if (file_exists($targetPath) && filesize($targetPath) > 1024) {
                return array(
                    'status'    => true,
                    'file_path' => $targetPath,
                    'size'      => filesize($targetPath)
                );
            }
        }

        // Strategy 3: Direct curl download from stream URL
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
            'error'  => 'Falha ao baixar vídeo do SonovinhasBR: ' . $this->truncateLog($output)
        );
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
                $url = str_replace('\\\\/', '/', $url);
                if (strpos($url, 'http') === 0) {
                    return $url;
                }
            }
        }

        return '';
    }
}