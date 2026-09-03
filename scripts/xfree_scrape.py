#!/usr/bin/env python3
"""
xfree_scrape.py - Scrapes video listings from xfree.com profile pages.

Usage:
    python3 xfree_scrape.py <profile_url> [page] [max_pages]

Output: JSON with video list.
"""
import sys
import json
import re

try:
    from curl_cffi import requests
except ImportError:
    print(json.dumps({"error": "curl_cffi not installed. Run: pip3 install curl_cffi"}))
    sys.exit(1)


def clean_tags(tag_text):
    """Turn '#Tag1 #Tag2' text into a comma/space separated tags string."""
    if not tag_text:
        return ''
    tags = [t.strip() for t in re.split(r'[\s,]+', tag_text) if t.strip()]
    return ' '.join(tags)


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

    html = r.text

    # xfree sometimes answers 200 with a rate-limit/block page
    lowered = html.lower()
    if 'rate limit exceeded' in lowered or 'too many requests' in lowered:
        return {"error": "Rate limit exceeded. Please try again later.", "videos": [], "total": 0, "has_more": False}

    # Total count from page meta (e.g. "259 ... REELS" / "259 ... VIDEOS")
    total_from_meta = 0
    m = re.search(r'(\d[\d,.]*)\s*(?:FREE\s*)?(?:PORN\s*)?(?:REELS|VIDEOS)', html, re.I)
    if m:
        total_from_meta = int(re.sub(r'[^\d]', '', m.group(1)))

    # Current xfree wall markup:
    #   <a href="/video?id=12345&amp;user=xxx" class="wall__item__media">
    #     <img ... alt="#Tag1 #Tag2" id="wall-12345" src="https://thumbs.xfree.com/..." >
    #
    # Older markup used /video?id=N&title=... — we no longer rely on that.
    video_ids = []
    seen = set()
    for m in re.finditer(r'/video\?id=(\d+)', html):
        vid = m.group(1)
        if vid not in seen:
            seen.add(vid)
            video_ids.append(vid)

    # Thumbnails: id="wall-{id}" followed by src= inside the same <img>
    thumbs = dict(re.findall(r'id="wall-(\d+)"[^>]*\bsrc="([^"]+)"', html))

    # Alt text / tags per wall item (alt comes BEFORE id in the current markup)
    alts = {}
    for m in re.finditer(r'<img[^>]*\balt="([^"]*)"[^>]*id="wall-(\d+)"', html):
        alts[m.group(2)] = m.group(1)

    page_videos = []
    for vid in video_ids:
        alt_text = alts.get(vid, '')
        tags = clean_tags(alt_text)

        page_videos.append({
            'external_id': vid,
            'source_url': 'https://www.xfree.com/video?id=' + vid,
            'canonical_url': 'https://www.xfree.com/video?id=' + vid,
            # xfree does not server-render titles on profile walls anymore;
            # the real title is fetched during grab/import.
            'title': '',
            'description': tags,
            'tags': tags,
            'duration': 0,
            'duration_formatted': '',
            'thumbnail_url': thumbs.get(vid, ''),
        })

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
