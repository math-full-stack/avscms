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
 *   {"enabled":1,"opacity":60,"size":10,"margin":12,
 *    "positions":[{"pos":"top-right","dur":5},{"pos":"bottom-left","dur":5}]}
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
        'size'      => max(1, min(50, intval(isset($dec['size']) ? $dec['size'] : 10))),
        'margin'    => max(0, min(200, intval(isset($dec['margin']) ? $dec['margin'] : 12))),
        'positions' => $positions,
    );
}

/**
 * Per-video watermark config (cached per process).
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
        $rs = $conn->Execute("SELECT watermark_cfg FROM video WHERE VID = " . $vid . " LIMIT 1");
        if ($rs && !$rs->EOF) {
            $raw = trim((string)$rs->fields['watermark_cfg']);
            if ($raw !== '') {
                $dec = json_decode($raw, true);
                $cfg = wm_normalize($dec);
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
 * True when the watermark must force a re-encode (copyonly cannot burn it).
 * @param array|null $cfg
 * @return bool
 */
function wm_force_reencode($cfg) {
    return $cfg !== null;
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

    list($ow, $oh) = wm_out_dims(
        isset($video_info['width']) ? $video_info['width'] : 0,
        isset($video_info['height']) ? $video_info['height'] : 0,
        $e
    );

    $logoW   = max(16, intval($ow * $cfg['size'] / 100));
    if ($logoW % 2) $logoW++;

    $opacity = number_format($cfg['opacity'] / 100, 2, '.', '');
    $margin  = intval($cfg['margin']);

    $bg = ($scaleInner !== '')
        ? '[0:v]' . $scaleInner . ',setsar=1[bg]'
        : '[0:v]setsar=1[bg]';
    $graph = $bg . ';[1:v]scale=w=' . $logoW . ':h=-1,format=rgba,colorchannelmixer=aa=' . $opacity . '[wm];';

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