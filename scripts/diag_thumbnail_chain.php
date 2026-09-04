<?php
/**
 * diag_thumbnail_chain.php — Diagnostic for the timeline scrub-preview chain.
 *
 * Probes, in order, the links between "Manage Videos" and the .avs-preview box:
 *
 *   [1] Player profile  ->  timeline_preview flag (DB: player table)
 *   [2] Thumb frames 1.jpg..20.jpg  (disk, media/videos/{tmb}/{VID}/)
 *   [3] sprite.jpg built + served over HTTP (the watch/embed page rebuilds it)
 *   [4] Globals emitted on the page (player_timeline_preview / player_sprite)
 *
 * Usage (run on the VM / project root):
 *   php scripts/diag_thumbnail_chain.php <VID> [opcoes]
 *
 * Options:
 *   --page=watch|embed   Which page+profile to probe (default: watch -> profile 'Main';
 *                        embed -> profile 'Embed', fetched by vkey)
 *   --no-http            Skip the network probes (3 and 4). Only DB + disk checks.
 *   --insecure           Allow self-signed HTTPS (internal hosts). Off by default.
 *   --debug              Print extra detail.
 *
 * DB credentials come from env vars (same convention as scripts/gcs_reorganize.php
 * and include/function_migrations.php); when unset, the values in
 * include/config.db.php are used as fallback:
 *   DB_HOST  DB_USER  DB_PASS  DB_NAME
 *
 * The site base URL is read from include/config.paths.php + config.local.php
 * (as the templates do). Override for external/domain checks with:
 *   SITE_BASE_URL=https://seu-dominio
 *
 * IMPORTANT side effect: the HTTP probe fetches the real watch/embed page, and
 * both pages run "UPDATE video SET viewnumber = viewnumber + 1". Each probe
 * therefore counts as 1 normal view. Use --no-http to avoid it.
 *
 * Exit code: 0 = chain OK, 1 = at least one link FAIL, 2 = usage error.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$root = dirname(__DIR__); // project root (…/include/config.*.php lives inside)

// ---------------------------------------------------------------- arguments
$vid      = null;
$page     = 'watch';
$doHttp   = true;
$insecure = false;
$debug    = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--page=(watch|embed)$/', $arg, $m)) {
        $page = $m[1];
    } elseif ($arg === '--no-http') {
        $doHttp = false;
    } elseif ($arg === '--insecure') {
        $insecure = true;
    } elseif ($arg === '--debug') {
        $debug = true;
    } elseif (preg_match('/^\d+$/', $arg)) {
        $vid = (int) $arg;
    } else {
        usage();
    }
}

if ($vid === null || $vid <= 0) {
    usage();
}

function usage()
{
    global $argv;
    fwrite(STDERR,
        "Uso: php " . basename($argv[0]) . " <VID> [--page=watch|embed] [--no-http] [--insecure] [--debug]\n");
    exit(2);
}

// ---------------------------------------------------------------- bootstrap config
define('_VALID', true);
$config = array();
require $root . '/include/config.paths.php';   // BASE_URL, BASE_DIR, RELATIVE (resets $config)
require $root . '/include/config.local.php';   // real site values (max_thumb_folders etc.)
require $root . '/include/config.db.php';      // DB fallback credentials

$siteBase = getenv('SITE_BASE_URL');
if ($siteBase === false || $siteBase === '') {
    $siteBase = isset($config['BASE_URL']) ? $config['BASE_URL'] : '';
    $baseFrom = 'config (BASE_URL)';
} else {
    $baseFrom = 'env SITE_BASE_URL';
}
$siteBase = rtrim($siteBase, '/');

$db_host = getenv('DB_HOST') ?: (isset($config['db_host']) ? $config['db_host'] : 'localhost');
$db_user = getenv('DB_USER') ?: (isset($config['db_user']) ? $config['db_user'] : 'root');
$db_pass = getenv('DB_PASS') ?: (isset($config['db_pass']) ? $config['db_pass'] : '');
$db_name = getenv('DB_NAME') ?: (isset($config['db_name']) ? $config['db_name'] : 'avs');

$maxThumbFolders = isset($config['max_thumb_folders']) ? (int) $config['max_thumb_folders'] : 32000;
$imgW            = isset($config['img_max_width']) ? (int) $config['img_max_width'] : 384;
$imgH            = isset($config['img_max_height']) ? (int) $config['img_max_height'] : 216;

// ------------------------------------------------------------------ helpers
$results = array(); // label => status (OK|FAIL|WARN|SKIP|INFO)

function line($txt = '')
{
    echo $txt . "\n";
}

function status($code, $label, $detail = '')
{
    $tag = str_pad('[' . $code . ']', 6);
    echo $tag . ' ' . $label . (($detail !== '') ? ' — ' . $detail : '') . "\n";
    $GLOBALS['results'][$label] = $code;
}

function summaryAndExit($code)
{
    $results = $GLOBALS['results'];
    $page    = $GLOBALS['page'];

    line();
    line('======================================================================');
    line(' RESUMO DA CADEIA  (perfil em uso: ' . $page . ')');
    line('----------------------------------------------------------------------');
    $nFail  = 0;
    $nWarn  = 0;
    foreach ($results as $label => $st) {
        line('  [' . str_pad($st, 4) . '] ' . $label);
        if ($st === 'FAIL') {
            $nFail++;
        } elseif ($st === 'WARN') {
            $nWarn++;
        }
    }
    line('----------------------------------------------------------------------');
    if ($nFail > 0) {
        line(' VEREDITO: ' . $nFail . ' elo(s) quebrado(s) — o preview de scrub NAO deve aparecer.');
        line(' Corrija os FAIL acima (na maioria dos casos: timeline_preview=1 no perfil,');
        line(' frames 1..20 presentes, ou BASE_URL correta) e rode de novo.');
        line('======================================================================');
        exit(1);
    }
    $profileLabel = '[1] perfil ' . $page;
    if (isset($results[$profileLabel]) && $results[$profileLabel] === 'OK') {
        line(' VEREDITO: cadeia OK' . ($nWarn > 0 ? ' (com ' . $nWarn . ' aviso(s) — vale revisar)' : '') .
            ' — com timeline_preview=1, frames ok, sprite 200 e globals');
        line(' presentes, a pre-visualizacao (`.avs-preview`) deve renderizar no player.');
        line(' (Validacao final: abrir a pagina no navegador e passar o mouse no `.avs-seek`.)');
    } else {
        line(' VEREDITO: sem FAILs detectados nesta execucao.');
    }
    line('======================================================================');
    exit($code);
}

function thumbFolder($vid)
{
    global $config, $maxThumbFolders;
    $index      = intval(($vid - 1) / max(1, $maxThumbFolders));
    $tmb_folder = ($index === 0) ? 'tmb' : 'tmb' . $index;
    return $config['BASE_DIR'] . '/media/videos/' . $tmb_folder . '/' . $vid;
}

/**
 * HTTP GET. Returns array(status, body) or null on transport error.
 * Prefers the cURL extension; falls back to the stream wrapper.
 */
function httpGet($url, $insecure, $timeout = 20)
{
    $status = 0;
    $body   = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        ));
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return array('error' => $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array('status' => $code, 'body' => $resp);
    }

    $ctx = stream_context_create(array(
        'http' => array(
            'method'          => 'GET',
            'timeout'         => $timeout,
            'ignore_errors'   => true,
            'follow_location' => 0,
        ),
        'ssl'  => array(
            'verify_peer'      => !$insecure,
            'verify_peer_name' => !$insecure,
        ),
    ));
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        return array('error' => 'stream request failed');
    }
    $status = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    return array('status' => $status, 'body' => $resp);
}

// ------------------------------------------------------------- banner
line();
line('======================================================================');
line(' DIAGNOSTICO — cadeia de pre-visualizacao no scrub (timeline preview)');
line('======================================================================');
line('  Video ID        : ' . $vid);
line('  Pagina / perfil : ' . $page . '  (profile "' . ($page === 'embed' ? 'Embed' : 'Main') . '")');
line('  Base URL        : ' . $siteBase . '  [fonte: ' . $baseFrom . ']');
line('  Base dir        : ' . $config['BASE_DIR']);
line('  HTTP probes     : ' . ($doHttp ? 'sim (1 view sera contabilizada)' : 'NAO (--no-http)'));
line('----------------------------------------------------------------------');

// ------------------------------------------------------------------ DB
$db = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($db->connect_error) {
    status('FAIL', 'Conexao com o banco', 'mysqli: ' . $db->connect_error);
    summaryAndExit(1);
}
$db->set_charset('utf8');
line('Banco            : ' . $db_name . '@' . $db_host . ' (conectado)');
line();

// ================================================================ PROBE 1 — player profile
line('== [1] Perfil do player (tabela `player`) ==');

$profiles = array('Main', 'Embed');
$playerRows = array();
$res = $db->query("SELECT id, profile, engine, autoplay, resolution, timeline_preview, status
                   FROM player WHERE profile IN ('Main','Embed') ORDER BY id");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $playerRows[$row['profile']] = $row;
    }
    $res->free();
}

foreach ($profiles as $p) {
    if (isset($playerRows[$p])) {
        $r = $playerRows[$p];
        line(sprintf("  %-6s id=%d  engine=%-10s autoplay=%-3s resolution=%-5s timeline_preview=%s  status=%s",
            $p, $r['id'], $r['engine'], $r['autoplay'], $r['resolution'], $r['timeline_preview'], $r['status']));
    } else {
        line("  $p  (linha inexistente)");
    }
}

$chosen = ($page === 'embed') ? 'Embed' : 'Main';
if (!isset($playerRows[$chosen])) {
    status('FAIL', '[1] perfil ' . $chosen, 'linha nao existe na tabela `player`');
} elseif ($playerRows[$chosen]['status'] !== '1') {
    status('FAIL', '[1] perfil ' . $chosen, 'status = ' . $playerRows[$chosen]['status'] . ' (inativo — nada e servido)');
} else {
    $pr = $playerRows[$chosen];
    $ok = true;
    $why = '';
    if ($pr['timeline_preview'] !== '1') {
        $ok = false;
        $why = 'timeline_preview=' . $pr['timeline_preview'] . ' desliga o sprite/preview (Admin > Players > ' . $chosen . ')';
    }
    if ($ok) {
        status('OK', '[1] perfil ' . $chosen, 'engine=' . $pr['engine'] . ' e timeline_preview=1');
    } else {
        status('FAIL', '[1] perfil ' . $chosen, $why);
    }
}
if ($debug && isset($playerRows[$chosen]) && $playerRows[$chosen]['engine'] !== 'mediabunny') {
    line('  [DEBUG] engine=' . $playerRows[$chosen]['engine'] . ' — preview existe nos dois engines; o switch e o mesmo.');
}
line();

// ================================================================ PROBE 0/2 prep — video row
$sqlVideo = "SELECT VID, title, thumbs, thumb, type, active, embed_code, vkey, server, duration
             FROM video WHERE VID = " . (int) $vid . " LIMIT 1";
$res = $db->query($sqlVideo);
if (!$res || $res->num_rows !== 1) {
    status('FAIL', 'Video ' . $vid, 'nao encontrado na tabela `video`');
    summaryAndExit(1);
}
$video = $res->fetch_assoc();
$res->free();
$db->close();

line('== Video ==');
line('  title     : ' . $video['title']);
line('  vkey      : ' . $video['vkey'] . '   type=' . $video['type'] . '   active=' . $video['active']);
line('  thumbs    : ' . $video['thumbs'] . ' (coluna: qtd. de thumbs geradas na conversao)');
line('  duration  : ' . $video['duration'] . ' s   server=' . $video['server']);
if ($video['embed_code'] !== '' && $video['embed_code'] !== null) {
    line('  embed_code: definido (video e embed externo)');
}
line();

$isEmbedOnly = ($video['embed_code'] !== '' && $video['embed_code'] !== null);
$pageUrl = '';
if ($page === 'embed') {
    $pageUrl = $siteBase . '/embed/' . rawurlencode($video['vkey']);
} else {
    $pageUrl = $siteBase . '/video/' . $vid;
}

// ================================================================ PROBE 2 — thumb frames on disk
line('== [2] Frames de thumbnail em disco ==');

$thumbDir = thumbFolder($vid);
line('  dir       : ' . $thumbDir);

$expectedTotal = 20; // sprite.class.php always loops 1..20
if (!is_dir($thumbDir)) {
    status('FAIL', '[2] pasta de thumbs', 'nao existe — thumbs foram apagadas ou o video nunca teve thumbs geradas');
} else {
    $all = scandir($thumbDir);
    $numbered = array();
    $hasDefault = false;
    foreach ($all as $f) {
        if (preg_match('/^(\d+)\.jpg$/i', $f, $m)) {
            $numbered[(int) $m[1]] = $f;
        } elseif (strtolower($f) === 'default.jpg') {
            $hasDefault = true;
        }
    }
    ksort($numbered);
    $missing = array();
    for ($i = 1; $i <= $expectedTotal; $i++) {
        if (!isset($numbered[$i])) {
            $missing[] = $i;
        }
    }
    $found = $expectedTotal - count($missing);
    line('  frames    : ' . $found . '/' . $expectedTotal . ' presentes (1.jpg..' . $expectedTotal . '.jpg)'
        . ($hasDefault ? '  + default.jpg ok' : '  + default.jpg AUSENTE'));

    if (count($missing) === 0) {
        status('OK', '[2] frames de thumbnail', 'todas as ' . $expectedTotal . ' presentes');
    } elseif (count($missing) === $expectedTotal) {
        status('FAIL', '[2] frames de thumbnail', 'nenhum frame numerado encontrado (faltam 1.jpg..' . $expectedTotal . '.jpg)');
    } else {
        $hint = '';
        if ((int) $video['thumbs'] < $expectedTotal) {
            $hint = ' — coluna video.thumbs=' . $video['thumbs'] . ' sugere que a conversao gerou menos frames; '
                  . 'sprite.class.php exige 1..' . $expectedTotal . ' e gera avisos/gaps no sprite';
        }
        status('WARN', '[2] frames de thumbnail',
            'faltam: ' . implode(',', array_slice($missing, 0, 12)) . (count($missing) > 12 ? ', ...' : '') . $hint);
    }
    if (!$hasDefault) {
        status('WARN', '[2] default.jpg', 'ausente — poster do player tambem quebra');
    }
    if ($debug && count($numbered) > 0) {
        $first = array_slice($numbered, 0, 5, true);
        line('  [DEBUG] primeiros frames: ' . implode(' ', array_map(function ($k, $v) {
            return $k . '=' . $v;
        }, array_keys($first), $first)));
    }
}
line();

// ================================================================ PROBE 3/4 — HTTP
if (!$doHttp) {
    line('== [3]/[4] Probes HTTP puladas (--no-http) ==');
    // Local-only partial info for sprite file
    $spriteFile = $thumbDir . '/sprite.jpg';
    if (is_file($spriteFile)) {
        $sz = filesize($spriteFile);
        status('INFO', '[3] sprite.jpg local', 'existe (' . round($sz / 1024) . ' KB) — servido apos a 1a view da pagina');
    } else {
        status('WARN', '[3] sprite.jpg local', 'ainda nao existe — e gerado na primeira carga da pagina com timeline_preview=1');
    }
    line();
    line('  Para validar [3] e [4] rode sem --no-http (custa 1 view no contador).');
    line();
    summaryAndExit(0);
}

if ($isEmbedOnly) {
    line('== [3]/[4] HTTP ==');
    status('SKIP', 'Video embed-only',
        'embed_code preenchido — o player local ('. basename($pageUrl) .') nao monta o sprite nem o preview; cadeia nao se aplica');
    line();
    summaryAndExit(0);
}

line('== [3]/[4] HTTP (pagina real: ' . $pageUrl . ') ==');
line('  ATENCAO: este GET conta 1 view (UPDATE video.viewnumber) e regera o sprite no servidor, como qualquer visita.');
$pageResp = httpGet($pageUrl, $insecure);
if (isset($pageResp['error'])) {
    status('FAIL', 'HTTP pagina', 'erro de transporte: ' . $pageResp['error'] . ' — confira SITE_BASE_URL / rede');
    line();
    summaryAndExit(1);
}

$pageStatus = $pageResp['status'];
$html = $pageResp['body'];
line('  pagina      : HTTP ' . $pageStatus . ' (' . strlen($html) . ' bytes)');

if ($pageStatus === 200) {
    status('OK', '[4] pagina carregada', 'HTTP 200');
} elseif ($pageStatus >= 300 && $pageStatus < 400) {
    status('WARN', '[4] pagina carregada', 'HTTP ' . $pageStatus . ' (redirect) — pode ser splash/login/aprovacao (' . $pageUrl . ')');
} elseif ($pageStatus >= 500) {
    status('FAIL', '[4] pagina carregada', 'HTTP ' . $pageStatus . ' — possivel fatal no sprite.class.php '
        . '(frame ausente -> imagecreatefromjpeg() falha; veja o log de erros do PHP/apache)');
} else {
    status('FAIL', '[4] pagina carregada', 'HTTP ' . $pageStatus . ' — pagina nao acessivel');
}

// ---- globals (probe 4)
$timeline = null;
$sprite   = null;
if (preg_match('/var\s+player_timeline_preview\s*=\s*"([^"]*)"/', $html, $m)) {
    $timeline = $m[1];
}
if (preg_match('/var\s+player_sprite\s*=\s*"([^"]*)"/', $html, $m)) {
    $sprite = $m[1];
}

line('  globals     : player_timeline_preview = "' . (string) $timeline . '" | player_sprite = "' . (string) $sprite . '"');

if ($timeline === null) {
    status('FAIL', '[4] global player_timeline_preview', 'variavel nao encontrada no HTML — player_settings.tpl nao foi incluido '
        . '(header.tpl so inclui com {$view} && !embed_code; conferir o include no tema)');
} elseif ($timeline === '1') {
    status('OK', '[4] global player_timeline_preview', '= 1 (preview ligado)');
} else {
    status('FAIL', '[4] global player_timeline_preview', '= "' . $timeline . '" — preview desligado no perfil em uso');
}

if ($timeline === '1' && $pageStatus === 200) {
    if ($sprite === null || $sprite === '') {
        status('FAIL', '[4] global player_sprite', 'vazia/ausente mesmo com timeline_preview=1 — branch do sprite em video.php/embed.php nao executou');
    } else {
        status('OK', '[4] global player_sprite', '= ' . $sprite);
    }
}

// ---- sprite.jpg over HTTP (probe 3) — page fetch above already regenerated it server-side
$spriteFile = $thumbDir . '/sprite.jpg';
$localSprite = is_file($spriteFile) ? filesize($spriteFile) : false;

$expectedSpriteUrl = $siteBase . '/media/videos/'
    . (intval(($vid - 1) / max(1, $maxThumbFolders)) === 0 ? 'tmb' : 'tmb' . intval(($vid - 1) / max(1, $maxThumbFolders)))
    . '/' . $vid . '/sprite.jpg';

if ($timeline === '1' && $pageStatus === 200) {
    if ($sprite !== null && $sprite !== '') {
        $spriteUrl = $sprite;
    } else {
        $spriteUrl = $expectedSpriteUrl;
    }
    $spriteResp = httpGet($spriteUrl, $insecure);
    if (isset($spriteResp['error'])) {
        status('FAIL', '[3] sprite.jpg HTTP', 'erro de transporte ao buscar ' . $spriteUrl . ' — ' . $spriteResp['error']);
    } elseif ($spriteResp['status'] === 200) {
        $bytes = strlen($spriteResp['body']);
        status('OK', '[3] sprite.jpg HTTP', 'HTTP 200, ' . round($bytes / 1024) . ' KB (' . $spriteUrl . ')');
        if ($bytes === 0) {
            status('WARN', '[3] sprite.jpg HTTP', 'resposta vazia (0 bytes)');
        }
    } else {
        $hint = '';
        if ($localSprite === false) {
            $hint = ' — arquivo tambem ausente em disco (' . $spriteFile . '); a regeneracao roda em cada view com timeline_preview=1';
        } else {
            $hint = ' — arquivo existe em disco mas nao e servido; confira alias/rota estatica e BASE_URL';
        }
        status('FAIL', '[3] sprite.jpg HTTP', 'HTTP ' . $spriteResp['status'] . ' para ' . $spriteUrl . $hint);
    }
} else {
    line('  [3] sprite.jpg via HTTP: pulado (perfil com timeline_preview=0 ou pagina sem HTTP 200 — sem regeneracao).');
    if ($localSprite !== false) {
        status('INFO', '[3] sprite.jpg local', 'existe em disco (' . round($localSprite / 1024) . ' KB) mas a pagina atual nao o regenera');
    }
}

// ---- coherence: emitted sprite URL vs expected
if ($timeline === '1' && $pageStatus === 200 && $sprite !== null && $sprite !== '') {
    $norm = function ($u) { return rtrim(preg_replace('#^https?://#', '', $u), '/'); };
    if (strcasecmp($norm($sprite), $norm($expectedSpriteUrl)) !== 0) {
        status('WARN', '[4] coerencia sprite', 'URL emitida difere da esperada (' . $expectedSpriteUrl . ') — confira BASE_URL config vs dominio real');
    } else {
        status('OK', '[4] coerencia sprite', 'URL emitida = URL esperada');
    }
}
line();

summaryAndExit(0);


