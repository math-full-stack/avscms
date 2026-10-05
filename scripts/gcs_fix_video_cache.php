<?php
/**
 * One-shot: troca o Cache-Control dos vídeos já gravados no bucket GCS.
 *
 * Por que: include/function_server.php gravava 'private, max-age=0, no-store'
 * em todo upload de h264/{vid}/{label}.mp4. Com no-store o browser não cacheia
 * nada, então cada seek/troca de qualidade aborta a leitura em andamento e
 * re-baixa do byte 0 — e toda leitura abortada aparece no billing como
 * ReadObject|CANCELLED, com os bytes já enviados e cobrados. Medido: 4.47 GiB
 * de CANCELLED contra 1.87 GiB de OK em 03–05/out.
 *
 * Usa objects.patch (metadata-only): não move dado, não muda size/md5 e
 * PRESERVA o ACL publicRead do objeto (uniform bucket access está desligado
 * neste bucket, então o ACL por objeto é o que mantém o player funcionando).
 *
 * Idempotente: só faz PATCH nos objetos que ainda têm o valor antigo.
 *
 * Uso:
 *   GOOGLE_APPLICATION_CREDENTIALS=/path/gcs-service-account.json \
 *   php scripts/gcs_fix_video_cache.php            # só conta
 *   php scripts/gcs_fix_video_cache.php --apply    # executa
 */

define('_VALID', 1);
define('_CLI', true);
define('_ENTER', true);

$config = array(
    'bucket'  => 'pornozinho-media',
    'prefix'  => 'h264/',
    'old'     => 'private, max-age=0, no-store',
    'new'     => 'public, max-age=2592000',
    'api'     => 'https://storage.googleapis.com/storage/v1',
    'dryRun'  => !in_array('--apply', $_SERVER['argv'], true),
);

// Token da SA: usa a chave local (gsutil/gcloud não podem ser usados aqui —
// o usuário não tem storage.objects.update, e o gsutil cai no credential
// conflict do gcloud).
function gcs_token()
{
    $cache = sys_get_temp_dir() . '/avs_gcs_token.json';
    if (file_exists($cache) && filemtime($cache) > time() - 3000) {
        $d = json_decode(file_get_contents($cache), true);
        if (!empty($d['access_token'])) {
            return $d['access_token'];
        }
    }
    $key = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    if (!$key || !file_exists($key)) {
        fwrite(STDERR, "FALTA GOOGLE_APPLICATION_CREDENTIALS\n");
        exit(1);
    }
    // O token é mintado pelo gcloud, não por um JWT montado aqui: o build de
    // OpenSSL do PHP do XAMPP (8.2.4 / 1.1.1t) devolve OpenSSLAsymmetricKey em
    // openssl_pkey_get_private() mas o openssl_sign() recusa a mesma chave
    // ("cannot be coerced into a private key"), então o JWT local nunca valida.
    $cmd = sprintf(
        'GOOGLE_APPLICATION_CREDENTIALS=%s gcloud auth application-default print-access-token 2>/dev/null',
        escapeshellarg($key)
    );
    $tok = trim(shell_exec($cmd));
    if ($tok === '' || strpos($tok, ' ') !== false) {
        fwrite(STDERR, "TOKEN FALHOU (gcloud application-default indisponivel)\n");
        exit(1);
    }
    file_put_contents($cache, json_encode(array('access_token' => $tok)));
    chmod($cache, 0600);
    return $tok;
}

function api($token, $method, $path, $body = null)
{
    $ch = curl_init('https://storage.googleapis.com' . $path);
    $h  = array('Authorization: Bearer ' . $token);
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => 60,
    ));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return array($raw === false ? null : json_decode($raw, true), $err);
}

$token  = gcs_token();
$prefix = rawurlencode($config['prefix']);
$pageToken = '';
$todo = array();
$totalBytes = 0;
$scanned = 0;

do {
    $path = "/storage/v1/b/{$config['bucket']}/o?maxResults=1000&prefix={$prefix}&fields=items(name,size,cacheControl),nextPageToken";
    if ($pageToken !== '') {
        $path .= '&pageToken=' . rawurlencode($pageToken);
    }
    list($r, $err) = api($token, 'GET', $path);
    if ($err !== '' || !is_array($r) || isset($r['error'])) {
        fwrite(STDERR, 'LIST FALHOU: ' . ($err ?: $r['error']['message']) . "\n");
        exit(1);
    }
    foreach ($r['items'] as $o) {
        $scanned++;
        if (($o['cacheControl'] ?? '') === $config['old']) {
            $todo[] = $o['name'];
            $totalBytes += (int)$o['size'];
        }
    }
    $pageToken = $r['nextPageToken'] ?? '';
} while ($pageToken !== '');

printf("Bucket:      %s\n", $config['bucket']);
printf("Prefixo:     %s\n", $config['prefix']);
printf("Objetos:     %d escaneados, %d com '%s'\n", $scanned, count($todo), $config['old']);
printf("Bytes:       %.2f GB\n\n", $totalBytes / 1073741824);

if (!$todo) {
    echo "Nada a corrigir.\n";
    exit(0);
}

if ($config['dryRun']) {
    echo "DRY-RUN (use --apply para executar):\n";
    foreach (array_slice($todo, 0, 10) as $n) {
        echo "  - $n\n";
    }
    if (count($todo) > 10) {
        echo '  ... e mais ' . (count($todo) - 10) . "\n";
    }
    exit(0);
}

$ok = 0;
$fail = array();
foreach ($todo as $i => $name) {
    $enc = rawurlencode($name);
    list($r, $err) = api($token, 'PATCH', "/storage/v1/b/{$config['bucket']}/o/{$enc}", array(
        'cacheControl' => $config['new'],
    ));
    if ($err !== '' || !is_array($r) || isset($r['error'])) {
        $fail[] = $name . ' :: ' . ($err ?: $r['error']['message']);
    } else {
        $ok++;
        // Confere o dado: metadata-only não pode mudar tamanho nem hash.
        if ($r['cacheControl'] !== $config['new']) {
            $fail[] = $name . ' :: cacheControl nao aplicado (' . ($r['cacheControl'] ?? '?') . ')';
        }
    }
    if (($i + 1) % 50 === 0) {
        printf("  %d/%d ok, %d falhas\n", $ok, count($todo), count($fail));
    }
}

printf("\nOK: %d/%d\n", $ok, count($todo));
if ($fail) {
    echo "FALHAS:\n";
    foreach ($fail as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "Concluido.\n";