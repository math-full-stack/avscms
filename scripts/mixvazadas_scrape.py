#!/usr/bin/env python3
"""
mixvazadas_scrape.py - Discovers videos from mixvazadas.com listings.

Usage:
    python3 mixvazadas_scrape.py <url> [page] [max_pages]

Output: JSON with video list.

mixvazadas.com é um site Astro-based (SSG) com HTML server-rendered, sem Cloudflare.
A grade de vídeos usa cards <article class="video-card"> com link /video/{slug},
título em <h3 class="video-title"> e thumbnail em <img src="/_astro/...webp">.
A página do vídeo expõe meta tags (og:title, og:description, og:image),
<source src> para MP4 direto e tags em links <a href="/tag/{tag}">.

Estrutura observada nas páginas (2026-09):
  * Cards: <article class="video-card"> <a href="/video/{slug}"> <img src="/_astro/...webp">
    <h3 class="video-title">{title}</h3> ... <a href="/tag/{tag}" class="tag">
  * Paginação: /page/{N} com links <a href="/page/{N}" class="page-btn">
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
    'mixvazadas', 'porno', 'xxx', 'mais videos', 'videos porno',
    'sexo', 'gratis', 'novinhas', 'mulheres nuas',
])

BASE_URL = 'https://mixvazadas.com'


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


def parse_listing(html):
    """Extrai os vídeos da grade de uma página de listagem."""
    videos = []
    seen = set()

    # Divide por <article class="video-card">
    chunks = re.split(r'<article\s+class="video-card"', html)

    for chunk in chunks[1:]:  # pula o primeiro (antes do primeiro card)
        # URL e slug
        m = re.search(r'href="(/video/([^"]+))"', chunk)
        if not m:
            continue
        relative_url = m.group(1)
        slug = m.group(2).rstrip('/')
        source_url = resolve_url(relative_url)

        if slug in seen:
            continue
        seen.add(slug)

        # Título: <h3 class="video-title">
        title = ''
        tm = re.search(r'<h3\s+class="video-title"[^>]*>([^<]+)</h3>', chunk)
        if tm:
            title = tm.group(1).strip()

        # Thumbnail: primeiro <img> dentro de .video-thumbnail
        thumbnail = ''
        im = re.search(r'<img[^>]+src="([^"]+\.(?:webp|jpg|jpeg|png)[^"]*)"', chunk)
        if im:
            thumbnail = resolve_url(im.group(1).strip())

        # Tags do card
        tags = []
        for tag_m in re.finditer(r'<a[^>]+href="/tag/[^"]*"[^>]*class="tag[^"]*"[^>]*>\s*([^<]+?)\s*</a>', chunk):
            tag_text = tag_m.group(1).strip()
            if tag_text:
                tags.append(tag_text)

        videos.append({
            'external_id': slug,
            'source_url': source_url,
            'canonical_url': source_url,
            'title': title,
            'description': '',
            'tags': clean_tags(tags),
            'duration': 0,
            'duration_formatted': '',
            'thumbnail_url': thumbnail,
        })

    # Filter out items without a real title or source URL.
    videos = [v for v in videos if v.get('title') and v.get('source_url')]

    return videos



def _parse_duration_from_page(html):
    """Extrai duração em segundos da página do vídeo (padrão dos demais).

    Tenta, por ordem:
      1. og:video:duration (segundos inteiros)
      2. JSON-LD VideoObject (ISO 8601 PT...S)
      3. Duração exibida na tela, se houver
    """
    duration = 0

    m = re.search(r'property="og:video:duration"\s+content="(\d+)"', html, re.I)
    if m:
        try:
            duration = int(m.group(1))
        except ValueError:
            duration = 0

    if duration <= 0:
        m = re.search(
            r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>',
            html, re.S | re.I)
        if m:
            try:
                data = json.loads(m.group(1).strip())
            except Exception:
                data = None
            if isinstance(data, dict) and data.get('@type') == 'VideoObject':
                iso = data.get('duration') or ''
                if iso:
                    duration = _iso_seconds(iso)

    if duration <= 0:
        m = re.search(r'class="duration"[^>]*>\s*([^<]+?)\s*</div>', html, re.I)
        if m:
            duration = _parse_duration_mmss(m.group(1))

    return duration


def _parse_duration_mmss(text):
    """Converte '03:20' (mm:ss) ou '1:02:30' (h:mm:ss) para segundos."""
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


def _iso_seconds(iso):
    """Converte ISO 8601 (PT02M17S) para segundos."""
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


def enrich_video(video):
    """Busca a página do vídeo e completa descrição/tags/duração via meta tags
    e JSON-LD, no mesmo padrão dos demais providers de mass grabber."""
    html = fetch(video['source_url'])
    if not html:
        return

    # Descrição via og:description
    if not video['description']:
        m = re.search(r'<meta[^>]+property="og:description"[^>]+content="([^"]+)"', html, re.I)
        if not m:
            m = re.search(r'<meta[^>]+name="description"[^>]+content="([^"]+)"', html, re.I)
        if m:
            video['description'] = m.group(1).strip()

    # Título via og:title (se vazio)
    if not video['title']:
        m = re.search(r'<meta[^>]+property="og:title"[^>]+content="([^"]+)"', html, re.I)
        if m:
            raw = m.group(1).strip()
            video['title'] = re.sub(r'^Mixvazadas\s*-\s*', '', raw, flags=re.I)

    # Duração: padrão usado pelo Pornolandia/Sonovinhas/Xfree — só preenche se
    # ainda for 0 (abv listing) e a página do vídeo trouxer uma duração real.
    if video.get('duration', 0) <= 0:
        duration = _parse_duration_from_page(html)
        if duration > 0:
            video['duration'] = duration
            video['duration_formatted'] = fmt_duration(duration)

    # Thumbnail via og:image (fallback se listing não trouxe)
    if not video.get('thumbnail_url'):
        m = re.search(r'<meta[^>]+property="og:image"[^>]+content="([^"]+)"', html, re.I)
        if m:
            video['thumbnail_url'] = resolve_url(m.group(1).strip())

    # Tags via <a href="/tag/..."> (se vazias)
    if not video['tags']:
        tags = []
        for tm in re.finditer(r'<a[^>]+href="/tag/[^"]*"[^>]*class="tag[^"]*"[^>]*>\s*([^<]+?)\s*</a>', html):
            tag_text = tm.group(1).strip()
            if tag_text:
                tags.append(tag_text)
        if tags:
            video['tags'] = clean_tags(tags)


def build_page_url(url, page):
    """Monta a URL da página N a partir da URL base de listagem."""
    if page <= 1:
        return url

    # /page/{N} → troca ou adiciona
    if re.search(r'/page/\d+', url):
        return re.sub(r'/page/\d+', '/page/' + str(page), url)

    return url.rstrip('/') + '/page/' + str(page)


def has_more_pages(html):
    """True se existir link de paginação 'Próximo' ou número maior que a página atual."""
    # Verifica se existe <a href="/page/{N+1}"
    if re.search(r'class="page-btn\s*"[^>]*>[^<]*Próximo', html, re.I):
        return True
    if re.search(r'class="page-btn[^"]*"\s+aria-label="Próxima página"', html, re.I):
        return True
    return False


def scrape_listing(url, page=1, max_pages=1):
    """Descobre vídeos de uma listagem, com enriquecimento paralelo dos itens.

    Segue o mesmo padrão dos demais providers: depois de extrair as cards,
    busca as páginas individuais para preencher título/descrição/tags/duração
    e ignora itens que não tiverem uma duração real."""
    page_url = build_page_url(url, page)
    html = fetch(page_url)
    if html is None:
        return {
            "error": "Failed to fetch page: %s" % page_url,
            "videos": [],
            "total": 0,
            "has_more": False,
        }

    videos = parse_listing(html)
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

    # mixvazadas não expõe duração em nenhum lugar (sem og:video:duration,
    # sem JSON-LD, sem div.duration). Mantemos itens com título real.
    videos = [v for v in videos if v.get('title')]
    total = len(videos)

    return {
        'videos': videos,
        'total': total,
        'has_more': has_more,
    }

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: mixvazadas_scrape.py <url> [page] [max_pages]"}))
        sys.exit(1)

    url = sys.argv[1]
    page = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 1

    result = scrape_listing(url, page, max_pages)
    print(json.dumps(result))
