<?php
defined('_VALID') or die('Restricted Access!');

/**
 * Video watermark helpers — burns a logo into every converted format.
 *
 * The per-video profile lives in video.watermark_cfg (JSON), copied from the
 * mass-grabber source profile (grabber_sources.watermark_config) at grab time.
 * Videos without a profile get NO watermark, keeping the original ffmpeg
 * commands byte-for-byte identical.
 *
 * Profile JSON:
 *   {"enabled":1,"opacity":60,"size":120,"margin":12,
 *    "positions":[{"pos":"top-right","dur":5},{"pos":"bottom-left","dur":5}]}
 *   - size: logo WIDTH in pixels, FIXED — independent of the video/output
 *     resolution. Height follows automatically (-2), so the logo keeps its
 *     aspect ratio and even dimensions at any size.
 *   - One position row (any dur)           -> fixed position for the whole video
 *   - Several rows with dur > 0            -> alternates every `dur` seconds, looping
 *   - A row with dur = 0 (multi-row list)  -> stays there until the end of the cycle
 *
 * Notes:
 *   - FFmpeg 9 removed the `fps` option of the overlay filter; the generated
 *     graph never uses it (each overlay gets an explicit enable= window).
 *   - The logo scales relative to each output width (even-dimension rounding)
 *     and ends with format=yuv420p for H.264 profile compatibility.
 */

/**
 * Valid overlay positions.
 * @return array
 */
function wm_valid_positions() {
    return array('top-left', 'top-right', 'bottom-left', 'bottom-right', 'center');
}

/**
 * Normalize + validate a decoded profile.
 * @param mixed $dec decoded JSON
 * @return array|null normalized config, or null when disabled/invalid
 */
function wm_normalize($dec) {
    if (!is_array($dec) || empty($dec['enabled'])) return null;

    $positions = array();
    if (!empty($dec['positions']) && is_array($dec['positions'])) {
        foreach ($dec['positions'] as $row) {
            if (!is_array($row)) continue;
            $pos = isset($row['pos']) ? trim((string)$row['pos']) : '';
            if (!in_array($pos, wm_valid_positions(), true)) continue;
            $dur = max(0, intval(isset($row['dur']) ? $row['dur'] : 0));
            $positions[] = array('pos' => $pos, 'dur' => $dur);
        }
    }
    if (empty($positions)) return null;

    $cycle = 0;
    foreach ($positions as $p) $cycle += $p['dur'];
    if (count($positions) > 1 && $cycle <= 0) return null; // multi-position without timing is meaningless

    return array(
        'opacity'   => max(0, min(100, intval(isset($dec['opacity']) ? $dec['opacity'] : 60))),
        // size = logo width in PIXELS (fixed, independent of video resolution)
        'size'      => max(8, min(4096, intval(isset($dec['size']) ? $dec['size'] : 120))),
        'margin'    => max(0, min(200, intval(isset($dec['margin']) ? $dec['margin'] : 12))),
        'positions' => $positions,
    );
}

/**
 * Per-video watermark config (cached per process).
 *
 * Resolution order:
 *   1. video.watermark_cfg — the profile frozen into the row when the mass
 *      grabber created it (source edits never retro-change already-grabbed
 *      videos that carry a snapshot).
 *   2. grabber source by source_url domain — fallback so ANY process that
 *      converts a video tied to a watermark-configured grabber source burns
 *      the logo: single-URL grabs, videos grabbed before the snapshot existed,
 *      and admin reprocesses all resolve the source here.
 *
 * @param int $vid
 * @return array|null
 */
function wm_video_config($vid) {
    global $conn;
    static $cache = array();
    $vid = intval($vid);
    if ($vid <= 0) return null;
    if (array_key_exists($vid, $cache)) return $cache[$vid];

    $cfg = null;
    try {
        $rs = $conn->Execute("SELECT source_url, watermark_cfg FROM video WHERE VID = " . $vid . " LIMIT 1");
        if ($rs && !$rs->EOF) {
            $raw = trim((string)$rs->fields['watermark_cfg']);
            if ($raw !== '') {
                $dec = json_decode($raw, true);
                $cfg = wm_normalize($dec);
            }
            if ($cfg === null) {
                $cfg = wm_source_config_for_url(isset($rs->fields['source_url']) ? (string)$rs->fields['source_url'] : '');
            }
        }
    } catch (Exception $e) {
        $cfg = null;
    } catch (Throwable $e) {
        $cfg = null;
    }
    $cache[$vid] = $cfg;
    return $cfg;
}

/**
 * Resolve the watermark profile of the grabber source whose domain matches the
 * video's source URL (www. prefix ignored, one-level subdomains allowed).
 * @param string $url
 * @return array|null
 */
function wm_source_config_for_url($url) {
    global $conn;
    static $cache = array();

    $host = strtolower(trim((string)parse_url(trim($url), PHP_URL_HOST)));
    if ($host === '') return null;
    if (array_key_exists($host, $cache)) return $cache[$host];

    $plain = preg_replace('/^www\./', '', $host);

    $cfg = null;
    try {
        $rs = $conn->Execute(
            "SELECT watermark_config FROM grabber_sources
              WHERE watermark_config != ''
                AND (REPLACE(domain, 'www.', '') = " . $conn->qStr($plain) . "
                     OR domain LIKE " . $conn->qStr('%.' . $plain) . ")
              ORDER BY updated_at DESC LIMIT 1"
        );
        if ($rs && !$rs->EOF) {
            $raw = trim((string)$rs->fields['watermark_config']);
            if ($raw !== '') {
                $cfg = wm_normalize(json_decode($raw, true));
            }
        }
    } catch (Exception $e) {
        $cfg = null;
    } catch (Throwable $e) {
        $cfg = null;
    }
    $cache[$host] = $cfg;
    return $cfg;
}

/**
 * True when the watermark must force a re-encode (copyonly cannot burn it).
 * @param array|null $cfg
 * @return bool
 */
function wm_force_reencode($cfg) {
    return $cfg !== null;
}

/**
 * Refresh the frozen per-video snapshot (video.watermark_cfg/cut/cut_out) from
 * the CURRENT grabber source config, resolving the source by the video's URL
 * domain (same matching used by wm_source_config_for_url).
 *
 * grabber_cron() snapshots the source config when a job creates/reuses the
 * video row, but the admin reprocess paths spawn grabber_worker.php directly
 * and the worker never refreshed the snapshot — so editing the source (margin,
 * size, positions, cut) had no effect on already-grabbed videos that carry an
 * old enabled snapshot. Call this on every (re)grab so reprocess = re-apply
 * the current source config.
 *
 * @param int    $vid
 * @param string $sourceUrl
 * @return bool true when a matching source was found and the row was updated
 */
function wm_refresh_video_snapshot($vid, $sourceUrl)
{
    global $conn;
    $vid = intval($vid);
    if ($vid <= 0) {
        return false;
    }

    $host = strtolower(trim((string)parse_url(trim((string)$sourceUrl), PHP_URL_HOST)));
    if ($host === '') {
        return false;
    }
    $plain = preg_replace('/^www\./', '', $host);

    try {
        $rs = $conn->Execute(
            "SELECT watermark_config, cut_in, cut_out FROM grabber_sources
              WHERE (REPLACE(domain, 'www.', '') = " . $conn->qStr($plain) . "
                     OR domain LIKE " . $conn->qStr('%.' . $plain) . ")
              ORDER BY updated_at DESC LIMIT 1"
        );
        if (!$rs || $rs->EOF) {
            return false;
        }

        $wmRaw = trim((string)$rs->fields['watermark_config']);
        $cutIn  = intval(isset($rs->fields['cut_in'])  ? $rs->fields['cut_in']  : 0);
        $cutOut = intval(isset($rs->fields['cut_out']) ? $rs->fields['cut_out'] : 0);

        $conn->Execute(
            "UPDATE video SET
                watermark_cfg = " . $conn->qStr($wmRaw) . ",
                cut = " . $conn->qStr($cutIn) . ",
                cut_out = " . intval($cutOut) . "
              WHERE VID = " . intval($vid) . " LIMIT 1"
        );
        return true;
    } catch (Exception $e) {
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Resolve the logo file path (config watermark_image, default player logo).
 * @return string
 */
function wm_logo_path() {
    global $config;
    $path = !empty($config['watermark_image']) ? trim($config['watermark_image']) : 'media/player/logo/logo.png';
    if ($path === '') $path = 'media/player/logo/logo.png';
    if (!preg_match('#^(/|' . preg_quote($config['BASE_DIR'], '#') . ')#', $path)) {
        $path = $config['BASE_DIR'] . '/' . ltrim($path, '/');
    }
    return $path;
}

/**
 * Overlay x/y expressions for a position + margin.
 * @param string $pos
 * @param int    $margin
 * @return array [x, y]
 */
function wm_xy($pos, $margin) {
    switch ($pos) {
        case 'top-left':    return array($margin, $margin);
        case 'top-right':   return array('main_w-overlay_w-' . $margin, $margin);
        case 'bottom-left': return array($margin, 'main_h-overlay_h-' . $margin);
        case 'center':      return array('(main_w-overlay_w)/2', '(main_h-overlay_h)/2');
        case 'bottom-right':
        default:            return array('main_w-overlay_w-' . $margin, 'main_h-overlay_h-' . $margin);
    }
}

/**
 * Expected output frame size for a target encoding row (mirrors the scale
 * expression used by the conversion, with even-dimension rounding).
 * @param int $vw source width
 * @param int $vh source height
 * @param array $e encoding row
 * @return array [w, h]
 */
function wm_out_dims($vw, $vh, $e) {
    $vw = max(2, intval($vw));
    $vh = max(2, intval($vh));
    $w = intval($e['width']);
    $h = intval($e['height']);
    $asp = $vw / $vh;
    if ($asp > 4 / 3) {
        $ow = $w;
        $oh = round($w / $asp);
    } else {
        $oh = $h;
        $ow = round($h * $asp);
    }
    if ($ow % 2) $ow--;
    if ($oh % 2) $oh--;
    return array(max(2, $ow), max(2, $oh));
}

/**
 * Build the ffmpeg args that burn the watermark, or '' when disabled/missing.
 *
 * Returns e.g.:
 *   ' -i "/path/logo.png" -filter_complex "[0:v]scale=...,setsar=1[bg];[1:v]...[wm];[bg][wm]overlay=...[vout]" -map "[vout]" -map 0:a?'
 *
 * @param array|null $cfg        normalized profile (null -> no watermark)
 * @param string     $scaleInner scale filter expression without the -vf prefix
 * @param array      $video_info source probe info (width/height)
 * @param array      $e          encoding row
 * @return string
 */
function wm_build_args($cfg, $scaleInner, $video_info, $e) {
    if ($cfg === null) return '';

    $logo = wm_logo_path();
    if (!file_exists($logo) || !is_file($logo)) {
        echo "\n[Watermark] logo nao encontrado (" . $logo . ") — convertendo sem marca d'agua\n";
        return '';
    }

    // Fixed pixel width — independent of the video/output resolution. Height is
    // derived automatically by the scale filter (-2 = keep aspect AND force an
    // even dimension), so the logo is never distorted and stays yuv420p-safe.
    $logoW = max(8, intval($cfg['size']));
    if ($logoW % 2) $logoW++;

    $opacity = number_format($cfg['opacity'] / 100, 2, '.', '');
    $margin  = intval($cfg['margin']);

    $bg = ($scaleInner !== '')
        ? '[0:v]' . $scaleInner . ',setsar=1[bg]'
        : '[0:v]setsar=1[bg]';
    $graph = $bg . ';[1:v]scale=w=' . $logoW . ':h=-2,format=rgba,colorchannelmixer=aa=' . $opacity . '[wm];';

    $positions = $cfg['positions'];
    $n = count($positions);

    if ($n === 1) {
        // Fixed position for the whole video.
        list($x, $y) = wm_xy($positions[0]['pos'], $margin);
        $xExpr = $x;
        $yExpr = $y;
    } else {
        // Alternation via a SINGLE overlay whose x/y switch over time — FFmpeg 9
        // drops disabled branches of chained overlay+enable, so position is
        // driven by nested time expressions instead (instant switch = same look).
        $cycle = 0;
        foreach ($positions as $p) $cycle += $p['dur'];

        $xs   = array();
        $ys   = array();
        $accs = array();
        $acc  = 0;
        foreach ($positions as $p) {
            list($x, $y) = wm_xy($p['pos'], $margin);
            $xs[]   = $x;
            $ys[]   = $y;
            $acc   += $p['dur'];
            $accs[] = $acc;
        }

        $xExpr = $xs[$n - 1];
        $yExpr = $ys[$n - 1];
        for ($i = $n - 2; $i >= 0; $i--) {
            $cond  = 'lt(mod(t,' . $cycle . '),' . $accs[$i] . ')';
            $xExpr = 'if(' . $cond . ',' . $xs[$i] . ',' . $xExpr . ')';
            $yExpr = 'if(' . $cond . ',' . $ys[$i] . ',' . $yExpr . ')';
        }
    }

    // Single quotes protect the commas inside the x/y expressions from the
    // filtergraph parser (same quoting style as the existing scale expression).
    $graph .= '[bg][wm]overlay=x=\'' . $xExpr . '\':y=\'' . $yExpr . '\',format=yuv420p[vout]';

    return ' -i ' . escapeshellarg($logo)
         . ' -filter_complex "' . $graph . '"'
         . ' -map "[vout]" -map 0:a?';
}