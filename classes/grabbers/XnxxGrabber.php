<?php
require_once dirname(__FILE__) . '/AbstractGrabber.php';
require_once dirname(__FILE__) . '/DownloadStrategy.php';

class XnxxGrabber extends AbstractGrabber
{
    use DownloadStrategy;

    public function getSiteName()
    {
        return 'XNXX';
    }

    public function canHandle($url)
    {
        return (bool) preg_match('/xnxx\.com/i', $url);
    }

    public function fetchInfo($url)
    {
        $result = [
            'status' => false,
            'id' => null,
            'video_id' => null,
            'site' => 'xnxx',
            'title' => '',
            'description' => '',
            'tags' => '',
            'category' => '',
            'duration' => 0,
            'duration_formatted' => '',
            'thumbnail' => '',
            'qualities' => [],
            'embed_url' => '',
            'stream_url' => '',
            'author' => '',
            'views' => 0,
            'likes' => 0,
        ];

        $probe = $this->probeYtdlp($url, 120);
        $data = is_array($probe) ? $probe : [];

        if (!empty($data['title'])) {
            $result['title'] = $this->sanitizeText($data['title']);
        }
        if (!empty($data['description'])) {
            $result['description'] = $this->sanitizeText($data['description']);
        }
        if (!empty($data['duration'])) {
            $result['duration'] = (int)$data['duration'];
            $result['duration_formatted'] = $this->formatDuration($result['duration']);
        }
        if (!empty($data['thumbnail'])) {
            $result['thumbnail'] = $data['thumbnail'];
        }
        if (!empty($data['uploader'])) {
            $result['author'] = $this->sanitizeText($data['uploader']);
        }
        if (!empty($data['view_count'])) {
            $result['views'] = (int)$data['view_count'];
        }
        if (!empty($data['like_count'])) {
            $result['likes'] = (int)$data['like_count'];
        }

        $eid = null;
        if (preg_match('/video-([a-z0-9]+)/i', $url, $m)) {
            $eid = $m[1];
        } elseif (!empty($data['id'])) {
            $eid = (string)$data['id'];
        }
        if ($eid !== null) {
            $result['id'] = $eid;
            $result['video_id'] = $eid;
            $result['embed_url'] = 'https://www.xnxx.com/embed/' . $eid;
        }

        $tagsStr = '';
        if (!empty($data['tags']) && is_array($data['tags'])) {
            $tags = array_values(array_filter(array_map([$this, 'sanitizeText'], $data['tags']), function ($t) {
                return $t !== '';
            }));
            $tagsStr = implode(',', array_slice($tags, 0, 15));
        }
        $result['tags'] = $tagsStr;
        $result['category'] = '';

        $streamUrl = '';
        $qualities = [];

        if (!empty($data['formats']) && is_array($data['formats'])) {
            $cands = [];
            foreach ($data['formats'] as $fmt) {
                if (!is_array($fmt)) {
                    continue;
                }
                $ext = strtolower($fmt['ext'] ?? '');
                $protocol = strtolower($fmt['protocol'] ?? '');
                $vcodec = strtolower($fmt['vcodec'] ?? '');
                $acodec = strtolower($fmt['acodec'] ?? '');
                $urlFmt = $fmt['url'] ?? '';
                if (!$urlFmt) {
                    continue;
                }
                $height = isset($fmt['height']) && $fmt['height'] !== null ? (int)$fmt['height'] : null;
                if ($protocol === 'https' && $ext === 'mp4' && $vcodec === 'none' && $acodec !== 'none') {
                    $cands[] = ['url' => $urlFmt, 'height' => $height, 'score' => 200];
                }
                if ($protocol === 'https' && $ext === 'mp4' && $vcodec !== 'none') {
                    $cands[] = ['url' => $urlFmt, 'height' => $height, 'score' => 150];
                }
                if ($height !== null) {
                    $qkey = (string)$height . 'p';
                    if (!isset($qualities[$qkey]) || empty($qualities[$qkey])) {
                        $qualities[$qkey] = $urlFmt;
                    }
                }
            }
            usort($cands, function ($a, $b) {
                if ($b['score'] === $a['score']) {
                    $bh = $b['height'] ?? 0;
                    $ah = $a['height'] ?? 0;
                    return $bh <=> $ah;
                }
                return $b['score'] <=> $a['score'];
            });
            if (!empty($cands)) {
                $streamUrl = $cands[0]['url'];
            }
        }

        if ($streamUrl === '' && !empty($data['url'])) {
            $streamUrl = $data['url'];
        }
        if ($streamUrl === '' && !empty($data['webpage_url'])) {
            $streamUrl = $data['webpage_url'];
        }

        if ($streamUrl !== '') {
            $result['stream_url'] = $streamUrl;
        }
        ksort($qualities, SORT_NATURAL);
        $result['qualities'] = $qualities;
        $result['status'] = !empty($result['stream_url']) || !empty($result['title']);

        return $result;
    }

    public function downloadVideo($url, $targetPath, $quality = 'best')
    {
        $this->referer = 'https://www.xnxx.com/';
        return $this->downloadVideoStandard($url, $targetPath, $quality, null);
    }
}
