#!/usr/bin/env python3
"""
pornobrasil_scrape.py - Discovers videos from pornobrasil.com listings.

Usage:
    python3 pornobrasil_scrape.py <url> [page] [max_pages]

Output: JSON with video list.

pornobrasil.com is a tube site with server-rendered HTML listings. Video cards
contain links, thumbnails and titles. Video pages expose players with embedded
video URLs.

Structure observed (2026-09):
  * Cards: <a href="..."> with <img> thumbnails and title text
  * Pagination: standard page links
  * Video pages: embedded player with video source
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
    for m in re.finditer(r'<a[^>]+href="[^"]*(?:category|tag)/([^/"]+)"[^>]*>([^<]+)</a>', html, re.I):
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

    # pornobrasil.com uses <div class='thumb-block'> with:
    #   <a href="URL" title="TITLE"><div class="post-thumbnail">...<span class="duration">MM:SS</span></div></a>
    #   <header><a class="title" href="URL" title="TITLE">TITLE</a></header>
    # URLs are clean slugs: /slug-here/ (no /video/ prefix)

    # Strategy 1: thumb-block divs
    thumb_blocks = re.findall(
        r"<div class='thumb-block'>(.*?)</div>\s*</div>\s*</div>",
        html, re.S
    )
    if not thumb_blocks:
        # Fallback: split by thumb-block
        thumb_blocks = re.split(r"<div class='thumb-block'>", html)[1:]

    for block in thumb_blocks:
        # Link — first <a> with title attr pointing to pornobrasil.com
        href_match = re.search(
            r'<a[^>]+href="(https?://pornobrasil\.com/[^"]+/)"[^>]*title="([^"]*)"',
            block
        )
        if not href_match:
            # Try without title attr
            href_match = re.search(
                r'<a[^>]+href="(https?://pornobrasil\.com/[^"]+/)"',
                block
            )
        if not href_match:
            continue
        href = href_match.group(1)
        title = href_match.group(2).strip() if href_match.lastindex >= 2 else ''

        # Skip category/tag/other non-video links
        if re.search(r'/(page|categoria|tag|canal|estrelas-porno|categorias|feed)/', href):
            continue
        if href == 'https://pornobrasil.com/':
            continue

        slug = href.rstrip('/').rsplit('/', 1)[-1]
        if not slug or slug in seen:
            continue
        seen.add(slug)

        # Title fallback: <a class="title" ...>TITLE</a>
        if not title:
            title_m = re.search(r'<a\s+class="title"[^>]*>(.*?)</a>', block, re.S)
            if title_m:
                title = re.sub(r'<[^>]+>', '', title_m.group(1)).strip()

        # Duration
        dur = ''
        dur_m = re.search(r'<span class="duration">(\d+:\d{2}(?::\d{2})?)</span>', block)
        if dur_m:
            dur = dur_m.group(1)

        # Thumbnail from data-src (lazyloaded) or src
        thumbnail = ''
        im = re.search(r'data-src="([^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"', block)
        if not im:
            im = re.search(r'<img[^>]+src="([^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"', block)
        if im:
            thumbnail = im.group(1)

        videos.append({
            'external_id': slug,
            'source_url': href,
            'canonical_url': href.rstrip('/'),
            'title': title,
            'description': '',
            'tags': '',
            'duration': parse_duration_mmss(dur),
            'duration_formatted': dur,
            'thumbnail_url': thumbnail,
        })

    # Strategy 2: generic link pattern for pages without thumb-block structure
    if not videos:
        for m in re.finditer(
            r'<a[^>]+href="(https?://pornobrasil\.com/[^"]+/)"[^>]*title="([^"]*)"',
            html
        ):
            href = m.group(1)
            title = m.group(2).strip()
            if re.search(r'/(page|categoria|tag|canal|estrelas-porno|categorias|feed)/', href):
                continue
            slug = href.rstrip('/').rsplit('/', 1)[-1]
            if not slug or slug in seen:
                continue
            seen.add(slug)
            videos.append({
                'external_id': slug,
                'source_url': href,
                'canonical_url': href.rstrip('/'),
                'title': title,
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

    # Fallback: extract duration from <meta itemprop="duration"> (e.g. P0DT0H33M35S)
    if video['duration'] <= 0:
        m = re.search(r'<meta[^>]+itemprop="duration"[^>]+content="(P[^"]+)"', html, re.I)
        if not m:
            m = re.search(r'content="(P[^"]+)"[^>]*itemprop="duration"', html, re.I)
        if m:
            video['duration'] = parse_iso_seconds(m.group(1))
            video['duration_formatted'] = fmt_duration(video['duration'])

    # Fallback: extract duration from <span class="duration">MM:SS</span>
    if video['duration'] <= 0:
        m = re.search(r'<span\s+class="duration">\s*(\d{1,2}:\d{2}(?::\d{2})?)\s*</span>', html)
        if m:
            video['duration'] = parse_duration_mmss(m.group(1))
            video['duration_formatted'] = m.group(1)

    if not video['tags']:
        tags = clean_tags(extract_tags(html))
        if tags:
            video['tags'] = tags

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


def scrape_profile(url, page=1, max_pages=1):
    page_url = build_page_url(url, page)
    html = fetch(page_url)
    if html is None:
        return {"error": "Failed to fetch page: %s" % page_url,
                "videos": [], "total": 0, "has_more": False}

    result = parse_listing(html, page_url)
    videos = result.get('videos', [])
    has_more = result.get('has_more', False)

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
        print(json.dumps({"error": "Usage: pornobrasil_scrape.py <url> [page] [max_pages]"}))
        sys.exit(1)

    url = sys.argv[1]
    page = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 1

    result = scrape_profile(url, page, max_pages)
    print(json.dumps(result))
