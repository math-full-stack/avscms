#!/usr/bin/env python3
"""
sonovinhasbr_scrape.py - Scrapes video listings from sonovinhasbr.com

Usage:
    python3 sonovinhasbr_scrape.py <url> [page] [max_pages]

Output: JSON with video list.
"""
import sys
import json
import re
import time
import warnings
warnings.filterwarnings('ignore')

try:
    import requests
except ImportError:
    print(json.dumps({"error": "requests not installed. Run: pip3 install requests"}))
    sys.exit(1)

try:
    from bs4 import BeautifulSoup
except ImportError:
    BeautifulSoup = None


HEADERS = {
    'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language': 'pt-BR,pt;q=0.9,en;q=0.8',
}


def parse_video_object(html):
    """Extract video metadata from VideoObject JSON-LD script tag."""
    # Try standalone VideoObject script first (preferred)
    match = re.search(
        r'<script[^>]*>\s*\{\s*"@context"\s*:\s*"https?://schema\.org"\s*,\s*"@type"\s*:\s*"VideoObject".*?\}\s*</script>',
        html, re.S | re.I
    )
    if match:
        try:
            data = json.loads(match.group(0).split('>', 1)[1].rsplit('<', 1)[0])
            return data
        except Exception:
            pass

    # Try finding VideoObject inside a @graph array
    match = re.search(
        r'"@type"\s*:\s*"VideoObject"\s*,\s*"name"',
        html, re.I
    )
    if match:
        # Extract the full JSON-LD block containing this
        script_match = re.search(
            r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>',
            html, re.S | re.I
        )
        if script_match:
            try:
                data = json.loads(script_match.group(1))
                if isinstance(data, dict) and '@graph' in data:
                    for item in data['@graph']:
                        if item.get('@type') == 'VideoObject':
                            return item
            except Exception:
                pass

    return None


def parse_duration_iso(iso_str):
    """Convert ISO 8601 duration (PT00H02M00S) to seconds."""
    if not iso_str:
        return 0
    m = re.match(r'PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?', iso_str)
    if not m:
        return 0
    hours = int(m.group(1) or 0)
    mins = int(m.group(2) or 0)
    secs = int(m.group(3) or 0)
    return hours * 3600 + mins * 60 + secs


def format_duration(seconds):
    """Format seconds as HH:MM:SS or MM:SS."""
    seconds = int(seconds)
    hours = seconds // 3600
    mins = (seconds % 3600) // 60
    secs = seconds % 60
    if hours > 0:
        return f'{hours:02d}:{mins:02d}:{secs:02d}'
    return f'{mins:02d}:{secs:02d}'


def extract_post_id(url):
    """Extract WordPress post ID from player URL or post URL."""
    m = re.search(r'[?&]v=(\d+)', url)
    if m:
        return m.group(1)
    return None


def scrape_listing_page(url):
    """Scrape a single listing page and return video entries."""
    try:
        r = requests.get(url, headers=HEADERS, timeout=30)
    except Exception as e:
        return {'error': str(e), 'videos': [], 'has_more': False}

    if r.status_code != 200:
        return {'error': f'HTTP {r.status_code}', 'videos': [], 'has_more': False}

    html = r.text
    videos = []

    if BeautifulSoup:
        soup = BeautifulSoup(html, 'html.parser')
        posts = soup.select('.post')
        for post in posts:
            thumb_link = post.select_one('.post-thumb a[href]')
            if not thumb_link:
                continue
            href = thumb_link.get('href', '')
            if not href or 'sonovinhasbr.com' not in href:
                continue

            # Skip ad posts (nofollow external links)
            if thumb_link.get('rel') and 'nofollow' in thumb_link.get('rel', []):
                continue

            title_el = post.select_one('.post-conteudo h2') or post.select_one('a h2')
            title = title_el.get_text(strip=True) if title_el else ''

            img_el = post.select_one('.post-thumb img')
            thumbnail = ''
            if img_el:
                thumbnail = img_el.get('src', '') or img_el.get('data-src', '')
                # Try srcset for higher quality
                srcset = img_el.get('srcset', '')
                if srcset:
                    parts = srcset.split(',')
                    best_url = ''
                    best_w = 0
                    for part in parts:
                        part = part.strip()
                        tokens = part.split()
                        if len(tokens) >= 2:
                            try:
                                w = int(tokens[1].replace('w', ''))
                            except ValueError:
                                w = 0
                            if w > best_w:
                                best_w = w
                                best_url = tokens[0]
                    if best_url:
                        thumbnail = best_url

            post_id = extract_post_id(href)

            videos.append({
                'external_id': post_id or '',
                'source_url': href,
                'canonical_url': href.rstrip('/'),
                'title': title,
                'description': '',
                'tags': '',
                'duration': 0,
                'duration_formatted': '',
                'thumbnail_url': thumbnail,
            })

        # Check for next page
        has_more = False
        next_link = soup.select_one('a.next, .nav-next a, a[rel="next"]')
        if next_link:
            has_more = True
    else:
        # Fallback: regex-based parsing
        # Find all post link blocks: <div class="post"> ... <a href="URL"> ... <h2>TITLE</h2>
        # Strategy: find all .post blocks by splitting on them
        post_pattern = re.compile(r'<div class="post">\s*<div class="post-conteudo">(.*?)</div>\s*</div>', re.S)
        post_blocks = post_pattern.findall(html)

        # If that fails, try a more flexible approach: find all internal links with h2
        if not post_blocks:
            # Find all anchor tags linking to internal posts (not ads/external)
            link_pattern = re.compile(
                r'<a\s+href="(https?://www\.sonovinhasbr\.com/[a-z0-9\-]+/)"[^>]*>\s*'
                r'(?:<[^>]+>)*\s*<h2[^>]*>(.*?)</h2>',
                re.S | re.I
            )
            matches = link_pattern.findall(html)
            seen_urls = set()
            for href, title_raw in matches:
                if href in seen_urls:
                    continue
                # Skip if this looks like an ad (check for nofollow in parent context)
                idx = html.find(href)
                context_before = html[max(0, idx - 200):idx] if idx >= 0 else ''
                if 'nofollow' in context_before.lower():
                    continue
                seen_urls.add(href)
                title = re.sub(r'<[^>]+>', '', title_raw).strip()

                # Look for thumbnail near this link
                thumbnail = ''
                context_after = html[idx:idx + 2000] if idx >= 0 else ''
                img_match = re.search(r'<img[^>]+src="([^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"', context_after)
                if img_match:
                    thumbnail = img_match.group(1)

                post_id = extract_post_id(href)
                videos.append({
                    'external_id': post_id or '',
                    'source_url': href,
                    'canonical_url': href.rstrip('/'),
                    'title': title,
                    'description': '',
                    'tags': '',
                    'duration': 0,
                    'duration_formatted': '',
                    'thumbnail_url': thumbnail,
                })
        else:
            for block in post_blocks:
                href_match = re.search(r'<a[^>]+href="(https?://www\.sonovinhasbr\.com/[^"]+/)"[^>]*>', block)
                if not href_match:
                    continue
                href = href_match.group(1)

                title_match = re.search(r'<h2[^>]*>(.*?)</h2>', block, re.S)
                title = re.sub(r'<[^>]+>', '', title_match.group(1)).strip() if title_match else ''

                img_match = re.search(r'<img[^>]+src="([^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"', block)
                thumbnail = img_match.group(1) if img_match else ''

                post_id = extract_post_id(href)

                videos.append({
                    'external_id': post_id or '',
                    'source_url': href,
                    'canonical_url': href.rstrip('/'),
                    'title': title,
                    'description': '',
                    'tags': '',
                    'duration': 0,
                    'duration_formatted': '',
                    'thumbnail_url': thumbnail,
                })

        has_more = bool(re.search(r'rel="next"', html))

    return {'videos': videos, 'has_more': has_more}


def scrape_video_page(url):
    """Scrape a single video page for full metadata."""
    try:
        r = requests.get(url, headers=HEADERS, timeout=30)
    except Exception as e:
        return {'error': str(e)}

    if r.status_code != 200:
        return {'error': f'HTTP {r.status_code}'}

    html = r.text
    result = {}

    # VideoObject JSON-LD
    vo = parse_video_object(html)
    if vo:
        result['title'] = vo.get('name', '')
        result['description'] = vo.get('description', '')
        result['thumbnail_url'] = vo.get('thumbnailUrl', '')
        result['duration'] = parse_duration_iso(vo.get('duration', ''))
        result['duration_formatted'] = format_duration(result['duration'])
        result['embed_url'] = vo.get('embedUrl', '')
        result['content_url'] = vo.get('contentUrl', '')
        post_id = extract_post_id(vo.get('contentUrl', '') or vo.get('embedUrl', ''))
        if post_id:
            result['external_id'] = post_id

    # Fallback: meta tags
    if 'title' not in result:
        m = re.search(r'<title>([^<]+)</title>', html)
        if m:
            result['title'] = m.group(1).replace(' - Só Novinhas BR', '').strip()

    if 'description' not in result:
        m = re.search(r'<meta[^>]+name="description"[^>]+content="([^"]+)"', html)
        if m:
            result['description'] = m.group(1).strip()

    if 'thumbnail_url' not in result:
        m = re.search(r'<meta[^>]+property="og:image"[^>]+content="([^"]+)"', html)
        if m:
            result['thumbnail_url'] = m.group(1).strip()

    # Tags
    tags = []
    if BeautifulSoup:
        soup = BeautifulSoup(html, 'html.parser')
        tag_links = soup.select('.post-tags a[rel="tag"]')
        for t in tag_links:
            tags.append(t.get_text(strip=True))
    else:
        tag_matches = re.findall(r'<a[^>]+rel="tag"[^>]*>([^<]+)</a>', html)
        tags = [t.strip() for t in tag_matches]

    result['tags'] = ', '.join(tags)

    return result


def scrape_profile(url, page=1, max_pages=1):
    """Main entry: discover videos from listing/category URLs."""
    if page == 1:
        page_url = url
    else:
        # WordPress pagination: /page/N/
        if url.rstrip('/').endswith('/'):
            page_url = url.rstrip('/') + '/page/' + str(page) + '/'
        elif re.search(r'/page/\d+/?$', url):
            page_url = re.sub(r'/page/\d+/?$', '/page/' + str(page) + '/', url)
        else:
            page_url = url.rstrip('/') + '/page/' + str(page) + '/'

    result = scrape_listing_page(page_url)

    if 'error' in result:
        return {'error': result['error'], 'videos': [], 'total': 0, 'has_more': False}

    videos = result.get('videos', [])
    has_more = result.get('has_more', False)

    # Enrich: fetch individual video pages for full metadata (title, tags, duration)
    for i, video in enumerate(videos):
        if video.get('title') and video.get('tags'):
            continue  # already has metadata
        try:
            detail = scrape_video_page(video['source_url'])
            if 'error' not in detail:
                if not video.get('title') and detail.get('title'):
                    video['title'] = detail['title']
                if not video.get('description') and detail.get('description'):
                    video['description'] = detail['description']
                if not video.get('tags') and detail.get('tags'):
                    video['tags'] = detail['tags']
                if not video.get('duration') and detail.get('duration'):
                    video['duration'] = detail['duration']
                    video['duration_formatted'] = detail.get('duration_formatted', '')
                if not video.get('thumbnail_url') and detail.get('thumbnail_url'):
                    video['thumbnail_url'] = detail['thumbnail_url']
                if not video.get('external_id') and detail.get('external_id'):
                    video['external_id'] = detail['external_id']
            time.sleep(0.5)
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
        print(json.dumps({"error": "Usage: sonovinhasbr_scrape.py <url> [page] [max_pages]"}))
        sys.exit(1)

    url = sys.argv[1]
    page = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 1

    result = scrape_profile(url, page, max_pages)
    print(json.dumps(result))
