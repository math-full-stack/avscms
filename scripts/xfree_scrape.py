#!/usr/bin/env python3
"""
xfree_scrape.py - Scrapes video listings from xfree.com profile pages.

Usage:
    python3 xfree_scrape.py <profile_url> [page] [max_pages]

Output: JSON with video list.

Titles are read first from the server-rendered profile wall (free, no extra
requests). Duration is not rendered on the wall, so each video-looking post is
fetched (concurrently, rate-limit aware) and real metadata is parsed from the
video page (og:video:duration / JSON-LD VideoObject).

Facts learned from live pages:
  * The wall renders one item per creator post. Real video posts carry a real
    title (text before the tags <span>) and usually a ?title= slug. Photo
    albums only carry tags and show an "Image media type" icon.
  * Video pages always expose og:video:duration (integer seconds) and a
    JSON-LD VideoObject with name + ISO-8601 duration. Some old posts render a
    generic placeholder name ("@user's Sex Reel On xfree.com ...") and photo
    albums a 2-second "reel" - both must be rejected so no fake data is stored.
"""
import sys
import json
import re
import html as html_mod
import time
import random
from concurrent.futures import ThreadPoolExecutor

try:
    from curl_cffi import requests
except ImportError:
    print(json.dumps({"error": "curl_cffi not installed. Run: pip3 install curl_cffi"}))
    sys.exit(1)

RATE_LIMITED = []  # shared stop flag once xfree answers 429 / block page

# Tags that say nothing about the content - never used to synthesize titles.
TAG_STOPLIST = set([
    'amateur', 'amateurs', 'onlyfans', 'of', 'fyp', 'sexy', 'beautiful',
    'photos', 'photo', 'vertical porn', 'leaks', 'xxx', 'porn', 'tiktok',
    'xfree', 'tease', 'teasing', 'hot', 'babe', 'girl', 'new', 'brazil',
])


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
    m = re.match(r'^P(?:(?P<d>\d+)D)?(?:T(?:(?P<h>\d+)H)?(?:(?P<mi>\d+)M)?(?:(?P<s>\d+)S)?)?$',
                 dur.strip())
    if not m:
        return 0
    d = int(m.group('d') or 0)
    h = int(m.group('h') or 0)
    mi = int(m.group('mi') or 0)
    s = int(m.group('s') or 0)
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


_PLACEHOLDER_RE = re.compile(
    r"(Sex Reel On xfree\.com|TikTok Porn|@[\w.\-]+\s*'s?\s*(Sex Reel|Page))", re.I)


def _clean_page_title(name):
    """Strip xfree suffixes; return '' for generic placeholder names."""
    if not name:
        return ''
    name = html_mod.unescape(str(name)).strip()
    # "Real title ... - @user's Sex Reel On xfree.com – TikTok Porn & Shorts"
    name = re.split(r'\s+-\s*@', name)[0].strip()
    if _PLACEHOLDER_RE.search(name):
        return ''
    # Generic names that start with the username
    if re.match(r'^@[\w.\-]+', name):
        return ''
    return name


def _parse_video_page(html_text):
    """Extract metadata from a xfree video page.

    Returns dict(title, duration, thumbnail) with duration in seconds.
    Real videos always expose og:video:duration (int seconds). The JSON-LD
    VideoObject name/duration are used as fallback/extra. Placeholder names
    ('... Sex Reel On xfree.com ...') and <3s slideshow reels (photo albums)
    are treated as missing so nothing fake is stored.
    """
    # Prefer og:video:duration: integer seconds, always truthful.
    duration = 0
    m = re.search(r'property="og:video:duration"\s+content="(\d+)"', html_text)
    if m:
        duration = int(m.group(1))

    name = ''
    thumb = ''
    m = re.search(r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>',
                  html_text, re.S)
    if m:
        try:
            data = json.loads(m.group(1).strip())
        except Exception:
            data = None
        if isinstance(data, dict) and data.get('@type') == 'VideoObject':
            name = _clean_page_title(data.get('name'))
            if duration <= 0:
                duration = _iso_seconds(str(data.get('duration') or ''))
            if data.get('thumbnailUrl'):
                thumb = str(data['thumbnailUrl'])
    if not name:
        # og:title fallback
        m2 = re.search(r'property="og:title"\s+content="([^"]*)"', html_text) \
            or re.search(r'content="([^"]*)"[^>]*property="og:title"', html_text)
        if m2:
            name = _clean_page_title(m2.group(1))

    # <3s = the auto "reel" of a photo album, not a real video post.
    if duration < 3:
        duration = 0
    return {
        'title': name,
        'duration': duration,
        'thumbnail': thumb,
    }


def _fetch_video_meta(url):
    """Fetch one video page and parse its metadata. Rate-limit aware."""
    for attempt in (0, 1):
        try:
            r = requests.get(url, impersonate='chrome', timeout=15)
        except Exception:
            time.sleep(1)
            continue
        if r.status_code == 429 or 'rate limit exceeded' in r.text.lower():
            if attempt == 0:
                time.sleep(3 + random.random() * 3)
                continue
            RATE_LIMITED.append(True)
            return {'rate_limited': True}
        if r.status_code != 200:
            return {'error': True}
        return _parse_video_page(r.text)
    return {'error': True}


def _profile_label(url):
    """Human-readable model name from the profile URL (e.g. misha.stojkovic)."""
    m = re.search(r'xfree\.com/([a-z0-9._-]+)', url or '', re.I)
    if not m:
        return 'XFree'
    raw = m.group(1)
    words = re.split(r'[._-]+', raw)
    return ' '.join(w[:1].upper() + w[1:] for w in words if w) or 'XFree'


def _synth_title(tags):
    """Build a readable title from the most descriptive tags (last resort)."""
    words = []
    for t in tags.split():
        key = re.sub(r'^#', '', t).lower().replace('_', ' ')
        if key in TAG_STOPLIST:
            continue
        words.append(re.sub(r'^#', '', t))
        if len(words) >= 4:
            break
    if not words and tags:
        words = [re.sub(r'^#', '', tags.split()[0])]
    return ' '.join(words) if words else ''


def _enrich_page(videos):
    """Fetch metadata (duration/title) for the video posts of one page."""
    to_fetch = [v for v in videos if v.get('media_type') == 'video']
    if not to_fetch:
        return
    with ThreadPoolExecutor(max_workers=5) as pool:
        futures = {}
        for v in to_fetch:
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
            if not v['title'] and meta.get('title'):
                v['title'] = meta['title']
            if v['duration'] <= 0 and meta.get('duration'):
                v['duration'] = int(meta['duration'])
            if meta.get('thumbnail'):
                v['thumbnail_url'] = meta['thumbnail']
            # If the post turned out to be a photo album (no real duration),
            # make sure no placeholder title leaks in.
            if meta.get('duration') <= 0 and not v['_wall_has_title']:
                v['title'] = ''


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
    #       <img ... alt="#Tag1 #Tag2" id="wall-12345" src="https://thumbs.xfree.com/...">
    #     ...
    #       <div class="wall__item__icon"><img ... alt="Image media type"></div>  <- photo album
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

    # Titles + media type: parse each wall item block so titles stay attached
    # to their id. Photo albums carry only tags and an "Image media type" icon.
    wall_titles = {}
    wall_has_title = {}
    is_image = {}
    slugs = {}
    chunks = html_text.split('class="wall__item-holder"')[1:]
    for chunk in chunks:
        m = re.search(r'/video\?id=(\d+)', chunk)
        if not m:
            continue
        vid = m.group(1)

        # slug from the href: ...&title=cute-brunette-...&user=...
        slug = ''
        sm = re.search(r'&(?:amp;)?title=([a-zA-Z0-9\-]+)', chunk)
        if sm:
            slug = sm.group(1).replace('-', ' ').strip()
        slugs[vid] = slug

        # image-type icon? (alt text of the svg inside .wall__item__icon)
        im = re.search(r'class="wall__item__icon"[^>]*>.*?\balt="([^"]*)"', chunk, re.S)
        is_image[vid] = bool(im and ('image' in (im.group(1) or '').lower()
                                     or 'photo' in (im.group(1) or '').lower()))

        # Title = text BEFORE the tags <span> inside the description div.
        title = ''
        t = re.search(r'class="description line-clamp"[^>]*>(.*?)</div>', chunk, re.S)
        if t:
            inner = t.group(1)
            before_span = re.split(r'<span', inner, maxsplit=1)[0]
            before_span = re.sub(r'<[^>]+>', ' ', before_span)
            before_span = html_mod.unescape(re.sub(r'\s+', ' ', before_span)).strip()
            if before_span:
                title = before_span
        if not title:
            title = slug
        if title:
            wall_titles[vid] = title
            wall_has_title[vid] = True

    n_wall_items = len(chunks)

    page_videos = []
    for vid in video_ids:
        alt_text = alts.get(vid, '')
        tags = clean_tags(alt_text)

        title = wall_titles.get(vid, '')
        img_post = is_image.get(vid, False)
        if img_post:
            # Photo albums / image sets are NOT videos: they have no real
            # title and no playable duration, and they can't be grabbed.
            # Skip them so the Discover listing only holds real videos.
            continue

        page_videos.append({
            'external_id': vid,
            'source_url': 'https://www.xfree.com/video?id=' + vid,
            'canonical_url': 'https://www.xfree.com/video?id=' + vid,
            'title': title,
            'description': tags,
            'tags': tags,
            'duration': 0,
            'duration_formatted': '',
            'thumbnail_url': thumbs.get(vid, ''),
            'media_type': 'video',
            '_wall_has_title': bool(wall_has_title.get(vid)),
        })

    # Duration is not on the wall - enrich video posts from their own pages.
    _enrich_page(page_videos)

    profile_label = _profile_label(url)
    out_videos = []
    for v in page_videos:
        # Fill any remaining blank title from the tags (videos whose posts only
        # carry tags render a generic placeholder on their page too).
        if not v['title']:
            v['title'] = _synth_title(v['tags'])
        # Last resort: a real video whose post carries no caption at all (xfree
        # only renders a generic placeholder for those). Never leave a blank
        # title in the Discover listing.
        if not v['title']:
            v['title'] = profile_label + ' Reel'
        v['duration'] = int(v['duration'])
        v['duration_formatted'] = _fmt_duration(v['duration'])
        v.pop('_wall_has_title', None)
        out_videos.append(v)

    total = total_from_meta if total_from_meta > 0 else len(page_videos)
    # Keep scanning while the wall page itself returned items (even when they
    # were all photo albums - real videos may sit on later pages).
    has_more = n_wall_items > 0

    return {
        'videos': out_videos,
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
