<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/AbstractGrabber.php';

/**
 * PornolandiaGrabber - Extrator de vídeos do pornolandia.xxx
 *
 * Site de tube em HTML simples (sem SPA). A página do vídeo expõe um
 * JSON-LD VideoObject com metadata completa (nome, descrição, duração ISO,
 * miniatura e contentUrl direto em MP4), além das tags em links <a class="tag">.
 *
 * Estratégia de download:
 *   1. Download direto do MP4 (contentUrl) via curl — mais rápido e confiável
 *      que yt-dlp, pois o site serve o arquivo final direto.
 *   2. Fallback: yt-dlp no extractor generic/html5.
 */
class PornolandiaGrabber extends AbstractGrabber {

    public function __construct() {
        $this->referer = 'https://www.pornolandia.xxx/';
        parent::__construct();
    }

    public function getSiteName() {
        return 'Pornolandia';
    }

    public function canHandle($url) {
        return (bool) preg_match('/pornolandia\\.xxx/i', $url);
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o Pornolandia.'
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
        $author      = '';
        $tags        = array();

        // 1. VideoObject JSON-LD (fonte primária de metadata)
        if (preg_match('/<script[^>]*type="application\\/ld\\+json"[^>]*>(.*?)<\\/script>/si', $html, $ldMatch)) {
            $ld = json_decode(trim($ldMatch[1]), true);
            if ($ld && $ld['@type'] == 'VideoObject') {
                $title       = isset($ld['name']) ? $ld['name'] : '';
                $description = isset($ld['description']) ? $ld['description'] : '';
                $streamUrl   = isset($ld['contentUrl']) ? $ld['contentUrl'] : '';
                $author      = isset($ld['author']) ? $ld['author'] : '';
                $duration    = $this->parseIsoDuration(isset($ld['duration']) ? $ld['duration'] : '');

                $thumbs = isset($ld['thumbnailUrl']) ? $ld['thumbnailUrl'] : array();
                if (is_array($thumbs)) {
                    $thumbnail = !empty($thumbs) ? $thumbs[0] : '';
                } else {
                    $thumbnail = (string)$thumbs;
                }
            }
        }

        // 2. Fallback: meta tags
        if (empty($title) && preg_match('/<h1[^>]*>(.*?)<\\/h1>/si', $html, $m)) {
            $title = $this->sanitizeText(trim(strip_tags($m[1])));
        }
        if (empty($title) && preg_match('/<title>([^<]+)<\\/title>/i', $html, $m)) {
            $title = str_replace(' - Pornolandia', '', trim($m[1]));
        }
        if (empty($description) && preg_match('/<meta[^>]+name="description"[^>]+content="([^"]+)"/i', $html, $m)) {
            $description = trim($m[1]);
        }
        if (empty($thumbnail) && preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            $thumbnail = trim($m[1]);
        }

        // 3. Fallback: source tag do player <video> -> <source src="...mp4">
        if (empty($streamUrl) && preg_match('/<source[^>]+src="([^"]+\\.mp4[^"]*)"/i', $html, $m)) {
            $streamUrl = trim($m[1]);
        }

        // 4. Tags: links com classe "tag" para /videos/{slug}/ (categorias do conteúdo)
        if (preg_match_all('/<a[^>]+class="tag[^"]*"[^>]+href="\\/videos\\/[a-z0-9\\-]+\\/"[^>]*>([^<]+)<\\/a>/i', $html, $tagMatches)) {
            $tags = array_map('trim', $tagMatches[1]);
            $tags = array_filter($tags, function ($t) {
                return stripos($t, 'mais videos') === false && stripos($t, 'porno') === false;
            });
        }

        // Extrair ID numérico do URL (/video/{id}/{slug})
        $videoId = '';
        if (preg_match('/\\/video\\/(\\d+)/', $url, $m)) {
            $videoId = $m[1];
        }

        $tagsStr = implode(', ', array_slice(array_values($tags), 0, 15));
        $qualities = array('best' => 'Melhor Qualidade (Máxima)');

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'Pornolandia',
            'title'              => $this->sanitizeText($title),
            'description'        => $description,
            'tags'               => $tagsStr,
            'duration'           => $duration,
            'duration_formatted' => $this->formatDuration($duration),
            'thumbnail'          => $thumbnail,
            'qualities'          => $qualities,
            'embed_url'          => '',
            'stream_url'         => $streamUrl,
            'author'             => $author,
            'views'              => 0,
            'likes'              => 0,
        );
    }

    public function downloadVideo($url, $targetPath, $quality = 'best') {
        $url = trim($url);

        // Estratégia 1: download direto do MP4 (contentUrl do JSON-LD)
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

        // Estratégia 2: yt-dlp como fallback
        $output = $this->downloadWithYtdlp($url, $targetPath, 'best[ext=mp4]/best');

        if (file_exists($targetPath) && filesize($targetPath) > 1024) {
            return array(
                'status'    => true,
                'file_path' => $targetPath,
                'size'      => filesize($targetPath)
            );
        }

        return array(
            'status' => false,
            'error'  => 'Falha ao baixar vídeo do Pornolandia: ' . $this->truncateLog($output)
        );
    }
}