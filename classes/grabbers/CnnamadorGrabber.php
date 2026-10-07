<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/AbstractGrabber.php';

/**
 * CnnamadorGrabber - Extrator de vídeos do cnnamador.com
 *
 * Site KVS (Kernel Video Sharing) atrás de Cloudflare, com listings
 * server-rendered. A página do vídeo expõe:
 *   - JSON-LD VideoObject (nome, descrição, duração ISO, thumbnail)
 *   - `var flashvars` com os streams diretos do KVS (video_url + rendições
 *     em video_alt_url*), duração, tags, categorias e a capa em preview_url)
 *   - player incorporado em /embed/{video_id}
 *
 * Estratégia de download:
 *   1. Direto por stream_url/rendição escolhida (curl em streaming - o
 *      original passa de 180 MB e o link limita a ~330 KB/s, então não dá
 *      para bufferar em RAM nem usar o timeout de 300s do pai)
 *   2. Fallback: yt-dlp na página / embed (downloadVideoStandard)
 */
class CnnamadorGrabber extends AbstractGrabber {
    use DownloadStrategy;

    public function __construct() {
        $this->referer = 'https://cnnamador.com/';
        parent::__construct();
    }

    public function getSiteName() {
        return 'Cnnamador';
    }

    public function canHandle($url) {
        return (bool) preg_match('/cnnamador\.com/i', $url);
    }

    /**
     * Lê uma chave do `var flashvars` do KVS (sempre em aspas simples).
     */
    private function flashvar($html, $key) {
        $re = '/\b' . preg_quote($key, '/') . "\\s*:\\s*'((?:[^'\\\\]|\\\\.)*)'/s";
        if (preg_match($re, $html, $m)) {
            return trim(str_replace(array('\\/', "\\'"), array('/', "'"), $m[1]));
        }
        return '';
    }

    /**
     * Remove as frases de boilerplate que o KVS concatena à descrição real
     * ("Vídeo de X com duração de Y.", "Arquivo em HD com 43,49 MB.",
     * "Duração: 12min5s. Tamanho: 374.62 MB. Qualidade: HD."). Só corta nas
     * pontas: se não sobrar nada, o vídeo não tem descrição no site.
     */
    private function cleanDescription($desc) {
        $d = trim(preg_replace('/\s+/u', ' ', (string) $desc));
        if ($d === '') {
            return '';
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $d);
        $lead  = '/^(?:v[ií]deo\b|arquivo\b|dura[cç][aã]o\b|tamanho:|qualidade:|s(?:ã|a)o \d+)/iu';
        $trail = '/^(?:arquivo\b|dura[cç][aã]o\b|tamanho:|qualidade:|s(?:ã|a)o \d+)/iu';

        while ($parts && preg_match($lead, $parts[0])) {
            array_shift($parts);
        }
        while ($parts && preg_match($trail, $parts[count($parts) - 1])) {
            array_pop($parts);
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Junta listas CSV (video_tags + video_categories), descartando tokens de
     * 1 letra ("o") que o admin digitou por engano, e limita a 15.
     */
    private function joinTags($groups) {
        $out  = array();
        $seen = array();
        foreach ($groups as $group) {
            foreach (explode(',', (string) $group) as $raw) {
                $tag = trim($raw);
                if (mb_strlen($tag) < 2) {
                    continue;
                }
                $key = mb_strtolower($tag, 'UTF-8');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $tag;
            }
        }
        return implode(', ', array_slice($out, 0, 15));
    }

    /**
     * Rendições disponíveis: cada stream com sua altura (video_url_size é
     * "LARGURAxALTURA"). Serve para escolher a rendition conforme a qualidade
     * pedida na fonte (best/1080/720/480/360).
     */
    private function collectStreams($html) {
        $specs = array(
            array('video_url', 'video_url_size'),
            array('video_url_hd', 'video_url_hd_size'),
            array('video_alt_url', 'video_alt_url_size'),
            array('video_alt_url_hd', 'video_alt_url_hd_size'),
            array('video_alt_url2', 'video_alt_url2_size'),
            array('video_alt_url3', 'video_alt_url3_size'),
        );

        $streams = array();
        foreach ($specs as $spec) {
            $url = $this->flashvar($html, $spec[0]);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $height = 0;
            $size = $this->flashvar($html, $spec[1]);
            if (preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
                $height = (int) $m[2];
            }
            $streams[] = array('url' => $url, 'height' => $height);
        }

        return $streams;
    }

    /**
     * URL candidatas ao download, na ordem em que devem ser tentadas.
     *
     * quality 'best' (0): maior rendição primeiro (o original).
     * quality numérica: a maior rendição <= alvo primeiro; se nenhuma couber,
     * a menor disponível; as demais vêm depois, dentro do orçamento primeiro.
     */
    private function candidateOrder(array $info, $quality) {
        $streams = isset($info['streams']) && is_array($info['streams']) ? $info['streams'] : array();

        if (empty($streams)) {
            return !empty($info['stream_url']) ? array($info['stream_url']) : array();
        }

        usort($streams, function ($a, $b) {
            return intval($b['height']) - intval($a['height']);
        });

        $target = intval($quality);
        if ($target > 0) {
            $pick = null;
            foreach ($streams as $s) {
                if ($s['height'] > 0 && $s['height'] <= $target) {
                    $pick = $s;
                    break;
                }
            }
            if ($pick === null) {
                for ($i = count($streams) - 1; $i >= 0; $i--) {
                    if ($streams[$i]['height'] > 0) {
                        $pick = $streams[$i];
                        break;
                    }
                }
            }

            if ($pick !== null) {
                $within = array();
                $above  = array();
                foreach ($streams as $s) {
                    if ($s['url'] === $pick['url']) {
                        continue;
                    }
                    if ($s['height'] > 0 && $s['height'] <= $target) {
                        $within[] = $s;
                    } else {
                        $above[] = $s;
                    }
                }
                $streams = array_merge(array($pick), $within, $above);
            }
        }

        $urls = array();
        foreach ($streams as $s) {
            if (!in_array($s['url'], $urls, true)) {
                $urls[] = $s['url'];
            }
        }
        return $urls;
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o Cnnamador.'
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
        $author      = '';
        $videoId     = '';

        // 1. JSON-LD VideoObject
        if (preg_match('/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/si', $html, $ldMatch)) {
            $ld = json_decode(trim($ldMatch[1]), true);
            if (is_array($ld)) {
                if (isset($ld['@type']) && $ld['@type'] === 'VideoObject') {
                    $items = array($ld);
                } elseif (isset($ld['@graph']) && is_array($ld['@graph'])) {
                    $items = $ld['@graph'];
                } else {
                    $items = array();
                }
                foreach ($items as $item) {
                    if (!is_array($item) || !isset($item['@type']) || $item['@type'] !== 'VideoObject') {
                        continue;
                    }
                    if ($title === '' && isset($item['name'])) {
                        $title = (string) $item['name'];
                    }
                    if ($description === '' && isset($item['description'])) {
                        $description = (string) $item['description'];
                    }
                    if ($duration <= 0 && isset($item['duration'])) {
                        $duration = $this->parseIsoDuration((string) $item['duration']);
                    }
                    if ($thumbnail === '' && isset($item['thumbnailUrl'])) {
                        $t = $item['thumbnailUrl'];
                        $thumbnail = is_array($t) ? (string) reset($t) : (string) $t;
                    }
                    break;
                }
            }
        }

        // 2. flashvars do KVS: título bruto, streams, duração, tags e capa
        $videoTitle = $this->flashvar($html, 'video_title');
        if ($videoTitle !== '') {
            $title = $videoTitle;
        }

        $videoId = $this->flashvar($html, 'video_id');
        if ($videoId === '' && preg_match('/\/contents\/videos_screenshots\/\d+\/(\d+)\//', $html, $m)) {
            $videoId = $m[1];
        }

        if ($duration <= 0) {
            $d = $this->flashvar($html, 'duration');
            if ($d !== '' && ctype_digit($d)) {
                $duration = (int) $d;
            }
        }

        // Tags = video_tags; categorias KVS (video_categories) vão para
        // `category` — hoje entravam junto nas tags.
        $tags = $this->joinTags(array(
            $this->flashvar($html, 'video_tags'),
        ));
        $category = $this->joinTags(array(
            $this->flashvar($html, 'video_categories'),
        ));
        if ($category === '') {
            $category = $this->extractCategory($html, $url);
        }

        $preview = $this->flashvar($html, 'preview_url');
        if ($preview !== '') {
            $thumbnail = $preview;
        }

        $streams = $this->collectStreams($html);

        // 3. Meta tags (fallback)
        if ($title === '' && preg_match('/<meta[^>]+property="og:title"[^>]+content="([^"]+)"/i', $html, $m)) {
            $title = trim($m[1]);
        }
        if ($description === '' && preg_match('/<meta[^>]+property="og:description"[^>]+content="([^"]+)"/i', $html, $m)) {
            $description = trim($m[1]);
        }
        if ($thumbnail === '' && preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            $thumbnail = trim($m[1]);
        }
        if ($title === '' && preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
            $title = trim($m[1]);
        }

        // 4. Autor (link de membro do KVS)
        if (preg_match('/<a[^>]+href="https?:\/\/(?:www\.)?cnnamador\.com\/members\/\d+\/"[^>]*>(.*?)<\/a>/si', $html, $m)) {
            $author = trim(strip_tags($m[1]));
        }
        if ($author === '' && preg_match('/<meta[^>]+name="author"[^>]+content="([^"]+)"/i', $html, $m)) {
            $author = trim($m[1]);
        }

        // 5. Título limpo do sufixo do site
        if ($title !== '') {
            $title = preg_replace('/\s*[-|–]\s*CNNAMADOR.*$/i', '', $title);
            $title = preg_replace('/\s*\|\s*cnnamador.*$/i', '', $title);
        }

        $description = $this->cleanDescription($description);

        $streamUrl = '';
        foreach ($streams as $s) {
            if ($s['height'] <= 0) {
                $streamUrl = $s['url'];
                break;
            }
        }
        if ($streamUrl === '' && !empty($streams)) {
            $streamUrl = $streams[0]['url'];
        }

        $embedUrl = '';
        if ($videoId !== '' && ctype_digit($videoId)) {
            $embedUrl = 'https://cnnamador.com/embed/' . $videoId;
        }

        $qualities = array('best' => 'Melhor Qualidade (Máxima)');

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'Cnnamador',
            'title'              => $this->sanitizeText($title),
            'description'        => $description,
            'tags'               => $tags,
            'category'           => $category,
            'duration'           => intval($duration),
            'duration_formatted' => $this->formatDuration($duration),
            'thumbnail'          => $thumbnail,
            'qualities'          => $qualities,
            'embed_url'          => $embedUrl,
            'stream_url'         => $streamUrl,
            'author'             => $author,
            'views'              => 0,
            'likes'              => 0,
            // Extra (ignorado pelo merge/admin): rendições p/ escolher a
            // qualidade pedida na fonte antes de cair no yt-dlp.
            'streams'            => $streams,
        );
    }

    /**
     * Download direto em streaming para arquivo.
     *
     * O original do cnnamador tem ~187 MB e o link responde a ~330 KB/s
     * (~10 min); o downloadDirect do pai bufferava tudo em RAM (RETURNTRANSFER)
     * e cortava no timeout de 300s. Aqui o curl escreve direto no arquivo,
     * aceita só 200 final e aborta se a transferência parar.
     */
    protected function downloadDirect($streamUrl, $targetPath) {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        $fp = @fopen($targetPath, 'wb');
        if (!$fp) {
            return false;
        }

        $ch = curl_init($streamUrl);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        if ($this->directDownloadReferer && !empty($this->referer)) {
            curl_setopt($ch, CURLOPT_REFERER, $this->referer);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, 1800);
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 120);

        $ok       = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok !== true || $httpCode != 200 || !file_exists($targetPath) || filesize($targetPath) <= 1024) {
            @unlink($targetPath);
            return false;
        }

        return true;
    }

    public function downloadVideo($url, $targetPath, $quality = 'best') {
        $url  = trim($url);
        $info = $this->fetchInfo($url);

        if (!empty($info['status'])) {
            foreach ($this->candidateOrder($info, $quality) as $streamUrl) {
                $result = $this->tryDirectDownload($streamUrl, $targetPath);
                if (!empty($result['status'])) {
                    return $result;
                }
            }
        }

        return $this->downloadVideoStandard($url, $targetPath, $quality, $info);
    }
}
