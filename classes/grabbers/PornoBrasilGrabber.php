<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/AbstractGrabber.php';

/**
 * PornoBrasilGrabber - Extrator de vídeos do pornobrasil.com
 *
 * Site tube com listings server-rendered. A página do vídeo pode expor
 * JSON-LD VideoObject ou meta tags com metadata. O player geralmente serve
 * MP4 direto ou via iframe.
 *
 * Estratégia de download:
 *   1. Fetch HTML e extrair stream_url direto (source/src do player)
 *   2. Fallback: yt-dlp na URL da página
 */
class PornoBrasilGrabber extends AbstractGrabber {
    use DownloadStrategy;

    public function __construct() {
        $this->referer = 'https://pornobrasil.com/';
        parent::__construct();
    }

    public function getSiteName() {
        return 'PornoBrasil';
    }

    public function canHandle($url) {
        return (bool) preg_match('/pornobrasil\\.com/i', $url);
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o PornoBrasil.'
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
        $embedUrl    = '';
        $author      = '';
        $tags        = array();

        // 1. VideoObject JSON-LD
        if (preg_match('/<script[^>]*type="application\\/ld\\+json"[^>]*>(.*?)<\\/script>/si', $html, $ldMatch)) {
            $ld = json_decode(trim($ldMatch[1]), true);
            if ($ld && isset($ld['@graph']) && is_array($ld['@graph'])) {
                foreach ($ld['@graph'] as $item) {
                    if (isset($item['@type']) && $item['@type'] == 'VideoObject') {
                        $title       = isset($item['name']) ? $item['name'] : '';
                        $description = isset($item['description']) ? $item['description'] : '';
                        $streamUrl   = isset($item['contentUrl']) ? $item['contentUrl'] : '';
                        $embedUrl    = isset($item['embedUrl']) ? $item['embedUrl'] : '';
                        $duration    = $this->parseIsoDuration(isset($item['duration']) ? $item['duration'] : '');
                        $thumbnail   = isset($item['thumbnailUrl']) ? (is_array($item['thumbnailUrl']) ? reset($item['thumbnailUrl']) : $item['thumbnailUrl']) : '';
                        break;
                    }
                }
                // Fallback: Article type with duration
                if ($duration <= 0) {
                    foreach ($ld['@graph'] as $item) {
                        if (isset($item['duration']) && $item['duration']) {
                            $duration = $this->parseIsoDuration($item['duration']);
                            if (empty($title) && isset($item['name'])) {
                                $title = $item['name'];
                            }
                            break;
                        }
                    }
                }
            }
            if (!$title && $ld && isset($ld['@type']) && $ld['@type'] == 'VideoObject') {
                $title       = isset($ld['name']) ? $ld['name'] : '';
                $description = isset($ld['description']) ? $ld['description'] : '';
                $streamUrl   = isset($ld['contentUrl']) ? $ld['contentUrl'] : '';
                $embedUrl    = isset($ld['embedUrl']) ? $ld['embedUrl'] : '';
                $duration    = $this->parseIsoDuration(isset($ld['duration']) ? $ld['duration'] : '');
                $thumbnail   = isset($ld['thumbnailUrl']) ? (is_array($ld['thumbnailUrl']) ? reset($ld['thumbnailUrl']) : $ld['thumbnailUrl']) : '';
            }
        }

        // 2. Fallback: meta tags
        if (empty($title) && preg_match('/<title>([^<]+)<\\/title>/i', $html, $m)) {
            $title = trim($m[1]);
        }
        if (empty($description) && preg_match('/<meta[^>]+name="description"[^>]+content="([^"]+)"/i', $html, $m)) {
            $description = trim($m[1]);
        }
        if (empty($thumbnail) && preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            $thumbnail = trim($m[1]);
        }

        // 2b. Duration from <meta itemprop="duration" content="P0DT0H33M35S">
        if ($duration <= 0 && preg_match('/<meta[^>]+itemprop="duration"[^>]+content="(P[^"]+)"/i', $html, $m)) {
            $duration = $this->parseIsoDuration($m[1]);
        }
        if ($duration <= 0 && preg_match('/content="(P[^"]+)"[^>]*itemprop="duration"/i', $html, $m)) {
            $duration = $this->parseIsoDuration($m[1]);
        }

        // 3. Fallback: source tag do player <video> -> <source src="...mp4">
        //    Skip preview trailers (thumb-cdn77.xvideos-cdn.com) — use embed instead
        if (empty($streamUrl) && preg_match('/<source[^>]+src="([^"]+\\.mp4[^"]*)"/i', $html, $m)) {
            $candidate = trim($m[1]);
            if (strpos($candidate, 'xvideos-cdn.com') === false) {
                $streamUrl = $candidate;
            }
        }

        // 4. Extract xvideos embed URL from <iframe>
        if (empty($embedUrl) && preg_match('/<iframe[^>]+src="(https?:\/\/www\.xvideos\.com\/embedframe\/[^"]+)"/i', $html, $iframeMatch)) {
            $embedUrl = $iframeMatch[1];
        }

        // 5. Tags
        if (preg_match_all('/<a[^>]+rel="tag"[^>]*>([^<]+)<\\/a>/i', $html, $tagMatches)) {
            $tags = array_map('trim', $tagMatches[1]);
        }

        // 6. Clean title: remove site name suffixes
        if (!empty($title)) {
            $title = preg_replace('/\\s*[|\\-–]\\s*Porno\\s*Brasil.*$/i', '', $title);
            $title = preg_replace('/\\s*[|\\-–]\\s*pornobrasil.*$/i', '', $title);
        }

        $videoId = '';
        if (preg_match('/video[s]?(\\d+)/', $url, $m)) {
            $videoId = $m[1];
        }

        $tagsStr = implode(', ', array_slice(array_values($tags), 0, 15));
        $qualities = array('best' => 'Melhor Qualidade (Máxima)');

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'PornoBrasil',
            'title'              => $this->sanitizeText($title),
            'description'        => $description,
            'tags'               => $tagsStr,
            'duration'           => $duration,
            'duration_formatted' => $this->formatDuration($duration),
            'thumbnail'          => $thumbnail,
            'qualities'          => $qualities,
            'embed_url'          => $embedUrl,
            'stream_url'         => $streamUrl,
            'author'             => $author,
            'views'              => 0,
            'likes'              => 0,
        );
    }

    public function downloadVideo($url, $targetPath, $quality = 'best') {
        $url = trim($url);
        $info = $this->fetchInfo($url);
        return $this->downloadVideoStandard($url, $targetPath, $quality, $info);
    }
}
