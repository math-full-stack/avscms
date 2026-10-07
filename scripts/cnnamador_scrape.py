#!/usr/bin/env python3
"""
cnnamador_scrape.py - Discovers videos from cnnamador.com listings.

Usage:
    python3 cnnamador_scrape.py <url> [page] [max_pages]

Output: JSON with video list.

cnnamador.com is a KVS (Kernel Video Sharing) site with server-rendered
listings. Video cards link to https://cnnamador.com/{slug}/ and carry the
video id in data-rt, the screenshot in data-src and the duration in a flag.
Video pages expose JSON-LD VideoObject, og: meta tags and a `flashvars`
object with the stream URLs (get_file + v-acctoken), duration, tags and
categories.

Structure observed (2026-10):
  * Cards: <div class="cards__item cards__item--videos ...">
           <a href="{slug}/" class="card" title="..." data-rt="N:hash:T:{id}:{p}:">
           <span class="flag flag--light">9:26</span>
           <img class="card__img" src="data:..." data-src="{screenshot}">
  * Pagination: {listing}/{N}/ (KVS standard) with
                <li class="pagination__item--next"><a href="...">
  * Video page: JSON-LD VideoObject + `var flashvars = { video_id, video_title,
    video_tags, video_categories, video_url (original), video_alt_url (480p),
    video_alt_url2 (240p), preview_url, duration }`
"""
import sys
import json
import re
import html as htmlmod
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

SITE = 'https://cnnamador.com'
ENRICH_WORKERS = 5


def fetch(url):
    try:
        r = requests.get(url, headers=HEADERS, impersonate='chrome', timeout=30,
                         allow_redirects=True)
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
        return int(m.group(1)) * 3600 + int(m.group(2)) * 60 + int(m.group(3))
    return int(m.group(1)) * 60 + int(m.group(2))


def parse_iso_seconds(iso):
    if not iso:
        return 0
    m = re.match(r'^P(?:(?P<d>\d+)D)?(?:T(?:(?P<h>\d+)H)?(?:(?P<mi>\d+)M)?(?:(?P<s>\d+)S)?)?$',
                 iso.strip())
    if not m:
        return 0
    return (int(m.group('d') or 0) * 86400 + int(m.group('h') or 0) * 3600
            + int(m.group('mi') or 0) * 60 + int(m.group('s') or 0))


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


TAG_STOPLIST = set([
    'gratis', 'hd', 'sexo', 'gratis', 'videos porno', 'mais videos', 'porno', 'xxx', 'putaria', 'buceteiro', 'buceteiro.com', 'cnnamador', 'cnnamador.com', 'mixvazadas', 'mixvazadas.com', 'naoconto', 'naoconto.com', 'pornobrasil', 'pornobrasil.com', 'pornolandia', 'pornolandia.xxx', 'pornomineiro', 'pornomineiro.com', 'sonovinhasbr', 'sonovinhasbr.com', 'xfree', 'xfree.com', 'onlyfans', 'pornhub', 'xvideos', 'xnxx', 'redtube', 'youporn', 'nudo', 'telegram', 'whatsapp', 'linktree', 'tiktok', 'youtube', 'instagram',
])


def strip_tags(text):
    return htmlmod.unescape(re.sub(r'<[^>]+>', ' ', text or '')).strip()


def slug_from_url(url):
    path = (url or '').split('?')[0].split('#')[0].rstrip('/')
    if not path:
        return ''
    return path.rsplit('/', 1)[-1]


# Frases geradas pelo KVS (não são texto do admin): "Vídeo de ... com duração
# de ...", "Arquivo em HD com 43,49 MB.", "Duração: 12min5s. Tamanho: ...".
# A frase inicial pode começar com "Vídeo" (a do fim não: descrições reais
# terminam com coisas como "Vídeo amador da morena ...").
LEAD_BOILERPLATE = re.compile(
    r'^(?:v[ií]deo\b|arquivo\b|dura[cç][aã]o\b|tamanho:|qualidade:|s(?:ã|a)o \d+)',
    re.I,
)
TRAIL_BOILERPLATE = re.compile(
    r'^(?:arquivo\b|dura[cç][aã]o\b|tamanho:|qualidade:|s(?:ã|a)o \d+)',
    re.I,
)


def clean_description(desc):
    """Remove as frases de boilerplate que o KVS concatena à descrição real.
    Só corta nas pontas (início/fim); se não sobrar nada, o vídeo não tem
    descrição no site e devolve vazio."""
    d = ' '.join((desc or '').split())
    if not d:
        return ''
    parts = re.split(r'(?<=[.!?])\s+', d)
    while parts and LEAD_BOILERPLATE.match(parts[0]):
        parts.pop(0)
    while parts and TRAIL_BOILERPLATE.match(parts[-1]):
        parts.pop()
    return ' '.join(parts).strip()


def build_page_url(url, page):
    """Base {listing}/ + página N (padrão KVS). Remove um número de página já
    presente na URL base para não acumular (/latest-updates/50/ + 2 => /51/)."""
    clean = (url or '').split('?')[0].split('#')[0]
    m = re.match(r'^(https?://[^/]+)(/.*)?$', clean)
    if not m:
        return url
    host, path = m.group(1), (m.group(2) or '/')
    path = re.sub(r'/\d+/?$', '/', path)
    base = path.rstrip('/')
    if base == '':
        # A raiz não se pagina (o "Próximo" dela leva para /latest-updates/N/):
        # da 2ª página em diante a descoberta continua nesse listing.
        if page <= 1:
            return host + '/'
        return '%s/latest-updates/%d/' % (host, page)
    if page <= 1:
        return host + base + '/'
    return '%s%s/%d/' % (host, base, page)


def has_more_pages(html):
    m = re.search(r'<li[^>]*class="[^"]*pagination__item--next[^"]*"[^>]*>(.*?)</li>',
                  html, re.S)
    if not m:
        return False
    return bool(re.search(r'<a[^>]+href="([^"]+)"', m.group(1)))


def parse_listing(html):
    videos = []
    seen = set()

    # Cada bloco termina no próximo <div class="cards__item"; anúncios são
    # descartados por não terem data-rt (href="#").
    for block in re.split(r'(?=<div class="cards__item)', html):
        if 'data-rt=' not in block:
            continue
        a = re.search(r'<a\s+href="([^"]+)"\s+class="card"', block, re.S)
        if not a:
            continue
        source_url = a.group(1).strip()
        if not source_url.startswith('http'):
            source_url = SITE + source_url

        external_id = slug_from_url(source_url)
        if not external_id or external_id in seen:
            continue
        seen.add(external_id)

        title = ''
        tm = re.search(r'<a\s+href="[^"]+"\s+class="card"\s+title="([^"]*)"', block, re.S)
        if tm:
            title = htmlmod.unescape(tm.group(1)).strip()
        if not title:
            hm = re.search(r'<h2 class="card__title[^"]*"[^>]*>(.*?)</h2>', block, re.S)
            if hm:
                title = strip_tags(hm.group(1))

        thumbnail = ''
        im = re.search(r'data-src="([^"]+)"', block)
        if im:
            thumbnail = im.group(1)
        else:
            im = re.search(r'<img[^>]+src="([^"]+)"', block)
            if im and not im.group(1).startswith('data:'):
                thumbnail = im.group(1)

        duration = 0
        dm = re.search(r'class="flag flag--light"[^>]*>\s*([0-9:]+)\s*</span>', block, re.S)
        if dm:
            duration = parse_duration_mmss(dm.group(1))

        videos.append({
            'external_id': external_id,
            'source_url': source_url,
            'canonical_url': source_url.rstrip('/'),
            'title': title,
            'description': '',
            'tags': '',
            'category': '',
            'duration': duration,
            'duration_formatted': fmt_duration(duration),
            'thumbnail_url': thumbnail,
        })

    return videos


def flashvar(html, key):
    m = re.search(r'\b%s\s*:\s*\'((?:[^\'\\]|\\.)*)\'' % re.escape(key), html)
    if not m:
        return ''
    return m.group(1).replace('\\/', '/').replace("\\'", "'").strip()


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
        for item in data['@graph']:
            if isinstance(item, dict) and item.get('@type') == 'VideoObject':
                return item
    return None


def join_tags(*groups, stoplist=None):
    out = []
    seen = set()
    for group in groups:
        for raw in (group or '').split(','):
            tag = raw.strip()
            # Tags de 1 letra (ex.: "o") são ruído do admin no KVS.
            if len(tag) < 2 or tag.lower() in seen:
                continue
            if stoplist and tag.lower() in stoplist:
                continue
            seen.add(tag.lower())
            out.append(tag)
    return ', '.join(out[:15])


def enrich_video(video):
    page = fetch(video['source_url'])
    if not page:
        return

    vo = parse_video_object(page)
    if vo:
        if not video['title']:
            video['title'] = str(vo.get('name') or '').strip()
        if not video['description']:
            video['description'] = clean_description(str(vo.get('description') or ''))
        if video['duration'] <= 0:
            video['duration'] = parse_iso_seconds(str(vo.get('duration') or ''))
            video['duration_formatted'] = fmt_duration(video['duration'])
        thumb = vo.get('thumbnailUrl')
        if isinstance(thumb, list):
            thumb = thumb[0] if thumb else ''
        if thumb:
            video['thumbnail_url'] = str(thumb).strip()

    if not video['description']:
        og = re.search(r'<meta[^>]+property="og:description"[^>]+content="([^"]*)"',
                       page, re.I)
        if og:
            video['description'] = clean_description(og.group(1))

    if video['duration'] <= 0:
        d = flashvar(page, 'duration')
        if d.isdigit():
            video['duration'] = int(d)
            video['duration_formatted'] = fmt_duration(video['duration'])

    # Título real do KVS (video_title) quando o listing veio sem título.
    if not video['title']:
        video['title'] = flashvar(page, 'video_title')

    # Tags do flashvars (spread do admin no KVS). As categorias do KVS
    # (video_categories) viram `category` — hoje entravam junto nas tags.
    tags = join_tags(flashvar(page, 'video_tags'), stoplist=TAG_STOPLIST)
    if tags:
        video['tags'] = tags

    if not video.get('category'):
        category = join_tags(flashvar(page, 'video_categories'))
        if category:
            video['category'] = category

    # Thumbnail de maior resolução (preview.jpg) quando o listing traz 276x155.
    preview = flashvar(page, 'preview_url')
    if preview:
        video['thumbnail_url'] = preview


def scrape_listing(url, page=1, max_pages=1):
    page_url = build_page_url(url, page)
    page_html = fetch(page_url)
    if page_html is None:
        return {"error": "Failed to fetch page: %s" % page_url,
                "videos": [], "total": 0, "has_more": False}

    videos = parse_listing(page_html)
    has_more = has_more_pages(page_html)

    if videos:
        with ThreadPoolExecutor(max_workers=ENRICH_WORKERS) as pool:
            futures = [pool.submit(enrich_video, v) for v in videos]
            for f in futures:
                try:
                    f.result()
                except Exception:
                    pass

    return {
        'videos': videos,
        'total': len(videos),
        'has_more': has_more,
    }


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: cnnamador_scrape.py <url> [page] [max_pages]"}))
        sys.exit(1)

    arg_url = sys.argv[1]
    arg_page = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    arg_max = int(sys.argv[3]) if len(sys.argv) > 3 else 1

    result = scrape_listing(arg_url, arg_page, arg_max)
    print(json.dumps(result))
