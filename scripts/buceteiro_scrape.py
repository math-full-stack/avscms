#!/usr/bin/env python3
"""
buceteiro_scrape.py - Discovers videos from buceteiro.com listings.

Usage:
    python3 bucereteiro_scrape.py <url> [page] [max_pages]

Output: JSON with video list.

buceteiro.com é um WordPress com Yoast SEO. O scraper parseia o sitemap.xml
(post-sitemap{N}.xml) ou paginação /page/{N}/ para descobrir URLs de vídeos,
e enriquece cada item com o JSON-LD VideoObject da página do vídeo.

Estrutura observada nas páginas (2026-09):
  * Sitemap: /sitemap.xml → post-sitemap.xml ... post-sitemap16.xml
  * Paginação: /page/{N}/ com links <a href="/page/{N+1}/">
  * Cards: <article> com <a href="/{slug}/">, <img> thumbnail, <h2> título
  * Página de vídeo: JSON-LD VideoObject com name/description/duration (PT..S)/thumbnailUrl
  * Tags: <section class="trends"> com links /tag-porno/{slug}/
  * Player: iframe playernc.com com UUID
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
    'porno', 'xxx', 'mais videos', 'videos porno', 'sexo', 'gratis',
    'novinhas', 'mulheres nuas', 'buceteiro',
])

BASE_URL = 'https://buceteiro.com'


def fetch(url):
    """Busca uma página e retorna o HTML, ou None em erro/block."""
    try:
        r = requests.get(url, headers=HEADERS, impersonate='chrome', timeout=30)
    except Exception:
        return None
    if r.status_code != 200:
        return None
    return r.text


def resolve_url(path):
    """Resolve caminho relativo para URL absoluta."""
    if path.startswith('http'):
        return path
    if path.startswith('//'):
        return 'https:' + path
    if path.startswith('/'):
        return BASE_URL + path
    return BASE_URL + '/' + path


def fmt_duration(seconds):
    """Formata segundos como HH:MM:SS ou MM:SS."""
    seconds = max(0, int(seconds))
    if seconds <= 0:
        return ''
    h = seconds // 3600
    m = (seconds % 3600) // 60
    s = seconds % 60
    if h > 0:
        return '%d:%02d:%02d' % (h, m, s)
    return '%d:%02d' % (m, s)


def parse_iso_seconds(iso):
    """Converte duração ISO 8601 (PT02M17S) para segundos."""
    if not iso:
        return 0
    m = re.match(
        r'^P(?:(?P<d>\d+)D)?(?:T(?:(?P<h>\d+)H)?(?:(?P<mi>\d+)M)?(?:(?P<s>\d+)S)?)?$',
        iso.strip())
    if not m:
        return 0
    d = int(m.group('d') or 0)
    h = int(m.group('h') or 0)
    mi = int(m.group('mi') or 0)
    s = int(m.group('s') or 0)
    return d * 86400 + h * 3600 + mi * 60 + s


def clean_tags(tag_list):
    """Filtra tags genéricas e devolve string separada por vírgula."""
    out = []
    for t in tag_list:
        key = re.sub(r'\s+', ' ', t).strip().lower()
        if not key or key in TAG_STOPLIST:
            continue
        if t not in out:
            out.append(t)
    return ', '.join(out[:15])


def extract_slug(url):
    """Extrai o slug do vídeo de um URL buceteiro.com."""
    m = re.search(r'buceteiro\.com/([^\/\?]+)/?$', url)
    if m:
        slug = m.group(1)
        # Ignorar paths de sistema
        if slug in ('page', 'feed', 'sitemap.xml', 'wp-admin', 'wp-content',
                     'wp-includes', 'categorias', 'tag-porno', 'atriz-porno'):
            return None
        return slug
    return None


def parse_sitemap_index(html):
    """Extrai URLs de sub-sitemaps do sitemap index."""
    urls = []
    for m in re.finditer(r'<loc>([^<]+)</loc>', html):
        loc = m.group(1).strip()
        if 'post-sitemap' in loc:
            urls.append(loc)
    return urls


def parse_sitemap_urls(xml):
    """Extrai URLs de vídeos de um sub-sitemap XML."""
    urls = []
    for m in re.finditer(r'<loc>([^<]+)</loc>', xml):
        loc = m.group(1).strip()
        if 'buceteiro.com/' in loc:
            urls.append(loc)
    return urls


def discover_from_sitemap(max_pages=1):
    """Descobre vídeos a partir do sitemap.xml."""
    sitemap_index = fetch(BASE_URL + '/sitemap.xml')
    if not sitemap_index:
        return {'error': 'Failed to fetch sitemap.xml', 'videos': [], 'total': 0, 'has_more': False}

    sub_sitemaps = parse_sitemap_index(sitemap_index)
    if not sub_sitemaps:
        return {'error': 'No post sitemaps found', 'videos': [], 'total': 0, 'has_more': False}

    # Processar até max_pages sub-sitemaps
    all_urls = []
    for sm_url in sub_sitemaps[:max_pages]:
        xml = fetch(sm_url)
        if xml:
            all_urls.extend(parse_sitemap_urls(xml))
        time.sleep(0.5)

    # Deduplicar
    seen = set()
    video_urls = []
    for url in all_urls:
        slug = extract_slug(url)
        if slug and slug not in seen:
            seen.add(slug)
            video_urls.append(url)

    return {
        'urls': video_urls,
        'total': len(video_urls),
        'has_more': len(sub_sitemaps) > max_pages,
        'sitemap_count': len(sub_sitemaps),
    }


def parse_listing_page(html):
    """Extrai vídeos da grade de uma página de listagem WordPress."""
    videos = []
    seen = set()

    # WordPress通常: <article> com link e thumbnail
    # Padrão 1: <a href="/{slug}/"> com <img> e título
    for m in re.finditer(
        r'<article[^>]*>.*?<a[^>]+href="(?:https?://buceteiro\.com)?/([^"]+?)/?"[^>]*>.*?</article>',
        html, re.S | re.I):
        slug = m.group(1).strip()
        if slug in seen or not slug or slug.startswith('page'):
            continue
        seen.add(slug)
        source_url = BASE_URL + '/' + slug + '/'

        # Extrair do chunk do article
        chunk = m.group(0)
        title = ''
        tm = re.search(r'<h[23][^>]*>(.*?)</h[23]>', chunk, re.S)
        if tm:
            title = re.sub(r'<[^>]+>', '', tm.group(1)).strip()

        thumbnail = ''
        im = re.search(r'<img[^>]+src="([^"]+\.(?:webp|jpg|jpeg|png)[^"]*)"', chunk)
        if im:
            thumbnail = resolve_url(im.group(1).strip())

        videos.append({
            'external_id': slug,
            'source_url': source_url,
            'canonical_url': source_url,
            'title': title,
            'description': '',
            'tags': '',
            'duration': 0,
            'duration_formatted': '',
            'thumbnail_url': thumbnail,
        })

    # Padrão 2: links diretos no HTML (fallback para temas simples)
    if not videos:
        for m in re.finditer(r'href="(https?://buceteiro\.com/([^"]+?)/?)"', html):
            slug = m.group(2).strip()
            if slug in seen or not slug or slug.startswith('page'):
                continue
            # Ignorar paths de sistema
            if slug in ('feed', 'sitemap.xml', 'wp-admin', 'wp-content',
                         'wp-includes', 'categorias', 'tag-porno', 'atriz-porno'):
                continue
            seen.add(slug)
            source_url = m.group(1).rstrip('/') + '/'
            videos.append({
                'external_id': slug,
                'source_url': source_url,
                'canonical_url': source_url,
                'title': '',
                'description': '',
                'tags': '',
                'duration': 0,
                'duration_formatted': '',
                'thumbnail_url': '',
            })

    return videos


def enrich_video(video):
    """Busca a página do vídeo e completa metadata via JSON-LD."""
    html = fetch(video['source_url'])
    if not html:
        return

    # JSON-LD VideoObject
    for m in re.finditer(
        r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>',
        html, re.S | re.I):
        try:
            data = json.loads(m.group(1).strip())
        except Exception:
            continue

        if not isinstance(data, dict):
            continue

        if data.get('@type') == 'VideoObject':
            if not video['title']:
                video['title'] = str(data.get('name') or '').strip()
            if not video['description']:
                video['description'] = str(data.get('description') or '').strip()
            iso = data.get('duration') or ''
            if iso and video['duration'] <= 0:
                video['duration'] = parse_iso_seconds(iso)
                video['duration_formatted'] = fmt_duration(video['duration'])
            thumbs = data.get('thumbnailUrl', '')
            if isinstance(thumbs, list):
                thumbs = thumbs[0] if thumbs else ''
            if not video['thumbnail_url'] and thumbs:
                video['thumbnail_url'] = thumbs

        # Article/WebPage: keywords (tags) e articleSection (categoria)
        if data.get('@type') in ('Article', 'WebPage'):
            if not video['tags'] and isinstance(data.get('keywords'), list):
                video['tags'] = clean_tags(data['keywords'])

    # Tags: <section class="trends"> com links /tag-porno/
    if not video['tags']:
        tags = []
        for tm in re.finditer(r'<a[^>]+href="[^"]*\/tag-porno\/[^"]*"[^>]*>([^<]+)<\/a>', html, re.I):
            tag_text = tm.group(1).strip()
            if tag_text:
                tags.append(tag_text)
        if tags:
            video['tags'] = clean_tags(tags)

    # Thumbnail fallback via og:image
    if not video['thumbnail_url']:
        im = re.search(r'<meta[^>]+property="og:image"[^>]+content="([^"]+)"', html, re.I)
        if im:
            video['thumbnail_url'] = im.group(1).strip()


def build_page_url(url, page):
    """Monta a URL da página N a partir da URL base de listagem."""
    if page <= 1:
        return url

    # /page/{N} → troca ou adiciona
    if re.search(r'/page/\d+', url):
        return re.sub(r'/page/\d+', '/page/' + str(page), url)

    return url.rstrip('/') + '/page/' + str(page) + '/'


def has_more_pages(html):
    """True se existir link de paginação 'Próximo' ou número maior que a página atual."""
    if re.search(r'class="next\s+page-numbers"', html, re.I):
        return True
    if re.search(r'class="page-numbers\s+next"', html, re.I):
        return True
    if re.search(r'rel="next"', html, re.I):
        return True
    return False


def scrape_listing(url, page=1, max_pages=1):
    """Descobre vídeos de uma listagem, com enriquecimento paralelo dos itens.

    Se a URL for o sitemap.xml, usa descoberta por sitemap (mais eficiente).
    Caso contrário, usa paginação /page/{N}/.
    """
    # Modo sitemap
    if re.search(r'sitemap\.xml', url, re.I):
        sitemap_result = discover_from_sitemap(max_pages)
        if 'error' in sitemap_result:
            return sitemap_result

        video_urls = sitemap_result['urls']

        # Criar entradas básicas
        videos = []
        for v_url in video_urls:
            slug = extract_slug(v_url)
            if slug:
                videos.append({
                    'external_id': slug,
                    'source_url': v_url,
                    'canonical_url': v_url,
                    'title': '',
                    'description': '',
                    'tags': '',
                    'duration': 0,
                    'duration_formatted': '',
                    'thumbnail_url': '',
                })

        # Enriquecer em paralelo (máx. 5)
        if videos:
            with ThreadPoolExecutor(max_workers=5) as pool:
                futures = [pool.submit(enrich_video, v) for v in videos]
                for f in futures:
                    try:
                        f.result()
                    except Exception:
                        pass

        # Filtrar só vídeos com título real
        videos = [v for v in videos if v.get('title')]

        return {
            'videos': videos,
            'total': len(videos),
            'has_more': sitemap_result['has_more'],
        }

    # Modo paginação
    page_url = build_page_url(url, page)
    html = fetch(page_url)
    if html is None:
        return {
            "error": "Failed to fetch page: %s" % page_url,
            "videos": [],
            "total": 0,
            "has_more": False,
        }

    videos = parse_listing_page(html)
    has_more = has_more_pages(html)

    # Enriquecimento: busca paralela (máx. 5) das páginas de vídeo.
    if videos:
        with ThreadPoolExecutor(max_workers=5) as pool:
            futures = [pool.submit(enrich_video, v) for v in videos]
            for f in futures:
                try:
                    f.result()
                except Exception:
                    pass

    # Filtrar só vídeos com título real (enriquecimento deu resultado)
    videos = [v for v in videos if v.get('title')]
    total = len(videos)

    return {
        'videos': videos,
        'total': total,
        'has_more': has_more,
    }


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: bucereteiro_scrape.py <url> [page] [max_pages]"}))
        sys.exit(1)

    url = sys.argv[1]
    page = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 1

    result = scrape_listing(url, page, max_pages)
    print(json.dumps(result))
