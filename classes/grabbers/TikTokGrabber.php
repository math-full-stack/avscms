<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/AbstractGrabber.php';

/**
 * TikTokGrabber - Extrator de vídeos do TikTok (tiktok.com / vm.tiktok.com).
 *
 * O TikTok não entrega metadata confiável em HTML (a página é um app JS), então
 * tudo sai do yt-dlp em modo info — mesmo caminho já usado por YoutubeGrabber e
 * pelos sites atrás de proteção (XFree/SonovinhasBR). O yt-dlp carrega
 * `scripts/yt-dlp` e resolve o CDN do TikTok, inclusive o Referer exigido pelo
 * CDN no download.
 *
 * TODO vídeo do TikTok é SHORTS: vertical (orientation=portrait, definido na
 * conversão a partir das dimensões) e curto (o provider de discovery só aceita
 * duração < 100s, que é o critério do feed de shorts). Aqui o fetchInfo também
 * devolve `is_shorts`/`max_duration` para quem consumir a info decidir.
 *
 * Estratégia de download (DownloadStrategy): yt-dlp com seletor que preserva o
 * MP4 original (vertical, sem re-encode) — o TikTok serve H.264/AAC muxado.
 */
class TikTokGrabber extends AbstractGrabber {
    use DownloadStrategy;

    /** Limite de duração do feed de shorts (ver shorts.php / shorts_feed.php). */
    const SHORTS_MAX_DURATION = 100;

    public function __construct() {
        $this->referer = 'https://www.tiktok.com/';
        parent::__construct();
    }

    public function getSiteName() {
        return 'TikTok';
    }

    public function canHandle($url) {
        return (bool) preg_match('#(^|//)(www\.|m\.)?(vm\.)?tiktok\.com/#i', $url);
    }

    /**
     * Cookies para conteúdo que exige sessão (contas privadas/regiões com
     * consentimento). Mesmas configs usadas pelo provider de discovery:
     * `grabber_cookies` (arquivo) e `grabber_cookies_browser` (browser).
     */
    private function getAuthArgs() {
        global $config;

        $args = '';
        $cookieFile = isset($config['grabber_cookies']) ? trim($config['grabber_cookies']) : '';
        if ($cookieFile && file_exists($cookieFile)) {
            $args .= ' --cookies ' . escapeshellarg($cookieFile);
        }
        $browser = isset($config['grabber_cookies_browser']) ? trim($config['grabber_cookies_browser']) : '';
        if ($browser) {
            $args .= ' --cookies-from-browser ' . escapeshellarg($browser);
        }
        return $args;
    }

    /**
     * Extrai as hashtags do texto (#tag) — o TikTok não expõe campo de tags,
     * elas vivem na descrição/título.
     */
    private function extractHashtags($text) {
        $tags = array();
        if (preg_match_all('/#([\p{L}\p{N}_]{2,50})/u', (string) $text, $m)) {
            foreach ($m[1] as $tag) {
                $tag = strtolower(trim($tag));
                if ($tag !== '' && !in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }
        return array_slice($tags, 0, 15);
    }

    /**
     * Escolhe a melhor miniatura do JSON do yt-dlp (prefere a capa).
     */
    private function pickThumbnail($data) {
        if (empty($data['thumbnails']) || !is_array($data['thumbnails'])) {
            return isset($data['thumbnail']) ? (string) $data['thumbnail'] : '';
        }

        $best = '';
        foreach ($data['thumbnails'] as $thumb) {
            if (empty($thumb['url'])) {
                continue;
            }
            // 'cover' é a capa real do vídeo (as outras são frames do vídeo).
            if (isset($thumb['id']) && $thumb['id'] === 'cover') {
                return (string) $thumb['url'];
            }
            $best = (string) $thumb['url'];
        }
        return $best;
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o TikTok.',
            );
        }

        $data = $this->probeYtdlp($url, 180);
        if (!is_array($data)) {
            return array(
                'status' => false,
                'error'  => 'Não foi possível extrair a metadata via yt-dlp (vídeo privado, removido ou bloqueio regional).',
            );
        }

        // Playlist (perfil/coleção): usa o 1º vídeo. O fetchInfo é single-video.
        if (!empty($data['entries']) && is_array($data['entries'])) {
            $first = reset($data['entries']);
            if (is_array($first)) {
                $data = $first;
            }
        }

        $videoId     = isset($data['id']) ? (string) $data['id'] : '';
        $title       = isset($data['title']) ? (string) $data['title'] : '';
        $description = isset($data['description']) ? (string) $data['description'] : '';
        $duration    = isset($data['duration']) ? (int) round($data['duration']) : 0;
        $thumbnail   = $this->pickThumbnail($data);
        $uploader    = '';
        if (!empty($data['uploader'])) {
            $uploader = (string) $data['uploader'];
        } elseif (!empty($data['channel'])) {
            $uploader = (string) $data['channel'];
        }

        if ($title === '' && $description !== '') {
            $title = $description;
        }

        // Hashtags do título + descrição (o TikTok não tem campo de tags).
        $tags = $this->extractHashtags($title . ' ' . $description);
        if ($uploader !== '') {
            $tags[] = strtolower($uploader);
        }

        // URL canônica do vídeo (o CDN precisa do Referer da página).
        $webpageUrl = isset($data['webpage_url']) ? (string) $data['webpage_url'] : $url;

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'TikTok',
            'title'              => $this->sanitizeText($title),
            'description'        => $description,
            'tags'               => implode(', ', array_slice($tags, 0, 15)),
            'duration'           => $duration,
            'duration_formatted' => $this->formatDuration($duration),
            'thumbnail'          => $thumbnail,
            'qualities'          => array('best' => 'Melhor Qualidade (original vertical)'),
            'embed_url'          => $webpageUrl,
            'stream_url'         => $this->pickBestMuxedUrl($data),
            'author'             => $uploader,
            'views'              => isset($data['view_count']) ? (int) $data['view_count'] : 0,
            'likes'              => isset($data['like_count']) ? (int) $data['like_count'] : 0,
            // Todo TikTok alimenta o feed de shorts (ver cabeçalho da classe).
            'is_shorts'          => true,
            'orientation'        => 'portrait',
            'max_duration'       => self::SHORTS_MAX_DURATION,
        );
    }

    public function downloadVideo($url, $targetPath, $quality = 'best') {
        $url = trim($url);
        $info = $this->fetchInfo($url);
        return $this->downloadVideoStandard($url, $targetPath, $quality, $info);
    }
}
