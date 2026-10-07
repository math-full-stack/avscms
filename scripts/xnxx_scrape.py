#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import sys
import json
import re
from urllib.parse import urljoin

try:
    from curl_cffi import requests
except Exception as e:
    print(json.dumps({
        "status": False,
        "error": f"curl_cffi not available: {e}",
        "hint": "Instale curl-cffi (pip install curl-cffi)"
    }))
    sys.exit(0)

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
    "Accept-Language": "pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7",
    "Connection": "keep-alive",
    "Upgrade-Insecure-Requests": "1",
}

def fetch(url, timeout=60):
    try:
        r = requests.get(url, headers=HEADERS, timeout=timeout, impersonate="chrome110")
        if r.status_code != 200:
            return None
        return r.text
    except Exception:
        return None

def parse_duration_text(txt):
    if not txt:
        return 0
    s = txt.lower()
    total = 0
    m = re.search(r'(\d+)\s*h', s)
    if m:
        total += int(m.group(1)) * 3600
    m = re.search(r'(\d+)\s*m', s)
    if m:
        total += int(m.group(1)) * 60
    m = re.search(r'(\d+)\s*s', s)
    if m:
        total += int(m.group(1))
    if total == 0:
        m2 = re.search(r'(\d+)\s*min', s)
        if m2:
            total += int(m2.group(1)) * 60
    return total

def extract_videos(html):
    videos = []

    # Variant 1: hits/best
    for m in re.finditer(r'<div[^>]+id="video-thumb-([a-z0-9]+)"[^>]+class="[^"]*thumb-block[^"]*video[^"]*"[^>]+data-video=\'([^\']+)\'', html, re.I | re.S):
        eid = m.group(1).strip()
        dv_raw = m.group(2)
        try:
            dv = json.loads(dv_raw)
        except Exception:
            dv = {}
        sfw_thumb = dv.get('sfwThumbUrl') or dv.get('thumbUrl') or dv.get('previewThumbUrl') or ''
        vid_url = f"/video-{eid}/"
        title = ''
        t_m = re.search(rf'<a[^>]+class="[^"]*title[^"]*"[^>]+href="/video-{re.escape(eid)}/[^"]*"[^>]+title="([^"]+)"', html, re.I | re.S)
        if t_m:
            title = t_m.group(1).strip()
        else:
            t_m2 = re.search(rf'<a[^>]+href="/video-{re.escape(eid)}/[^"]*"[^>]+title="([^"]+)"', html, re.I | re.S)
            if t_m2:
                title = t_m2.group(1).strip()
        thumb_url = sfw_thumb
        if thumb_url and thumb_url.startswith('//'):
            thumb_url = 'https:' + thumb_url
        elif thumb_url and not thumb_url.startswith('http'):
            thumb_url = urljoin('https://www.xnxx.com', thumb_url)
        dur_sec = 0
        block_m = re.search(rf'<div[^>]+id="video-thumb-{re.escape(eid)}"[^>]*>(.*?)</div>\s*</div>', html, re.I | re.S)
        block_txt = block_m.group(1) if block_m else ''
        if block_txt:
            md_m = re.search(r'<div[^>]+class="[^"]*metadata[^"]*"[^>]*>(.*?)</div>', block_txt, re.I | re.S)
            if md_m:
                dur_sec = parse_duration_text(md_m.group(1))
        videos.append({
            'external_id': eid,
            'source_url': urljoin('https://www.xnxx.com', vid_url),
            'canonical_url': urljoin('https://www.xnxx.com', vid_url),
            'title': title,
            'description': '',
            'tags': '',
            'category': '',
            'duration': dur_sec,
            'duration_formatted': '',
            'thumbnail_url': thumb_url,
        })

    if len(videos) == 0:
        for m in re.finditer(r'<div[^>]+id="video_([a-z0-9]+)"[^>]+class="[^"]*thumb-block[^"]*"', html, re.I | re.S):
            eid = m.group(1).strip()
            vid_url = f"/video-{eid}/"
            title = ''
            t_m = re.search(rf'<a[^>]+class="[^"]*title[^"]*"[^>]+href="/video-{re.escape(eid)}/[^"]*"[^>]+title="([^"]+)"', html, re.I | re.S)
            if t_m:
                title = t_m.group(1).strip()
            else:
                t_m2 = re.search(rf'<a[^>]+href="/video-{re.escape(eid)}/[^"]*"[^>]+title="([^"]+)"', html, re.I | re.S)
                if t_m2:
                    title = t_m2.group(1).strip()
            thumb_url = ''
            img_m = re.search(rf'<div[^>]+id="video_{re.escape(eid)}"[^>]*>.*?<img[^>]+data-src="([^"]+)"', html, re.I | re.S)
            if img_m:
                thumb_url = img_m.group(1)
            dur_sec = 0
            md_m = re.search(rf'<div[^>]+id="video_{re.escape(eid)}"[^>]*>.*?<div[^>]+class="[^"]*metadata[^"]*"[^>]*>(.*?)</div>', html, re.I | re.S)
            if md_m:
                dur_sec = parse_duration_text(md_m.group(1))
            videos.append({
                'external_id': eid,
                'source_url': urljoin('https://www.xnxx.com', vid_url),
                'canonical_url': urljoin('https://www.xnxx.com', vid_url),
                'title': title,
                'description': '',
                'tags': '',
                'category': '',
                'duration': dur_sec,
                'duration_formatted': '',
                'thumbnail_url': thumb_url,
            })

    seen = set()
    out = []
    for v in videos:
        eidv = v.get('external_id') or ''
        if not eidv or eidv in seen:
            continue
        seen.add(eidv)
        out.append(v)
    return out

def has_more_page(html):
    if not html:
        return False
    if re.search(r'class="[^"]*dir[^"]*next[^"]*"', html, re.I):
        return True
    if re.search(r'class="[^"]*no-page[^"]*next[^"]*"', html, re.I):
        return True
    return False

def build_page_url(base_url, page):
    if page <= 1:
        return base_url
    bu = base_url.rstrip('/')
    return bu + '/' + str(page - 1)

def main():
    if len(sys.argv) < 4:
        print(json.dumps({"status": False, "error": "Usage: xnxx_scrape.py <url> <page> <max_pages>"}))
        return
    url_base = sys.argv[1].strip()
    try:
        page_cur = int(sys.argv[2])
    except ValueError:
        page_cur = 1
    try:
        max_pages = int(sys.argv[3])
    except ValueError:
        max_pages = 1
    page_url = build_page_url(url_base, page_cur)
    html = fetch(page_url, timeout=180)
    if html is None:
        print(json.dumps({"status": False, "error": f"Failed to fetch {page_url}"}))
        return
    videos = extract_videos(html)
    more = has_more_page(html)
    if page_cur >= max_pages:
        more = False
    print(json.dumps({"status": True, "videos": videos, "has_more": more, "total": len(videos), "page": page_cur}))

if __name__ == "__main__":
    main()
