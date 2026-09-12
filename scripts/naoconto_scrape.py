#!/usr/bin/env python3
"""
naoconto_scrape.py - Discovers videos from naoconto.com listings.

Usage:
    python3 naoconto_scrape.py <url> [page] [max_pages]

Output: JSON with video list.

naoconto.com is a WordPress site with standard HTML listings. Posts are inside
<div class="post"> blocks with .thumb containing a background image and .title
with an <a> link. Video pages use embedded players (iframes or inline video).

Structure observed (2026-09):
  * Posts: <div class="post"> <div class="thumb"><a href="..."><div class="img" style="background-image:url(...)"></div></a></div>
    <div class="title"><a href="...">Title</a></div>
  * Pagination: /page/N/ (WordPress standard), wp-pagenavi
"""
import sys
import json
import re
import time
import subprocess
import warnings
from concurrent.futures import ThreadPoolExecutor

warnings.filterwarnings('ignore')

try:
    from curl_cffi import requests
except ImportError:
    print(json.dumps({"error": "curl_cffi not installed. Run: pip3 install curl_cffi"}))
    sys.exit(1)

HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language': 'pt-BR,pt;q=0.9,en;q=0.8',
}

TAG_STOPLIST = set([
    'porno', 'xxx', 'videos porno', 'sexo', 'gratis',
    'novinhas', 'mulheres nuas', 'putaria', 'nude',
])


def fetch(url):
    try:
        headers = dict(HEADERS)
        # NaoConto requires Referer for video pages
        if 'naoconto.com' in url:
            headers['Referer'] = 'https://www.naoconto.com/'
        r = requests.get(url, headers=headers, impersonate='chrome', timeout=30)
    except Exception:
        return None
    if r.status_code != 200:
        return None
    return r.text


def parse_duration_mmss(text):
    m = re.match(r'^\s*(\d+):(\d{2})(?::(\d{2}))?\s*$', text or '')
    if not m:
        return 0
    if m.group(3) is not None:
        h = int(m.group(1))
        mi = int(m.group(2))
        s = int(m.group(3))
        return h * 3600 + mi * 60 + s
    mi = int(m.group(1))
    s = int(m.group(2))
    return mi * 60 + s


def parse_iso_seconds(iso):
    if not iso:
        return 0
    m = re.match(r'^P(?:(?P<d>\d+)D)?(?:T(?:(?P<h>\d+)H)?(?:(?P<mi>\d+)M)?(?:(?P<s>\d+)S)?)?$',
                 iso.strip())
    if not m:
        return 0
    d = int(m.group('d') or 0)
    h = int(m.group('h') or 0)
    mi = int(m.group('mi') or 0)
    s = int(m.group('s') or 0)
    return d * 86400 + h * 3600 + mi * 60 + s


def fmt_duration(seconds):
    seconds = max(0, int(seconds))
    if seconds <= 0:
        return ''
    h = seconds // 3600
    m = (seconds % 3600) // 60
    s = seconds % 60
    if h > 0:
        return '%d:%02d:%02d' % (h, m, s)
    return '%d:%02d' % (m, s)


def parse_video_object(html):
    m = re.search(r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>',
                  html, re.S | re.I)
    if not m:
        return None
    try:
        data = json.loads(m.group(1).strip())
    except Exception:
        return None
    if isinstance(data, dict) and data.get('@type') == 'VideoObject':
        return data
    if isinstance(data, dict) and '@graph' in data:
        best = None
        for item in data['@graph']:
            if isinstance(item, dict) and item.get('@type') == 'VideoObject':
                return item
            if isinstance(item, dict) and item.get('duration') and not best:
                best = item
        if best:
            return best
    return None


def extract_tags(html):
    tags = []
    for m in re.finditer(r'<a[^>]+rel="tag"[^>]*>([^<]+)</a>', html, re.I):
        tag = m.group(1).strip()
        if tag and tag not in tags:
            tags.append(tag)
    return tags


def clean_tags(tag_list):
    out = []
    for t in tag_list:
        key = t.strip().lower()
        if not key or key in TAG_STOPLIST:
            continue
        if t not in out:
            out.append(t)
    return ', '.join(out[:15])


def external_key(href):
    slug = href.rstrip('/').rsplit('/', 1)[-1]
    return 'slug:' + (slug or href)


def probe_mp4_duration(url):
    """Get duration from MP4 via ffprobe (reads only moov atom, ~0.4s)."""
    try:
        result = subprocess.run(
            ['ffprobe', '-v', 'quiet', '-print_format', 'json', '-show_format', url],
            capture_output=True, text=True, timeout=15
        )
        if result.returncode == 0:
            data = json.loads(result.stdout)
            return int(float(data.get('format', {}).get('duration', 0)))
    except Exception:
        pass
    return 0


def parse_listing(html, page_url):
    videos = []
    seen = set()

    # naoconto.com post structure:
    # <div class="post"> ... <a href="URL" target="_blank" title="TITLE">
    #   <div class="abl-thumb-holder"><div class="img ..." style="background-image:url(...)"></div></div>
    #   </a> ... <div class="title"><a href="URL" target="_blank">TITLE</a></div> ...
    # </div>

    # Strategy 1: find all <div class="post"> blocks and extract link + title + thumbnail
    post_split = re.split(r'<div\s+class="post(?:\s+last)?"[^>]*>', html)
    for block in post_split[1:]:  # skip content before first post
        # Cut at next post or end
        block = re.split(r'<div\s+class="post(?:\s+last)?"[^>]*>', block)[0]

        # Link from the first <a> with video URL pattern
        href_match = re.search(
            r'<a[^>]+href="(https?://(?:www\.)?naoconto\.com/\d{4}/\d{2}/[^"]+\.html)"[^>]*>',
            block
        )
        if not href_match:
            continue
        href = href_match.group(1)

        key = external_key(href)
        if key in seen:
            continue
        seen.add(key)

        # Title: prefer <div class="title"><a>TITLE</a></div>, fallback to title attr
        title = ''
        title_match = re.search(r'<div\s+class="title">\s*<a[^>]*>(.*?)</a>', block, re.S)
        if title_match:
            title = re.sub(r'<[^>]+>', '', title_match.group(1)).strip()
        if not title:
            title_attr = re.search(r'<a[^>]+href="[^"]*naoconto\.com/[^"]*"[^>]*title="([^"]*)"', block)
            if title_attr:
                title = title_attr.group(1).strip()

        # Thumbnail from background-image
        thumbnail = ''
        img_match = re.search(r'background-image:\s*url\(["\']?([^"\')\s]+)["\']?\)', block)
        if img_match:
            thumbnail = img_match.group(1)

        videos.append({
            'external_id': key,
            'source_url': href,
            'canonical_url': href.rstrip('/'),
            'title': title,
            'description': '',
            'tags': '',
            'duration': 0,
            'duration_formatted': '',
            'thumbnail_url': thumbnail,
        })

    # Strategy 2: fallback — find all video-pattern links with title attribute
    if not videos:
        link_pattern = re.compile(
            r'<a[^>]+href="(https?://(?:www\.)?naoconto\.com/\d{4}/\d{2}/[^"]+\.html)"[^>]*title="([^"]*)"',
            re.S | re.I
        )
        for href, title_raw in link_pattern.findall(html):
            key = external_key(href)
            if key in seen:
                continue
            seen.add(key)
            videos.append({
                'external_id': key,
                'source_url': href,
                'canonical_url': href.rstrip('/'),
                'title': title_raw.strip(),
                'description': '',
                'tags': '',
                'duration': 0,
                'duration_formatted': '',
                'thumbnail_url': '',
            })

    has_more = bool(re.search(r'rel="next"|class="[^"]*next[^"]*"|/page/\d+/', html))

    return {'videos': videos, 'has_more': has_more}


def enrich_video(video):
    html = fetch(video['source_url'])
    if not html:
        return

    vo = parse_video_object(html)
    if vo:
        if not video['title']:
            video['title'] = str(vo.get('name') or '').strip()
        if not video['description']:
            video['description'] = str(vo.get('description') or '').strip()
        iso = vo.get('duration') or ''
        if iso and video['duration'] <= 0:
            video['duration'] = parse_iso_seconds(iso)
            video['duration_formatted'] = fmt_duration(video['duration'])

    # Fallback: extract duration from raw HTML
    if video['duration'] <= 0:
        m = re.search(r'"duration"\s*:\s*"(PT[^"]+)"', html)
        if m:
            video['duration'] = parse_iso_seconds(m.group(1))
            video['duration_formatted'] = fmt_duration(video['duration'])

    # Extract direct MP4 URL from <video> tag (naoconto serves direct MP4)
    m = re.search(r'<video[^>]*>.*?<source[^>]+src="(https?://[^"]+\.mp4[^"]*)"', html, re.S)
    if m:
        video['mp4_url'] = m.group(1)
        # ffprobe fallback: get duration from MP4 file header (~0.4s)
        if video['duration'] <= 0:
            dur = probe_mp4_duration(m.group(1))
            if dur > 0:
                video['duration'] = dur
                video['duration_formatted'] = fmt_duration(dur)

    if not video['tags']:
        tags = clean_tags(extract_tags(html))
        if tags:
            video['tags'] = tags

    # Try og:image for thumbnail
    if not video['thumbnail_url']:
        m = re.search(r'<meta[^>]+property="og:image"[^>]+content="([^"]+)"', html)
        if m:
            video['thumbnail_url'] = m.group(1).strip()


def build_page_url(url, page):
    if page <= 1:
        return url
    base = url.rstrip('/')
    if re.search(r'/page/\d+', base):
        return re.sub(r'/page/\d+', '/page/' + str(page), base)
    return base + '/page/' + str(page) + '/'


def scrape_listing_page(url):
    html = fetch(url)
    if html is None:
        return {'error': 'Failed to fetch page: %s' % url, 'videos': [], 'has_more': False}
    result = parse_listing(html, url)
    return result


def scrape_profile(url, page=1, max_pages=1):
    page_url = build_page_url(url, page)
    result = scrape_listing_page(page_url)

    if 'error' in result:
        return {'error': result['error'], 'videos': [], 'total': 0, 'has_more': False}

    videos = result.get('videos', [])
    has_more = result.get('has_more', False)

    # Enrich: fetch individual video pages for metadata
    for video in videos:
        try:
            enrich_video(video)
            time.sleep(0.3)
        except Exception:
            pass

    total = len(videos)

    return {
        'videos': videos,
        'total': total,
        'has_more': has_more,
    }


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: naoconto_scrape.py <url> [page] [max_pages]"}))
        sys.exit(1)

    url = sys.argv[1]
    page = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 1

    result = scrape_profile(url, page, max_pages)
    print(json.dumps(result))
