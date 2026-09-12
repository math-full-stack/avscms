#!/usr/bin/env python3
"""
pornomineiro_scrape.py - Discovers videos from pornomineiro.com listings.

Usage:
    python3 pornomineiro_scrape.py <url> [page] [max_pages]

Output: JSON with video list.

pornomineiro.com is a WordPress site with server-rendered HTML listings. Each
video card contains: link (/videos/{category}/{slug}/), title, thumbnail and
duration. Video pages expose JSON-LD VideoObject with description, tags and
duration in ISO 8601.

Structure observed (2026-09):
  * Cards: <div class="inline-block" itemscope> <a itemprop="URL" href="..." title="...">
    <img src="..."> <span class="bg-pm-primary/90...">7:12</span>
  * Video page: JSON-LD VideoObject with name/description/duration/thumbnailUrl
  * Pagination: /page/N/ (WordPress standard), rel="next" in head
"""
import sys
import json
import re
import time
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
    'novinhas', 'mulheres nuas', 'putaria',
])


def fetch(url):
    try:
        r = requests.get(url, headers=HEADERS, impersonate='chrome', timeout=30)
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
    # Direct VideoObject
    if isinstance(data, dict) and data.get('@type') == 'VideoObject':
        return data
    # Inside @graph — prefer VideoObject, fallback to any item with duration
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
    # Yoast SEO: article:tag meta or category links
    for m in re.finditer(r'<meta[^>]+property="article:tag"[^>]+content="([^"]+)"', html, re.I):
        tag = m.group(1).strip()
        if tag and tag not in tags:
            tags.append(tag)
    # Category from breadcrumb or category links
    for m in re.finditer(r'<a[^>]+href="https?://www\.pornomineiro\.com/videos/([^/]+)/"[^>]*>([^<]+)</a>', html, re.I):
        tag = m.group(2).strip()
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


def parse_listing(html, page_url):
    videos = []
    seen = set()

    # Cards: <div class="inline-block" itemscope ...> <a itemprop="URL" href="..." title="...">
    for m in re.finditer(
        r'<div[^>]*class="inline-block"[^>]*itemscope[^>]*>\s*'
        r'<a[^>]*itemprop="URL"[^>]*href="([^"]+)"[^>]*title="([^"]*)"',
        html, re.S | re.I
    ):
        source_url = m.group(1)
        title = m.group(2).strip()

        # Extract slug as external_id
        slug_match = re.search(r'/videos/[^/]+/([^/]+)/?$', source_url)
        external_id = slug_match.group(1) if slug_match else source_url

        if external_id in seen:
            continue
        seen.add(external_id)

        # Find thumbnail and duration in the surrounding block
        block_start = m.start()
        block_end = html.find('</div>', block_start + 500)
        if block_end < 0:
            block_end = block_start + 2000
        block = html[block_start:block_end + 200]

        thumbnail = ''
        im = re.search(r'<img[^>]+src="([^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"', block)
        if im:
            thumbnail = im.group(1)

        duration = 0
        dm = re.search(r'<span[^>]*>\s*(\d+:\d{2}(?::\d{2})?)\s*</span>', block)
        if dm:
            duration = parse_duration_mmss(dm.group(1))

        videos.append({
            'external_id': external_id,
            'source_url': source_url,
            'canonical_url': source_url.rstrip('/'),
            'title': title,
            'description': '',
            'tags': '',
            'duration': duration,
            'duration_formatted': fmt_duration(duration),
            'thumbnail_url': thumbnail,
        })

    return videos


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

    # Fallback: extract duration from raw HTML ("duration": "PT7M12S")
    if video['duration'] <= 0:
        m = re.search(r'"duration"\s*:\s*"(PT[^"]+)"', html)
        if m:
            video['duration'] = parse_iso_seconds(m.group(1))
            video['duration_formatted'] = fmt_duration(video['duration'])

    # Fallback: extract embed URL from iframe
    if not video.get('source_url'):
        m = re.search(r'<iframe[^>]+src="(https?://videos\.pornomineiro\.com/embed/\d+)', html)
        if m:
            video['source_url'] = m.group(1)

    if not video['tags']:
        tags = clean_tags(extract_tags(html))
        if tags:
            video['tags'] = tags


def build_page_url(url, page):
    if page <= 1:
        return url
    # WordPress: /page/N/
    base = url.rstrip('/')
    if re.search(r'/page/\d+', base):
        return re.sub(r'/page/\d+', '/page/' + str(page), base)
    return base + '/page/' + str(page) + '/'


def has_more_pages(html):
    if re.search(r'rel="next"\s+href="[^"]+"', html, re.I):
        return True
    if re.search(r'class="[^"]*next[^"]*"', html, re.I):
        return True
    return False


def scrape_listing(url, page=1, max_pages=1):
    page_url = build_page_url(url, page)
    html = fetch(page_url)
    if html is None:
        return {"error": "Failed to fetch page: %s" % page_url,
                "videos": [], "total": 0, "has_more": False}

    videos = parse_listing(html, page_url)
    has_more = has_more_pages(html)

    if videos:
        with ThreadPoolExecutor(max_workers=5) as pool:
            futures = [pool.submit(enrich_video, v) for v in videos]
            for f in futures:
                try:
                    f.result()
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
        print(json.dumps({"error": "Usage: pornomineiro_scrape.py <url> [page] [max_pages]"}))
        sys.exit(1)

    url = sys.argv[1]
    page = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 1

    result = scrape_listing(url, page, max_pages)
    print(json.dumps(result))
