<?php
/**
 * Transcrição automática (faster-whisper) — cron.
 *
 * Usage:  * * * * * php /path/to/avs/scripts/transcribe_cron.php
 *
 * Orquestra o pipeline em background (1 job ativo por vez):
 *  1. Baixa a fonte h264 do GCS  (gcs_download_h264_source, function_server.php)
 *  2. Extrai o áudio 16 kHz mono (ffmpeg)
 *  3. Dispara scripts/transcribe_worker.py em background
 *  4. Nos ticks seguintes acompanha o progresso e, ao terminar, gera
 *     SRT/VTT/TXT, persiste em `subtitles` (UNIQUE VID+lang) e encerra o job.
 *
 * Separacao executor x capacidade do host (idem ao fix da fila de conversão):
 *  - transcribe_executor ('server'|'local') = onde a transcrição DEVE rodar,
 *    escolhido no siteadmin (Settings -> Media, bloco Transcription Engine).
 *  - worker_role ('web' na VM, 'converter' no PC) = o que ESTE host é.
 *  - Só processa quando executor casa com role. Um cron que rode acidentalmente
 *    na VM com executor='local' aborta ANTES de reivindicar qualquer job —
 *    o simples fato de o cron existir no host não dá autorização a ele.
 *
 * Fail-closed: transcribe_enabled != '1', executor não reconhecido ou venv
 * ausente -> sai com código 0 (não derruba cron, não reivindica job).
 */

define('_VALID', 1);
define('_CLI', true);
define('_ENTER', true);
define('_CONSOLE', true); // CLI: skip web sessions

$basedir = dirname(dirname(__FILE__));
require_once $basedir . '/include/config.php';
require_once $basedir . '/include/function_server.php';

@set_time_limit(0);
@ini_set('max_execution_time', 0);
@ini_set('memory_limit', '512M');

function transcribe_log($msg)
{
    global $config;
    @mkdir($config['LOG_DIR'], 0755, true);
    @file_put_contents($config['LOG_DIR'] . '/transcribe.log', '['.date('Y-m-d H:i:s').'] '.$msg."\n", FILE_APPEND);
}

$stamp = '['.date('Y-m-d H:i:s').']';

// ---------------------------------------------------------------
// Gates (fail-closed) — nesta ordem, antes de QUALQUER reivindicação.
// ---------------------------------------------------------------

// 1. Master switch
if (!isset($config['transcribe_enabled']) || $config['transcribe_enabled'] != '1') {
    echo "$stamp transcribe_enabled=0 — nada a fazer.\n";
    exit(0);
}

// 2. Host capability gate
$executor = isset($config['transcribe_executor']) ? $config['transcribe_executor'] : 'server';
$role     = isset($config['worker_role'])        ? $config['worker_role']        : 'web';
$canProcess = false;
if ($executor === 'server') {
    $canProcess = ($role === 'web');       // VM
} elseif ($executor === 'local') {
    $canProcess = ($role === 'converter'); // PC local
}
if (!$canProcess) {
    echo "$stamp executor='$executor' + worker_role='$role' — este host não processa transcrições.\n";
    exit(0);
}

// 3. Venv Python presente
$python = isset($config['transcribe_python']) ? $config['transcribe_python'] : '/etc/avscms/whisper-venv/bin/python';
if (!is_file($python) || !is_executable($python)) {
    echo "$stamp python do venv ausente: $python (ver header de scripts/transcribe_worker.py).\n";
    exit(0);
}

// 4. Ambiente do modelo fora do checkout (imune ao rsync --delete)
$cacheDir = isset($config['transcribe_cache_dir']) ? $config['transcribe_cache_dir'] : '/etc/avscms/whisper-models';
@mkdir($cacheDir, 0755, true);
putenv('HF_HOME=' . $cacheDir);
putenv('XDG_CACHE_HOME=' . $cacheDir);

// 5. Single-instance guard
$lockFile = $config['LOG_DIR'] . '/transcribe.lock';
@mkdir(dirname($lockFile), 0755, true);
$lockH = @fopen($lockFile, 'c');
if ($lockH && !flock($lockH, LOCK_EX | LOCK_NB)) {
    echo "$stamp Outra instância de transcribe_cron rodando — skip.\n";
    exit(0);
}

$model  = isset($config['transcribe_model'])  ? $config['transcribe_model']  : 'small';
$lang   = isset($config['transcribe_lang'])   ? $config['transcribe_lang']   : 'auto';
$maxDur = intval(isset($config['transcribe_max_duration']) ? $config['transcribe_max_duration'] : 0);
$timeout= intval(isset($config['transcribe_job_timeout']) ? $config['transcribe_job_timeout'] : 3600);
$maxAtt = intval(isset($config['transcribe_max_attempts']) ? $config['transcribe_max_attempts'] : 3);

/**
 * Marca o job como reprocessável (pending) ou failed, conforme attempts,
 * e limpa o diretório de trabalho.
 */
function transcribe_fail_attempt($job, $msg)
{
    global $conn, $config, $maxAtt;

    $vid = intval($job['VID']);
    $currentAttempt = intval($job['attempts']); // 1..N (incrementado no claim)
    transcribe_log("Job #{$job['id']} (VID $vid) tentativa $currentAttempt/$maxAtt falhou: $msg");
    echo '['.date('Y-m-d H:i:s')."] Job #{$job['id']} (VID $vid) falhou: $msg\n";

    $error = $conn->qStr(substr($msg, 0, 500));
    if ($currentAttempt >= $maxAtt) {
        $conn->execute("UPDATE transcription_jobs
                        SET status='failed', error=$error, started_at=0, finished_at=".time()."
                        WHERE id=".intval($job['id'])." LIMIT 1");
    } else {
        $conn->execute("UPDATE transcription_jobs
                        SET status='pending', error=$error, started_at=0
                        WHERE id=".intval($job['id'])." LIMIT 1");
    }

    transcribe_cleanup_workdir($vid);
}

/**
 * Remove o diretório de trabalho do vídeo (scratch temporário).
 */
function transcribe_cleanup_workdir($vid)
{
    global $config;
    $dir = $config['TMP_DIR'] . '/transcribe/' . intval($vid);
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($dir);
    }
}

/**
 * Converte segundos para o formato de legenda.
 * $separator = ',' (SRT) ou '.' (VTT).
 */
function transcribe_clock($secs, $separator = ',')
{
    $secs = max(0, floatval($secs));
    $h = floor($secs / 3600);
    $m = floor(($secs - $h * 3600) / 60);
    $s = floor($secs - $h * 3600 - $m * 60);
    $ms = round(($secs - floor($secs)) * 1000);
    if ($ms >= 1000) { $ms = 0; $s++; }
    if ($s >= 60) { $s = 0; $m++; }
    if ($m >= 60) { $m = 0; $h++; }
    return sprintf('%02d:%02d:%02d%s%03d', $h, $m, $s, $separator, $ms);
}

/**
 * Monta TXT, SRT e VTT a partir dos segmentos do JSON do worker.
 * Prefixo [Speaker] só quando houver mais de um falante distinto (hoje a
 * transcrição é de falante único fixo "Palestrante 1").
 */
function transcribe_formats($data)
{
    $segments = (isset($data['segments']) && is_array($data['segments'])) ? $data['segments'] : array();
    $speakers = (isset($data['speakers']) && is_array($data['speakers'])) ? $data['speakers'] : array();
    $prefix = (count(array_unique($speakers)) > 1);

    $txt = array();
    $srt = array();
    $vtt = array("WEBVTT", "");

    $i = 0;
    foreach ($segments as $seg) {
        $i++;
        $start = floatval($seg['start']);
        $end   = floatval($seg['end']);
        $text  = trim((string)$seg['text']);

        if ($prefix && !empty($seg['speaker'])) {
            $text = '[' . $seg['speaker'] . '] ' . $text;
        }

        $txt[] = $text;

        $srt[] = (string)$i;
        $srt[] = transcribe_clock($start, ',') . ' --> ' . transcribe_clock($end, ',');
        $srt[] = $text;
        $srt[] = '';

        $vtt[] = (string)$i;
        $vtt[] = transcribe_clock($start, '.') . ' --> ' . transcribe_clock($end, '.');
        $vtt[] = $text;
        $vtt[] = '';
    }

    return array(
        'txt' => implode("\n", $txt),
        'srt' => implode("\n", $srt),
        'vtt' => implode("\n", $vtt),
    );
}

/**
 * Traduz o resultado do worker (result.json presente) em linha(s) de `subtitles`
 * e encerra o job como done. Remove o scratch.
 */
function transcribe_finalize($job)
{
    global $conn, $config;

    $vid = intval($job['VID']);
    $dir = $config['TMP_DIR'] . '/transcribe/' . $vid;
    $jsonPath = $dir . '/result.json';

    if (!is_file($jsonPath)) {
        transcribe_fail_attempt($job, 'worker terminou sem result.json (resultado perdido?)');
        return;
    }

    $raw = @file_get_contents($jsonPath);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        transcribe_fail_attempt($job, 'result.json ilegível (JSON inválido)');
        return;
    }

    $formats = transcribe_formats($data);
    $lang    = isset($data['language']) ? substr(preg_replace('/[^a-zA-Z0-9\-_]/', '', (string)$data['language']), 0, 16) : 'auto';
    if ($lang === '') { $lang = 'auto'; }

    $wordsJson = json_encode(isset($data['words']) ? $data['words'] : array(), JSON_UNESCAPED_UNICODE);
    $speakers  = isset($data['speakers']) ? $data['speakers'] : array();
    if (empty($speakers) && !empty($data['segments'])) {
        $speakers = array('Palestrante 1');
    }
    $speakersJson = json_encode(array_values($speakers), JSON_UNESCAPED_UNICODE);

    $sql = "INSERT INTO subtitles (VID, lang, provider, srt, vtt, txt, words_json, speakers_json, enabled, created_at)
            VALUES (".$vid.", ".$conn->qStr($lang).", 'faster-whisper',
                    ".$conn->qStr($formats['srt']).", ".$conn->qStr($formats['vtt']).",
                    ".$conn->qStr($formats['txt']).", ".$conn->qStr($wordsJson).",
                    ".$conn->qStr($speakersJson).", 1, ".time().")
            ON DUPLICATE KEY UPDATE
                srt = VALUES(srt), vtt = VALUES(vtt), txt = VALUES(txt),
                words_json = VALUES(words_json), speakers_json = VALUES(speakers_json),
                enabled = 1";
    $conn->execute($sql);

    $conn->execute("UPDATE transcription_jobs
                    SET status='done', error=NULL, progress=100.00, language=".$conn->qStr($lang).",
                        finished_at=".time().", started_at=0
                    WHERE id=".intval($job['id'])." LIMIT 1");

    transcribe_log("Job #{$job['id']} (VID $vid) concluído — legenda '".$lang."' com ".
                   count($data['segments'])." segmentos / ".count($data['words'])." palavras.");
    echo '['.date('Y-m-d H:i:s')."] Job #{$job['id']} (VID $vid) concluído (lang=$lang).\n";

    // Preserva o .htaccess de proteção (regravado no claim) — cleanup libera o resto.
    @unlink($dir . '/source.mp4');
    @unlink($dir . '/audio.wav');
    @unlink($dir . '/result.json');
    @unlink($dir . '/progress.json');
}

// ============================================================
// Fase A — acompanhar jobs em 'processing' (progresso / fim / erro)
// ============================================================
$rs = $conn->execute("SELECT id, VID, attempts FROM transcription_jobs WHERE status='processing' ORDER BY id ASC");
$processing = ($rs && $conn->Affected_Rows() > 0) ? $rs->getrows() : array();

$hasActive = false;
foreach ($processing as $job) {
    $vid = intval($job['VID']);
    $dir = $config['TMP_DIR'] . '/transcribe/' . $vid;
    $progressFile = $dir . '/progress.json';

    if (!is_file($progressFile)) {
        // Worker ainda carregando o modelo / o primeiro progresso não chegou.
        $hasActive = true;
        continue;
    }

    $pdata = @json_decode(@file_get_contents($progressFile), true);
    $state = isset($pdata['state']) ? $pdata['state'] : '';

    if ($state === 'done') {
        transcribe_finalize($job);
    } elseif ($state === 'error') {
        $msg = isset($pdata['error']) && $pdata['error'] !== '' ? $pdata['error'] : 'erro desconhecido no worker';
        transcribe_fail_attempt($job, $msg);
    } else {
        $hasActive = true;
        $progress = isset($pdata['progress']) ? floatval($pdata['progress']) : 0;
        $conn->execute("UPDATE transcription_jobs SET progress=".round(min(99.99, max(0, $progress)), 2).
                       " WHERE id=".intval($job['id'])." AND status='processing' LIMIT 1");
    }
}

if ($hasActive) {
    echo "$stamp Transcrição em andamento — 1 job ativo no máximo (CPU).\n";
    exit(0);
}

// ---------------------------------------------------------------
// Recuperação de jobs órfãos (cron morto no meio do processamento).
// Reset para pending; o watchdog de attempts decide a falha definitiva.
// ---------------------------------------------------------------
if ($timeout > 0) {
    $conn->execute("UPDATE transcription_jobs
                    SET status='pending', started_at=0
                    WHERE status='processing' AND started_at > 0
                      AND started_at < ".(time() - $timeout));
}

// ============================================================
// Fase B — reivindicar e disparar o próximo job pendente
// ============================================================
$rs = $conn->execute("SELECT * FROM transcription_jobs WHERE status='pending' ORDER BY id ASC LIMIT 1");
if (!$rs || $conn->Affected_Rows() != 1) {
    echo "$stamp Nenhum job pendente.\n";
    exit(0);
}
$job = $rs->fields;

// Claim atômico: quem conseguir setar processing primeiro processa (no caso de
// dois hosts com o mesmo executor por engano, só um vence).
$conn->execute("UPDATE transcription_jobs
                SET status='processing', started_at=".time().", attempts=attempts+1, progress=0
                WHERE id=".intval($job['id'])." AND status='pending' LIMIT 1");
if ($conn->Affected_Rows() != 1) {
    echo "$stamp Job #{$job['id']} reivindicado por outro processo/host — skip.\n";
    exit(0);
}

$vid   = intval($job['VID']);
$trDir = $config['TMP_DIR'] . '/transcribe/' . $vid;
@mkdir($trDir, 0755, true);
if (!is_dir($trDir)) {
    transcribe_fail_attempt($job, 'não foi possível criar o diretório de trabalho');
    exit(0);
}
// Bloqueia acesso web ao scratch (source.mp4/wav/json) enquanto existir.
file_put_contents($trDir . '/.htaccess', "Require all denied\nDeny from all\n");

$src  = $trDir . '/source.mp4';
$wav  = $trDir . '/audio.wav';
$jsonPath = $trDir . '/result.json';
$progPath = $trDir . '/progress.json';
$logPath  = $config['LOG_DIR'] . '/transcribe_' . $vid . '.log';

// 1. Fonte — valida o vídeo e baixa o h264 do GCS.
$vrs = $conn->execute("SELECT title, duration, active FROM video WHERE VID=".$vid." LIMIT 1");
if (!$vrs || $conn->Affected_Rows() != 1) {
    transcribe_fail_attempt($job, "vídeo $vid não existe");
    exit(0);
}
$video = $vrs->fields;
if ($video['active'] == '0') {
    transcribe_fail_attempt($job, "vídeo $vid está suspenso");
    exit(0);
}
if ($maxDur > 0 && floatval($video['duration']) > $maxDur) {
    transcribe_fail_attempt($job, 'vídeo muito longo para transcrição ('.round($video['duration'], 1).
                               's > limite '.$maxDur.'s)');
    exit(0);
}

if (!gcs_download_h264_source($vid, $src)) {
    transcribe_fail_attempt($job, 'download da fonte h264 do GCS falhou (sem server/formats no vídeo?)');
    exit(0);
}

// 2. Áudio 16 kHz mono (suporta MP4/MKV/MP3 etc. — o ffmpeg normaliza).
$ffmpeg = isset($config['ffmpeg']) ? $config['ffmpeg'] : 'ffmpeg';
$cmd = escapeshellarg($ffmpeg) . ' -y -i ' . escapeshellarg($src)
     . ' -vn -ac 1 -ar 16000 -f wav ' . escapeshellarg($wav) . ' 2>&1';
exec($cmd, $ffOut, $ffRc);
if ($ffRc !== 0 || !is_file($wav) || filesize($wav) <= 0) {
    transcribe_fail_attempt($job, 'extração de áudio falhou (arquivo sem faixa de áudio? ffmpeg rc='.$ffRc.')');
    exit(0);
}

// Duração (para o progresso do worker).
$duration = 0.0;
$ffprobe = isset($config['ffprobe']) ? $config['ffprobe'] : 'ffprobe';
exec(escapeshellarg($ffprobe) . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' .
     escapeshellarg($wav) . ' 2>&1', $durOut, $durRc);
if ($durRc === 0 && isset($durOut[0]) && is_numeric(trim($durOut[0]))) {
    $duration = floatval(trim($durOut[0]));
}

// 3. Worker em background (envs HF_HOME/XDG_CACHE_HOME já apontam p/ cacheDir).
$worker = $basedir . '/scripts/transcribe_worker.py';
$bg = escapeshellarg($python) . ' ' . escapeshellarg($worker)
    . ' --audio ' . escapeshellarg($wav)
    . ' --model ' . escapeshellarg($model)
    . ' --lang ' . escapeshellarg($lang)
    . ' --duration ' . escapeshellarg((string)$duration)
    . ' --out-json ' . escapeshellarg($jsonPath)
    . ' --progress ' . escapeshellarg($progPath);
exec($bg . ' > ' . escapeshellarg($logPath) . ' 2>&1 & echo $!', $spawnOut, $spawnRc);
$pid = isset($spawnOut[0]) ? trim($spawnOut[0]) : '';

transcribe_log("Job #{$job['id']} (VID $vid) lançado em background (pid=$pid, modelo='$model', ang='".time()."')");
echo "$stamp Job #{$job['id']} (VID $vid) lançado em background (pid=$pid).\n";
exit(0);