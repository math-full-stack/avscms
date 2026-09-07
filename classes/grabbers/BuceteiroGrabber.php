<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/AbstractGrabber.php';

/**
 * BuceteiroGrabber - Extrator de vídeos do buceteiro.com
 *
 * Site WordPress com Yoast SEO. A página do vídeo expõe JSON-LD VideoObject
 * com metadata e o player usa iframe de playernc.com com UUID.
 *
 * Segue o padrão XfreeGrabber:
 *   1. Busca HTML da página do vídeo para metadata (JSON-LD, tags, thumbnail).
 *   2. Extrai UUID do iframe e monta URL do player playernc.com.
 *   3. Usa yt-dlp (extractor genérico) no player URL para obter stream e formatos.
 *   4. downloadVideo() delega para downloadVideoStandard() via DownloadStrategy.
 */
class BuceteiroGrabber extends AbstractGrabber {
    use DownloadStrategy;

    public function __construct() {
        $this->referer = 'https://buceteiro.com/';
        parent::__construct();
    }

    public function getSiteName() {
        return 'Buceteiro';
    }

    public function canHandle($url) {
        return (bool) preg_match('/buceteiro\.com/i', $url);
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o Buceteiro.'
            );
        }

        // 1. Buscar página do vídeo para metadata (JSON-LD, tags, UUID)
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
        $tags        = array();
        $category    = '';

        // 1a. VideoObject JSON-LD (fonte primária de metadata)
        if (preg_match('/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/si', $html, $ldMatch)) {
            $ld = json_decode(trim($ldMatch[1]), true);
            if ($ld && isset($ld['@type']) && $ld['@type'] == 'VideoObject') {
                $title       = isset($ld['name']) ? $ld['name'] : '';
                $description = isset($ld['description']) ? $ld['description'] : '';
                $duration    = $this->parseIsoDuration(isset($ld['duration']) ? $ld['duration'] : '');

                $thumbs = isset($ld['thumbnailUrl']) ? $ld['thumbnailUrl'] : array();
                if (is_array($thumbs)) {
                    $thumbnail = !empty($thumbs) ? $thumbs[0] : '';
                } else {
                    $thumbnail = (string) $thumbs;
                }
            }
        }

        // 1b. Segundo JSON-LD (Article) — keywords (tags) e articleSection (categoria)
        preg_match_all('/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/si', $html, $allLd);
        if (!empty($allLd[1])) {
            foreach ($allLd[1] as $ldBlock) {
                $ld = json_decode(trim($ldBlock), true);
                if (!$ld || !isset($ld['@type'])) continue;

                if ($ld['@type'] == 'Article' || $ld['@type'] == 'WebPage') {
                    if (empty($tags) && isset($ld['keywords']) && is_array($ld['keywords'])) {
                        $tags = $ld['keywords'];
                    }
                    if (empty($category) && isset($ld['articleSection'])) {
                        $cat = is_array($ld['articleSection']) ? $ld['articleSection'][0] : $ld['articleSection'];
                        $category = trim($cat);
                    }
                }
            }
        }

        // 1c. Fallback: meta tags
        if (empty($title) && preg_match('/<meta[^>]+property="og:title"[^>]+content="([^"]+)"/i', $html, $m)) {
            $raw = trim($m[1]);
            $title = preg_replace('/^Buceteiro\s*-\s*/i', '', $raw);
        }
        if (empty($title) && preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
            $raw = trim($m[1]);
            $title = preg_replace('/\s*[\|\-]\s*Buceteiro.*$/i', '', $raw);
        }
        if (empty($description) && preg_match('/<meta[^>]+name="description"[^>]+content="([^"]+)"/i', $html, $m)) {
            $description = trim($m[1]);
        }
        if (empty($thumbnail) && preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            $thumbnail = trim($m[1]);
        }

        // 1d. Tags: links em <section class="trends">
        if (preg_match_all('/<a[^>]+href="[^"]*\/tag-porno\/[^"]*"[^>]*>([^<]+)<\/a>/i', $html, $tagMatches)) {
            $tags = array_merge($tags, array_map('trim', $tagMatches[1]));
        }

        // Filtrar tags genéricas
        $tags = array_filter($tags, function ($t) {
            $low = strtolower(trim($t));
            return $low !== '' && $low !== 'mais videos' && $low !== 'videos porno'
                && $low !== 'porno' && $low !== 'xxx' && $low !== 'sexo';
        });

        // 1e. Slug do URL como ID externo
        $videoId = '';
        if (preg_match('/\/([^\/\?]+)\/?$/', $url, $m) && $m[1] !== 'page') {
            $videoId = $m[1];
        }

        // 2. Extrair UUID do iframe e montar URL do player
        $uuid = '';
        if (preg_match('/playernc\.com\/player\/player\.php\?uuid=([a-f0-9\-]+)/i', $html, $m)) {
            $uuid = $m[1];
        }

        if (empty($uuid)) {
            return array(
                'status' => false,
                'error'  => 'Não foi possível extrair o UUID do player.'
            );
        }

        $playerUrl = 'https://playernc.com/player/player.php?uuid=' . $uuid;

        // 3. Usar yt-dlp no player URL para obter stream e formatos (padrão XFree)
        $data = $this->probeYtdlp($playerUrl, 120);
        if ($data === null) {
            return array(
                'status' => false,
                'error'  => 'Não foi possível extrair dados do vídeo via yt-dlp.'
            );
        }

        $streamUrl = $this->pickBestMuxedUrl($data);
        if ($streamUrl === '') {
            return array(
                'status' => false,
                'error'  => 'Não foi possível encontrar a URL do vídeo.'
            );
        }

        // Enriquecer metadata do yt-dlp se JSON-LD não trouxe
        if ($title === '') {
            $title = isset($data['title']) ? $this->sanitizeText(trim($data['title'])) : '';
        }
        if ($duration == 0) {
            $duration = isset($data['duration']) ? (int) $data['duration'] : 0;
        }
        if ($thumbnail === '' && !empty($data['thumbnail'])) {
            $thumbnail = $data['thumbnail'];
        }

        // Tags do yt-dlp como fallback
        if (empty($tags) && !empty($data['tags']) && is_array($data['tags'])) {
            $tags = array_filter($data['tags']);
        }

        // Extrair qualidades disponíveis a partir dos formatos do yt-dlp (padrão XFree)
        $qualities = array('best' => 'Melhor Qualidade (Máxima)');
        if (!empty($data['formats']) && is_array($data['formats'])) {
            $heightsFound = array();
            foreach ($data['formats'] as $fmt) {
                if (isset($fmt['height']) && $fmt['height'] > 0
                    && isset($fmt['vcodec']) && $fmt['vcodec'] !== 'none') {
                    $h = (int) $fmt['height'];
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

        $tagsStr = implode(', ', array_slice(array_values(array_unique($tags)), 0, 15));

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'Buceteiro',
            'title'              => $this->sanitizeText($title),
            'description'        => $description,
            'tags'               => $tagsStr,
            'duration'           => $duration,
            'duration_formatted' => $this->formatDuration($duration),
            'thumbnail'          => $thumbnail,
            'qualities'          => $qualities,
            'embed_url'          => !empty($uuid) ? 'buceteiro_proxy.php?uuid=' . $uuid : '',
            'stream_url'         => $streamUrl,
            'author'             => isset($data['uploader']) ? $data['uploader'] : '',
            'category'           => $category,
            'views'              => isset($data['view_count']) ? (int) $data['view_count'] : 0,
            'likes'              => isset($data['like_count']) ? (int) $data['like_count'] : 0,
        );
    }

    public function downloadVideo($url, $targetPath, $quality = 'best') {
        $url = trim($url);
        $info = $this->fetchInfo($url);
        return $this->downloadVideoStandard($url, $targetPath, $quality, $info);
    }
}
