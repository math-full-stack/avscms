#!/usr/bin/env python3
"""
pornolandia_scrape.py - Discovers videos from pornolandia.xxx listings.

Usage:
    python3 pornolandia_scrape.py <url> [page] [max_pages]

Output: JSON with video list.

pornolandia.xxx é um tube em HTML simples (sem SPA). A grade de vídeos é
server-rendered: cada card traz o link /video/{id}/{slug}, o título, a
miniatura e a duração. A página do vídeo expõe um JSON-LD VideoObject com
descrição, tags e duração em ISO 8601 — usado para enriquecer cada item.

Estrutura observada nas páginas (2026-09):
  * Cards: <div class="ratio-box thumb-lazy"> <a href=".../video/{id}/{slug}"
    title="{title}" class="floatbanner"> <img src=".../tmb/{id}/1.jpg"> ...
    <div class="duration">04:15</div>
  * Página de vídeo: JSON-LD VideoObject com name/description/duration
    (PT..S)/thumbnailUrl e tags em links <a class="tag" href="/videos/{slug}/">.
  * Paginação: /videos?page=N para a listagem geral e /videos/{categoria}/N
    para categorias; o head expõe rel="next" quando há mais páginas.
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

# Tags que nada dizem sobre o conteúdo (canal/promo) — nunca viram keywords.
TAG_STOPLIST = set([
    'porno', 'xxx', 'mais videos porno', 'videos porno', 'sexo', 'gratis',
    'novinhas', 'mulheres nuas',
])

# Prefixo do card na listagem (para split por bloco e manter id/título juntos).
CARD_START = 'class="ratio-box thumb-lazy"'


def fetch(url):
    """Busca uma página e retorna o HTML, ou None em erro/block."""
    try:
        r = requests.get(url, headers=HEADERS, impersonate='chrome', timeout=30)
    except Exception:
        return None
    if r.status_code != 200:
        return None
    return r.text


def parse_duration_mmss(text):
    """Converte '04:15' (mm:ss) ou '1:02:33' (h:mm:ss) para segundos."""
    m = re.match(r'^\s*(\d+):(\d{2})(?::(\d{2}))?\s*$', text or '')
    if not m:
        return 0
    if m.group(3) is not None:
        h = int(m.group(1))
        mi = int(m.group(2))
        s = int(m.group(3))
        return h * 3600 + mi * 60 + s
    # Formato mm:ss — o primeiro grupo são minutos, não horas.
    mi = int(m.group(1))
    s = int(m.group(2))
    return mi * 60 + s


def parse_iso_seconds(iso):
    """Converte duração ISO 8601 (PT02M17S) para segundos."""
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
    """Extrai o JSON-LD VideoObject da página do vídeo, se existir."""
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
    return None


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


def extract_tags(html):
    """Tags da página de vídeo: links de busca (search_query) e categorias.

    O markup usa <a class="tag..."> tanto para categorias (/videos/{slug}/)
    quanto para palavras-chave de busca (/search/videos?search_query=...).
    """
    tags = []
    for m in re.finditer(r'<a[^>]+class="tag[^"]*"[^>]*>(.*?)</a>', html, re.S | re.I):
        href = re.search(r'href="([^"]+)"', m.group(0))
        if not href:
            continue
        # Só tags reais de conteúdo (categoria ou busca); ignora canal/"mais videos".
        h = href.group(1)
        if not (h.startswith('/videos/') or 'search_query=' in h):
            continue
        label = re.sub(r'<[^>]+>', '', m.group(1)).strip()
        if label:
            tags.append(label)
    return tags


def parse_listing(html, page_url):
    """Extrai os vídeos da grade de uma página de listagem."""
    videos = []
    seen = set()

    # Divide pelos cards e lê href/título/miniatura/duração de cada bloco.
    chunks = html.split(CARD_START)[1:]
    for chunk in chunks:
        m = re.search(r'href="(https://www\.pornolandia\.xxx/video/(\d+)/[^"]+)"', chunk)
        if not m:
            continue
        source_url = m.group(1)
        vid = m.group(2)
        if vid in seen:
            continue
        seen.add(vid)

        # Título: atributo title do link (card tem title completo).
        title = ''
        tm = re.search(r'<a[^>]+title="([^"]*)"', chunk)
        if tm:
            title = tm.group(1).strip()

        # Miniatura: primeiro <img> do card (media/videos/tmb/{id}/1.jpg).
        thumbnail = ''
        im = re.search(r'<img[^>]+src="([^"]+\.(?:jpg|jpeg|png|webp)[^"]*)"', chunk)
        if im:
            thumbnail = im.group(1).strip()

        # Duração exibida no card: <div class="duration">04:15</div>
        duration = 0
        dm = re.search(r'<div class="duration">([^<]+)</div>', chunk)
        if dm:
            duration = parse_duration_mmss(dm.group(1))

        videos.append({
            'external_id': vid,
            'source_url': source_url,
            'canonical_url': source_url,
            'title': title,
            'description': '',
            'tags': '',
            'duration': duration,
            'duration_formatted': fmt_duration(duration),
            'thumbnail_url': thumbnail,
        })

    return videos


def enrich_video(video):
    """Busca a página do vídeo e completa descrição/tags/duração via JSON-LD."""
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

    if not video['tags']:
        tags = clean_tags(extract_tags(html))
        if tags:
            video['tags'] = tags


def build_page_url(url, page):
    """Monta a URL da página N a partir da URL base de listagem."""
    if page <= 1:
        return url

    # Categorias: /videos/{slug}/ → /videos/{slug}/N
    if re.search(r'/videos/[a-z0-9\-]+/?$', url.rstrip('/'), re.I):
        return url.rstrip('/') + '/' + str(page)

    # Listagem geral: troca o page existente ou adiciona ?page=N
    if re.search(r'[?&]page=\d+', url):
        return re.sub(r'([?&])page=\d+', r'\1page=' + str(page), url)
    separator = '&' if '?' in url else '?'
    return url + separator + 'page=' + str(page)


def has_more_pages(html):
    """True se existir rel="next" no head ou link de paginação 'Seguinte'."""
    if re.search(r'rel="next"\s+href="[^"]+"', html, re.I):
        return True
    if re.search(r'>(?:Seguinte|Pr[oó]xima)\s*&raquo;<', html, re.I):
        return True
    return False


def scrape_listing(url, page=1, max_pages=1):
    """Descobre vídeos de uma listagem, com enriquecimento dos itens."""
    page_url = build_page_url(url, page)
    html = fetch(page_url)
    if html is None:
        return {"error": "Failed to fetch page: %s" % page_url,
                "videos": [], "total": 0, "has_more": False}

    videos = parse_listing(html, page_url)
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

    total = len(videos)

    return {
        'videos': videos,
        'total': total,
        'has_more': has_more,
    }


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: pornolandia_scrape.py <url> [page] [max_pages]"}))
        sys.exit(1)

    url = sys.argv[1]
    page = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    max_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 1

    result = scrape_listing(url, page, max_pages)
    print(json.dumps(result))