<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/AbstractGrabber.php';

/**
 * PornoMineiroGrabber - Extrator de vídeos do pornomineiro.com
 *
 * Site WordPress com listings server-rendered. A página do vídeo expõe um
 * JSON-LD VideoObject com metadata completa (nome, descrição, duração ISO,
 * miniatura) e o player é servido via /embed/{post_id}.
 *
 * Estratégia de download:
 *   1. yt-dlp na embed URL (player page)
 *   2. Fallback: yt-dlp na URL da página do vídeo
 */
class PornoMineiroGrabber extends AbstractGrabber {
    use DownloadStrategy;

    /**
     * O CDN do site recusa (403) o download do MP4 quando a requisicao chega
     * com o Referer do proprio site; sem Referer o mesmo arquivo responde 200.
     * O fetch de HTML continua enviando o Referer normalmente.
     */
    protected $directDownloadReferer = false;

    public function __construct() {
        $this->referer = 'https://www.pornomineiro.com/';
        parent::__construct();
    }

    public function getSiteName() {
        return 'PornoMineiro';
    }

    public function canHandle($url) {
        return (bool) preg_match('/pornomineiro\\.com/i', $url);
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o PornoMineiro.'
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
        $embedUrl    = '';
        $streamUrl   = '';
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
                        $duration    = $this->parseIsoDuration(isset($item['duration']) ? $item['duration'] : '');
                        $thumbnail   = isset($item['thumbnailUrl']) ? (is_array($item['thumbnailUrl']) ? reset($item['thumbnailUrl']) : $item['thumbnailUrl']) : '';
                        $embedUrl    = isset($item['embedUrl']) ? $item['embedUrl'] : '';
                        break;
                    }
                }
                // Fallback: Article type with duration (pornomineiro uses @type=Article)
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
                $duration    = $this->parseIsoDuration(isset($ld['duration']) ? $ld['duration'] : '');
                $thumbnail   = isset($ld['thumbnailUrl']) ? (is_array($ld['thumbnailUrl']) ? reset($ld['thumbnailUrl']) : $ld['thumbnailUrl']) : '';
                $embedUrl    = isset($ld['embedUrl']) ? $ld['embedUrl'] : '';
            }
        }

        // 2. Embedded VideoObject (inline script, not type="application/ld+json")
        if (empty($title) && preg_match('/"@type"\s*:\s*"VideoObject".*?"name"\s*:\s*"([^"]+)"/s', $html, $m)) {
            $title = $this->sanitizeText(trim(stripslashes($m[1]), ' "'));
        }
        if (empty($embedUrl) && preg_match('/"embedUrl"\s*:\s*"([^"]+)"/s', $html, $m)) {
            $embedUrl = str_replace('\\/', '/', trim($m[1], ' "'));
        }
        if (empty($duration) && preg_match('/"duration"\s*:\s*"([^"]+)"/s', $html, $m)) {
            $duration = $this->parseIsoDuration(trim($m[1], ' "'));
        }

        // 3. Fallback: meta tags
        if (empty($title) && preg_match('/<title>([^<]+)<\\/title>/i', $html, $m)) {
            $title = trim($m[1]);
        }
        if (empty($description) && preg_match('/<meta[^>]+property="og:description"[^>]+content="([^"]+)"/i', $html, $m)) {
            $description = trim($m[1]);
        }
        if (empty($thumbnail) && preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            $thumbnail = trim($m[1]);
        }
        if (empty($author) && preg_match('/<meta[^>]+name="author"[^>]+content="([^"]+)"/i', $html, $m)) {
            $author = trim($m[1]);
        }

        // 4. Tags: article:tag meta + category links
        if (preg_match_all('/<meta[^>]+property="article:tag"[^>]+content="([^"]+)"/i', $html, $tagMatches)) {
            $tags = array_map('trim', $tagMatches[1]);
        }
        if (preg_match_all('/<a[^>]+href="https?:\\/\\/www\\.pornomineiro\\.com\\/videos\\/([a-z0-9\\-]+)\\/"[^>]*>([^<]+)<\\/a>/i', $html, $catMatches)) {
            foreach ($catMatches[2] as $cat) {
                $cat = trim($cat);
                if ($cat && !in_array($cat, $tags)) {
                    $tags[] = $cat;
                }
            }
        }

        // 5. Embed URL. O site declara o embed como link (`embedUrl" href="..."`)
        // e o host do player deixou de ser videos.pornomineiro.com - aceita as
        // duas formas (a antiga continua valendo se o layout voltar).
        if (empty($embedUrl) && preg_match('/embedUrl"\s*href="(https?:\/\/[^"]*pornomineiro\.com\/embed\/\d+\/?[^"]*)"/i', $html, $embedMatch)) {
            $embedUrl = $embedMatch[1];
        }
        if (empty($embedUrl) && preg_match('/<iframe[^>]+src="(https?:\/\/(?:videos|www)\.pornomineiro\.com\/embed\/\d+[^"]*)"/i', $html, $iframeMatch)) {
            $embedUrl = $iframeMatch[1];
        }

        // 5b. Stream direto do iframe do player. Hoje o player vem de
        // cdn.sources.network/e?...&v=<url assinada do MP4>: a URL do parâmetro
        // `v` responde 302 para o MP4 final e toca sem depender do Referer do
        // site. Sem isto o fetchInfo só devolvia embed_url e o preview caía no
        // aviso "não foi possível reproduzir".
        if (empty($streamUrl) && preg_match_all('/<iframe[^>]+src="([^"]+)"/i', $html, $iframes)) {
            foreach ($iframes[1] as $rawSrc) {
                $src = html_entity_decode($rawSrc, ENT_QUOTES);
                $query = parse_url($src, PHP_URL_QUERY);
                if (empty($query)) {
                    continue;
                }
                parse_str($query, $qs);
                if (!empty($qs['v']) && preg_match('#^https?://#i', $qs['v'])) {
                    $streamUrl = trim($qs['v']);
                    break;
                }
            }
        }

        // 5c. Player de embed antigo (videos.pornomineiro.com/embed/{video_id}).
        // Parte dos posts ainda usa esse iframe, que NAO tem o parametro `v` -
        // o yt-dlp nao suporta a pagina do site nem extrai dali (medido: o
        // formato achado e sempre o mesmo MP4 do embed). A pagina de embed
        // expoe o MP4 direto no padrao KVS (/get_file/.../{id}_{q}.mp4/), que
        // ja responde video/mp4. Sem isto esses videos nunca baixam.
        if (empty($streamUrl) && preg_match('/<iframe[^>]+src="(https?:\/\/videos\.pornomineiro\.com\/embed\/\d+[^"]*)"/i', $html, $embIframe)) {
            $embedPage = html_entity_decode($embIframe[1], ENT_QUOTES);
            $embedHtml = $this->fetchHtml($embedPage);
            if ($embedHtml && preg_match('#https?://videos\.pornomineiro\.com/get_file/[^\s"\'<>]+\.mp4/?#i', $embedHtml, $mp4Match)) {
                $streamUrl = $mp4Match[0];
            }
        }

        // 6. Clean title: remove site name suffixes
        if (!empty($title)) {
            $title = preg_replace('/\\s*[|\\-–]\\s*Porno\\s*Mineiro.*$/i', '', $title);
            $title = preg_replace('/\\s*[|\\-–]\\s*pornomineiro.*$/i', '', $title);
        }

        // Extract post ID from embed URL or URL
        $videoId = '';
        if (preg_match('/\\/embed\\/(\\d+)/', $embedUrl, $m)) {
            $videoId = $m[1];
        } elseif (preg_match('/post\\/(\\d+)/', $url, $m)) {
            $videoId = $m[1];
        }

        $tagsStr = implode(', ', array_slice(array_values($tags), 0, 15));
        $qualities = array('best' => 'Melhor Qualidade (Máxima)');

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'PornoMineiro',
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
