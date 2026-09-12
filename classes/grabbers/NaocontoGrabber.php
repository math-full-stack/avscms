<?php
defined('_VALID') or die('Restricted Access!');

require_once dirname(__FILE__) . '/AbstractGrabber.php';

/**
 * NaocontoGrabber - Extrator de vídeos do naoconto.com
 *
 * Site WordPress com listings server-rendered. Posts contêm players de vídeo
 * embutidos (iframes ou inline). A página do vídeo pode expor JSON-LD
 * VideoObject com metadata.
 *
 * Estratégia de download:
 *   1. yt-dlp na URL da página (extrai player automaticamente)
 *   2. Fallback: fetch HTML e tentar extrair URL direta do player
 */
class NaocontoGrabber extends AbstractGrabber {
    use DownloadStrategy;

    public function __construct() {
        $this->referer = 'https://www.naoconto.com/';
        parent::__construct();
    }

    protected function fetchHtml($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.naoconto.com/');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode == 200 && !empty($html)) {
            return $html;
        }
        return false;
    }

    public function getSiteName() {
        return 'NaoConto';
    }

    public function canHandle($url) {
        return (bool) preg_match('/naoconto\\.com/i', $url);
    }

    public function fetchInfo($url) {
        $url = trim($url);
        if (!$this->canHandle($url)) {
            return array(
                'status' => false,
                'error'  => 'URL inválida para o NaoConto.'
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
        }

        // 2. Fallback: extract direct MP4 from <video> tag
        if (empty($streamUrl) && preg_match('/<video[^>]*>.*?<source[^>]+src="(https?:\/\/[^"]+\.mp4[^"]*)"/si', $html, $mp4Match)) {
            $streamUrl = $mp4Match[1];
        }

        // 2b. Duration via ffprobe from MP4 file (reads only moov atom, ~0.4s)
        if ($duration <= 0 && !empty($streamUrl) && function_exists('shell_exec')) {
            $cmd = 'ffprobe -v quiet -print_format json -show_format ' . escapeshellarg($streamUrl) . ' 2>&1';
            $probe = @shell_exec($cmd);
            if ($probe) {
                $probeData = json_decode($probe, true);
                if (isset($probeData['format']['duration'])) {
                    $duration = (int) floatval($probeData['format']['duration']);
                }
            }
        }

        // 3. Fallback: meta tags
        if (empty($title) && preg_match('/<title>([^<]+)<\\/title>/i', $html, $m)) {
            $title = trim($m[1]);
        }
        if (empty($description) && preg_match('/<meta[^>]+name="description"[^>]+content="([^"]+)"/i', $html, $m)) {
            $description = trim($m[1]);
        }
        if (empty($thumbnail) && preg_match('/<meta[^>]+property="og:image"[^>]+content="([^"]+)"/i', $html, $m)) {
            $thumbnail = trim($m[1]);
        }

        // 4. Tags: rel="tag" links
        if (preg_match_all('/<a[^>]+rel="tag"[^>]*>([^<]+)<\\/a>/i', $html, $tagMatches)) {
            $tags = array_map('trim', $tagMatches[1]);
        }

        // 5. Clean title: remove site name suffixes
        if (!empty($title)) {
            $title = preg_replace('/\\s*\\|\\s*N.{1,20}o\\s+Conto.*$/i', '', $title);
            $title = preg_replace('/\\s*\\|\\s*Na[oa]conto\\.com.*$/i', '', $title);
        }

        // Extract post ID from URL (YYYY/MM/slug pattern)
        $videoId = '';
        if (preg_match('/\\/(\\d{4})\\/(\\d{2})\\/([^\\.]+)\\.html/', $url, $m)) {
            $videoId = $m[1] . $m[2] . '_' . $m[3];
        }

        $tagsStr = implode(', ', array_slice(array_values($tags), 0, 15));
        $qualities = array('best' => 'Melhor Qualidade (Máxima)');

        return array(
            'status'             => true,
            'id'                 => $videoId,
            'video_id'           => $videoId,
            'site'               => 'NaoConto',
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
