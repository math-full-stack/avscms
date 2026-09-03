#!/usr/bin/env python3
"""
xfree_scrape.py - Scrapes video listings from xfree.com profile pages.

Usage:
    python3 xfree_scrape.py <profile_url> [page] [max_pages]

Output: JSON with video list.

Titles are read directly from the server-rendered profile wall (free, no
extra requests). Duration is not rendered on the wall, so each video page is
fetched (concurrently, rate-limit aware) and the JSON-LD VideoObject block is
parsed for duration/title/thumbnail.
"""
import sys
import json
import re
import html as html_mod
from concurrent.futures import ThreadPoolExecutor

try:
    from curl_cffi import requests
except ImportError:
    print(json.dumps({"error": "curl_cffi not installed. Run: pip3 install curl_cffi"}))
    sys.exit(1)

RATE_LIMITED = []  # shared stop flag once xfree answers 429 / block page


def clean_tags(tag_text):
    """Turn '#Tag1 #Tag2' text into a comma/space separated tags string."""
    if not tag_text:
        return ''
    tags = [t.strip() for t in re.split(r'[\s,]+', tag_text) if t.strip()]
    return ' '.join(tags)


def _iso_seconds(dur):
    """Convert ISO-8601 duration (PT8M34S) to seconds."""
    if not dur:
        return 0
    m = re.match(r'^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$', dur.strip())
    if not m:
        return 0
    d, h, mi, s = (int(x or 0) for x in m.groups())
    return d * 86400 + h * 3600 + mi * 60 + s


def _fmt_duration(seconds):
    seconds = max(0, int(seconds))
    if seconds <= 0:
        return ''
    h = seconds // 3600
    m = (seconds % 3600) // 60
    s = seconds % 60
    if h > 0:
        return '%d:%02d:%02d' % (h, m, s)
    return '%d:%02d' % (m, s)


def _parse_video_page(html_text):
    """Extract metadata from a xfree video page (JSON-LD VideoObject)."""
    m = re.search(r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>',
                  html_text, re.S)
    if not m:
        return None
    try:
        data = json.loads(m.group(1).strip())
    except Exception:
        return None
    if not isinstance(data, dict) or data.get('@type') != 'VideoObject':
        return None

    name = ''
    if data.get('name'):
        name = html_mod.unescape(str(data['name'])).strip()
        # Cut the " - @user's Sex Reel On xfree.com ..." suffix
        name = re.split(r'\s+-\s*@', name)[0].strip()

    return {
        'title': name,
        'duration': _iso_seconds(str(data.get('duration') or '')),
        'thumbnail': (data.get('thumbnailUrl') or ''),
    }


def _fetch_video_meta(url):
    """Fetch one video page and parse its metadata. Rate-limit aware."""
    try:
        r = requests.get(url, impersonate='chrome', timeout=15)
    except Exception:
        return {'error': True}
    if r.status_code == 429 or 'rate limit exceeded' in r.text.lower():
        RATE_LIMITED.append(True)
        return {'rate_limited': True}
    if r.status_code != 200:
        return {'error': True}
    meta = _parse_video_page(r.text)
    return meta if meta else {'error': True}


def _enrich_page(videos):
    """Fetch metadata (duration/title) for the videos of one page."""
    if not videos:
        return
    with ThreadPoolExecutor(max_workers=5) as pool:
        futures = {}
        for v in videos:
            if RATE_LIMITED:
                break
            futures[pool.submit(_fetch_video_meta, v['source_url'])] = v
        for fut, v in futures.items():
            try:
                meta = fut.result()
            except Exception:
                continue
            if not meta or meta.get('error'):
                continue
            if meta.get('rate_limited'):
                RATE_LIMITED.append(True)
                break
            if v['duration'] <= 0 and meta.get('duration'):
                v['duration'] = int(meta['duration'])
            if not v['title'] and meta.get('title'):
                v['title'] = meta['title']
            if meta.get('thumbnail'):
                v['thumbnail_url'] = meta['thumbnail']


def scrape_profile(url, page=1, max_pages=1):
    page_url = url
    if page > 1:
        separator = '&' if '?' in url else '?'
        page_url = url + separator + 's=' + str((page - 1) * 20)

    try:
        r = requests.get(page_url, impersonate='chrome', timeout=30)
    except Exception as e:
        return {"error": str(e), "videos": [], "total": 0, "has_more": False}

    if r.status_code == 429:
        return {"error": "Rate limit exceeded. Please try again later.", "videos": [], "total": 0, "has_more": False}

    if r.status_code != 200:
        return {"error": "HTTP %d" % r.status_code, "videos": [], "total": 0, "has_more": False}

    html_text = r.text

    # xfree sometimes answers 200 with a rate-limit/block page
    lowered = html_text.lower()
    if 'rate limit exceeded' in lowered or 'too many requests' in lowered:
        return {"error": "Rate limit exceeded. Please try again later.", "videos": [], "total": 0, "has_more": False}

    # Total count from page meta (e.g. "259 ... REELS" / "259 ... VIDEOS")
    total_from_meta = 0
    m = re.search(r'(\d[\d,.]*)\s*(?:FREE\s*)?(?:PORN\s*)?(?:REELS|VIDEOS)', html_text, re.I)
    if m:
        total_from_meta = int(re.sub(r'[^\d]', '', m.group(1)))

    # Current xfree wall markup (server-rendered):
    #   <div class="wall__item-holder"><div class="wall__item">
    #     <a href="/video?id=12345&amp;title=slug&amp;user=xxx" class="wall__item__media">
    #       <img ... alt="#Tag1 #Tag2" id="wall-12345" src="https://thumbs.xfree.com/..." >
    #     <div class="video-attributes-group video-attributes-group--b" style="display:none;">
    #       <div class="wall__item__metadata">
    #         <div class="description line-clamp">
    #           Real video title <span>#Tag1 #Tag2</span></div>
    video_ids = []
    seen = set()
    for m in re.finditer(r'/video\?id=(\d+)', html_text):
        vid = m.group(1)
        if vid not in seen:
            seen.add(vid)
            video_ids.append(vid)

    # Thumbnails: id="wall-{id}" followed by src= inside the same <img>
    thumbs = dict(re.findall(r'id="wall-(\d+)"[^>]*\bsrc="([^"]+)"', html_text))

    # Alt text / tags per wall item (alt comes BEFORE id in the current markup)
    alts = {}
    for m in re.finditer(r'<img[^>]*\balt="([^"]*)"[^>]*id="wall-(\d+)"', html_text):
        alts[m.group(2)] = m.group(1)

    # Titles: parse each wall item block so titles stay attached to their id.
    wall_titles = {}
    chunks = html_text.split('class="wall__item-holder"')[1:]
    for chunk in chunks:
        m = re.search(r'/video\?id=(\d+)', chunk)
        if not m:
            continue
        vid = m.group(1)
        title = ''
        t = re.search(r'class="description line-clamp"[^>]*>(.*?)</div>', chunk, re.S)
        if t:
            seg = re.sub(r'<span.*?</span>', ' ', t.group(1), flags=re.S)
            seg = re.sub(r'<[^>]+>', ' ', seg)
            title = html_mod.unescape(re.sub(r'\s+', ' ', seg)).strip()
        if not title:
            slug = re.search(r'&amp;title=([a-zA-Z0-9\-]+)', chunk) or re.search(r'&title=([a-zA-Z0-9\-]+)', chunk)
            if slug:
                title = slug.group(1).replace('-', ' ').strip()
        if title:
            wall_titles[vid] = title

    page_videos = []
    for vid in video_ids:
        alt_text = alts.get(vid, '')
        tags = clean_tags(alt_text)

        page_videos.append({
            'external_id': vid,
            'source_url': 'https://www.xfree.com/video?id=' + vid,
            'canonical_url': 'https://www.xfree.com/video?id=' + vid,
            'title': wall_titles.get(vid, ''),
            'description': tags,
            'tags': tags,
            'duration': 0,
            'duration_formatted': '',
            'thumbnail_url': thumbs.get(vid, ''),
        })

    # Duration is not on the wall - enrich each video from its own page.
    _enrich_page(page_videos)

    for v in page_videos:
        v['duration'] = int(v['duration'])
        v['duration_formatted'] = _fmt_duration(v['duration'])

    total = total_from_meta if total_from_meta > 0 else len(page_videos)
    has_more = len(page_videos) > 0

    return {
        'videos': page_videos,
        'total': total,
        'has_more': has_more,
    }


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: xfree_scrape.py <url> [page] [max_pages]"}))
        sys.exit(1)

    url = sys.argv[1]
    page = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 1

    result = scrape_profile(url, page, max_pages)
    print(json.dumps(result))
