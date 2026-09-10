<?php
/**
 * diag_gcs_media_chain.php — Diagnóstico READ-ONLY da cadeia GCS de um vídeo.
 *
 * Percorre, na ordem, os elos que os proxies gcs_thumbs.php / gcs_video.php
 * dependem para servir a mídia de um vídeo:
 *
 *   [1] Linha do vídeo na tabela `video`      (server, formats, thumbs, ...)
 *   [2] Linha do servidor na tabela `servers` (server_type='gcs', video_url,
 *       gcs_bucket, status) — casamento com video.server via video_url
 *   [3] Objetos no bucket (listObjects):
 *         thumbs/{VID}/  (default.jpg, 1..20.jpg, sprite.jpg, video.mp4/webm)
 *         h264/{VID}/    (um objeto por formato, ex.: 1080p.mp4)
 *   [4] Fetch real de um objeto pequeno (default.jpg) via OAuth2 Bearer —
 *       mesmo transporte (token + alt=media) que o proxy usa
 *
 * NÃO escreve nada: apenas SELECT + listObjects + 1 GET pequeno no bucket.
 * Não imprime credenciais (só nomes de objetos e campos não-secretos).
 *
 * Uso (na raiz do projeto, com túnel Cloud SQL ativo — 127.0.0.1:3307):
 *   php scripts/diag_gcs_media_chain.php <VID>
 *
 * Env (lidos de .env via include/dotenv.php; env real sempre vence):
 *   DB_HOST/DB_USER/DB_PASSWORD/DB_NAME, GCS_KEY_PATH, GCS_BUCKET
 *
 * Exit code: 0 = cadeia íntegra, 1 = pelo menos um elo quebrado,
 *            2 = erro de uso/ambiente.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

// ---------------------------------------------------------------- args
$vid = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^\d+$/', $arg)) {
        $vid = (int) $arg;
    } else {
        fwrite(STDERR, "Uso: php " . basename($argv[0]) . " <VID>\n");
        exit(2);
    }
}
if ($vid === null || $vid <= 0) {
    fwrite(STDERR, "Uso: php " . basename($argv[0]) . " <VID>\n");
    exit(2);
}

$root = dirname(__DIR__);

// ---------------------------------------------------------------- bootstrap
define('_VALID', true);
require $root . '/include/dotenv.php';
$config = array();
require $root . '/include/config.paths.php';
require $root . '/include/config.db.php';
require $root . '/include/config.local.php'; // sobrescreve db_host (Cloud SQL proxy 127.0.0.1:3307)

$db_host = isset($config['db_host']) ? $config['db_host'] : '127.0.0.1';
$db_user = isset($config['db_user']) ? $config['db_user'] : 'avs_app';
$db_pass = isset($config['db_pass']) ? $config['db_pass'] : '';
$db_name = isset($config['db_name']) ? $config['db_name'] : 'avs';

// ---------------------------------------------------------------- helpers
$results = array();

function line($txt = '')
{
    echo $txt . "\n";
}

function status($code, $label, $detail = '')
{
    echo str_pad('[' . $code . ']', 6) . ' ' . $label . (($detail !== '') ? ' — ' . $detail : '') . "\n";
    $GLOBALS['results'][$label] = $code;
}

function summaryAndExit()
{
    $results = $GLOBALS['results'];
    line();
    line('======================================================================');
    line(' RESUMO DA CADEIA GCS (read-only)');
    line('----------------------------------------------------------------------');
    $nFail = 0;
    $nWarn = 0;
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
        line(' VEREDITO: ' . $nFail . ' elo(s) quebrado(s) — os proxies gcs_thumbs.php /');
        line(' gcs_video.php retornam 404 para este vídeo. Corrija os FAIL acima.');
        line('======================================================================');
        exit(1);
    }
    line(' VEREDITO: cadeia íntegra' . ($nWarn > 0 ? ' (com ' . $nWarn . ' aviso(s))' : '')
        . ' — o proxy deve servir a mídia deste vídeo.');
    line(' (Validação final: abrir a página do vídeo no navegador e conferir thumbs/player.)');
    line('======================================================================');
    exit(0);
}

// ---------------------------------------------------------------- banner
line();
line('======================================================================');
line(' DIAGNÓSTICO — cadeia de mídia GCS (READ-ONLY)');
line('======================================================================');
line('  Video ID    : ' . $vid);
line('  DB          : ' . $db_name . '@' . $db_host);
line('  Base dir    : ' . $config['BASE_DIR']);
line('----------------------------------------------------------------------');

// ---------------------------------------------------------------- DB
$db = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($db->connect_error) {
    status('FAIL', 'Conexão com o banco', 'mysqli: ' . $db->connect_error);
    line();
    line('  Dica: suba o túnel Cloud SQL (./tunnel.sh) e confira DB_HOST no .env.');
    summaryAndExit();
}
$db->set_charset('utf8');
line('Banco conectado.');
line();

// ================================================================ [1] video
line('== [1] Vídeo (tabela `video`) ==');
$res = $db->query("SELECT VID, title, server, formats, thumb, thumbs, hd, iphone, type, active
                   FROM video WHERE VID = " . $vid . " LIMIT 1");
if (!$res || $res->num_rows !== 1) {
    status('FAIL', '[1] vídeo', 'não encontrado na tabela `video`');
    summaryAndExit();
}
$video = $res->fetch_assoc();
$res->free();
line('  title   : ' . $video['title']);
line('  active  : ' . $video['active'] . '   type=' . $video['type']
    . '   thumb=' . $video['thumb'] . '   thumbs(coluna)=' . $video['thumbs']
    . '   hd=' . $video['hd'] . '   iphone=' . $video['iphone']);
line('  server  : ' . var_export($video['server'], true));
line('  formats : ' . var_export($video['formats'], true));
line();

if (trim((string) $video['server']) === '') {
    status('FAIL', '[1] vídeo', 'video.server vazio — o app trata o vídeo como local/FTP e não gera URLs gcs_thumbs.php/gcs_video.php');
    summaryAndExit();
}

// ================================================================ [2] servers
line('== [2] Servidor (tabela `servers`) ==');
$res = $db->query("SELECT server_id, video_url, server_type, gcs_bucket, gcs_key_path, status
                   FROM servers ORDER BY server_id");
$servers = array();
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $servers[] = $row;
    }
    $res->free();
}
if (empty($servers)) {
    status('FAIL', '[2] servidores', 'tabela `servers` vazia — nenhuma linha cadastrada');
    summaryAndExit();
}
foreach ($servers as $s) {
    line(sprintf('  id=%d  type=%-4s status=%s  bucket=%-24s video_url=%s',
        $s['server_id'], $s['server_type'], $s['status'],
        (string) $s['gcs_bucket'], (string) $s['video_url']));
}
$serverUrl = rtrim((string) $video['server'], '/');
$server    = null;
foreach ($servers as $s) {
    if (rtrim((string) $s['video_url'], '/') === $serverUrl) {
        $server = $s;
        break;
    }
}
line();
if (!$server) {
    status('FAIL', '[2] servidor do vídeo', 'nenhuma linha `servers` tem video_url = "' . $serverUrl
        . '" (valor de video.server). O casamento get_server_by_video_url() falha → proxies 404.');
    summaryAndExit();
}
status('OK', '[2] servidor do vídeo', 'casou com id=' . $server['server_id'] . ' via video_url');
if ($server['server_type'] !== 'gcs') {
    status('FAIL', '[2] servidor do vídeo', 'server_type = ' . $server['server_type']
        . ' (esperado gcs) — gcs_get_server_by_vid() retorna false');
    summaryAndExit();
}
if ($server['status'] != '1') {
    status('WARN', '[2] servidor do vídeo', 'status = ' . $server['status'] . ' (inativo)');
}
if (trim((string) $server['gcs_bucket']) === '') {
    status('FAIL', '[2] servidor do vídeo', 'gcs_bucket vazio — gcs_get_client() retorna false');
    summaryAndExit();
}
line();

// ================================================================ [3] bucket
line('== [3] Objetos no bucket (gs://' . $server['gcs_bucket'] . ') ==');

require_once $root . '/classes/gcs.class.php';

$keyPath = getenv('GCS_KEY_PATH');
if ($keyPath === false || $keyPath === '') {
    $keyPath = (string) $server['gcs_key_path'];
}
if ($keyPath !== '') {
    $keyPathAbs = $keyPath;
    if (!file_exists($keyPathAbs)) {
        $rel = $config['BASE_DIR'] . '/' . $keyPath;
        if (file_exists($rel)) {
            $keyPathAbs = $rel;
        }
    }
    line('  chave SA  : ' . (file_exists($keyPathAbs) ? 'encontrada (' . $keyPathAbs . ')' : 'NÃO ENCONTRADA (' . $keyPath . ')'));
} else {
    line('  chave SA  : via env GCS_KEY_JSON (inline) ou ausente');
}

$gcs = new GCS($keyPath, $server['gcs_bucket']);

// Token (valida credencial de ponta a ponta — sem imprimir o token)
$token = $gcs->getReadAccessToken();
if ($token === false) {
    status('FAIL', '[3] access token', 'getReadAccessToken() falhou: ' . $gcs->getError());
    summaryAndExit();
}
status('OK', '[3] access token', 'OAuth2 Bearer obtido (credencial válida)');

$objectsThumbs = $gcs->listObjects('thumbs/' . $vid . '/');
if ($objectsThumbs === false) {
    status('FAIL', '[3] listObjects thumbs/' . $vid . '/', 'falha na API: ' . $gcs->getError());
    summaryAndExit();
}
$objectsH264 = $gcs->listObjects('h264/' . $vid . '/');
if ($objectsH264 === false) {
    status('FAIL', '[3] listObjects h264/' . $vid . '/', 'falha na API: ' . $gcs->getError());
    summaryAndExit();
}

$namesThumbs = array_values(array_filter($objectsThumbs, function ($n) {
    return substr($n, -1) !== '/';
}));
$namesH264 = array_values(array_filter($objectsH264, function ($n) {
    return substr($n, -1) !== '/';
}));

line();
line('  thumbs/' . $vid . '/ : ' . (empty($namesThumbs) ? '(vazio — NENHUM objeto)' : count($namesThumbs) . ' objeto(s)'));
foreach ($namesThumbs as $n) {
    line('      ' . basename($n));
}
line('  h264/' . $vid . '/   : ' . (empty($namesH264) ? '(vazio — NENHUM objeto)' : count($namesH264) . ' objeto(s)'));
foreach ($namesH264 as $n) {
    line('      ' . basename($n));
}

// ---- expectativas
$expected = array();

$thumbCount = (int) $video['thumbs'];
if ($thumbCount <= 0) {
    $thumbCount = 20;
}
$expected['default.jpg'] = false;
for ($i = 1; $i <= $thumbCount; $i++) {
    $expected[$i . '.jpg'] = false;
}
$expected['sprite.jpg'] = false;
$expected['video.mp4']  = false;
$expected['video.webm'] = false;

$have = array();
foreach ($namesThumbs as $n) {
    $have[basename($n)] = true;
}

line();
$missingThumbs = array();
foreach ($expected as $file => $v) {
    if (isset($have[$file])) {
        continue;
    }
    $missingThumbs[] = $file;
}
if (empty($missingThumbs)) {
    status('OK', '[3] thumbs no bucket', 'todos os arquivos esperados presentes (default, 1..' . $thumbCount . ', sprite, video.mp4/webm)');
} else {
    $list = array_slice($missingThumbs, 0, 10);
    $detail = 'ausentes: ' . implode(', ', $list) . (count($missingThumbs) > 10 ? ', ...' : '');
    // default.jpg + frames são o essencial; sprite/miniclips têm geradores sob demanda
    $essentialMissing = array_intersect($missingThumbs, array_merge(array('default.jpg'), array_map(function ($i) {
        return $i . '.jpg';
    }, range(1, $thumbCount))));
    if ($essentialMissing) {
        status('FAIL', '[3] thumbs no bucket', $detail . ' — proxy gcs_thumbs.php responde 404 para estes arquivos');
    } else {
        status('WARN', '[3] thumbs no bucket', $detail . ' (não-essenciais; sprite/miniclips são regenerados sob demanda)');
    }
}

// ---- formatos h264 esperados
$expectedH264 = array();
$formats = array_filter(array_map('trim', explode(',', (string) $video['formats'])));
if (empty($formats)) {
    status('WARN', '[3] h264 no bucket', 'video.formats vazio — sem como inferir objetos esperados');
} else {
    foreach ($formats as $f) {
        $parts = explode('.', $f);
        if (count($parts) >= 3) {
            $expectedH264[] = $parts[1] . '.' . $parts[2];
        }
    }
    $haveH264 = array();
    foreach ($namesH264 as $n) {
        $haveH264[basename($n)] = true;
    }
    $missingH264 = array_diff($expectedH264, array_keys($haveH264));
    if (empty($missingH264)) {
        status('OK', '[3] h264 no bucket', 'todos os formatos presentes: ' . implode(', ', $expectedH264));
    } else {
        status('FAIL', '[3] h264 no bucket', 'formats da coluna video.formats ausentes no bucket: '
            . implode(', ', $missingH264) . ' — gcs_video.php responde 404');
    }
}
line();

// ================================================================ [4] fetch
line('== [4] Fetch real via OAuth2 Bearer (mesmo transporte dos proxies) ==');

$probe = null;
if (isset($have['default.jpg'])) {
    $probe = 'thumbs/' . $vid . '/default.jpg';
} elseif (!empty($namesThumbs)) {
    $probe = $namesThumbs[0];
} elseif (!empty($namesH264)) {
    $probe = $namesH264[0];
}

if ($probe === null) {
    status('SKIP', '[4] fetch', 'bucket sem objetos para o vídeo — nada a buscar');
    summaryAndExit();
}

$objectUrl = 'https://storage.googleapis.com/storage/v1/b/'
    . urlencode($server['gcs_bucket'])
    . '/o/' . rawurlencode($probe) . '?alt=media';

$ch = curl_init($objectUrl);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 60
));
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$err  = curl_error($ch);
curl_close($ch);

line('  objeto : ' . $probe);
line('  status : HTTP ' . $code . (($type !== false && $type !== '') ? '  type=' . $type : ''));
if ($err !== '') {
    status('FAIL', '[4] fetch', 'erro cURL: ' . $err);
} elseif ($code === 200 && $body !== false && strlen($body) > 0) {
    status('OK', '[4] fetch', 'HTTP 200, ' . strlen($body) . ' bytes (' . $type . ') — token + leitura funcionando');
} elseif ($code === 404) {
    status('FAIL', '[4] fetch', 'HTTP 404 do GCS — objeto não existe no bucket (ou prefixo errado)');
} else {
    status('FAIL', '[4] fetch', 'HTTP ' . $code . ' — confira IAM da Service Account no bucket');
}
line();

summaryAndExit();