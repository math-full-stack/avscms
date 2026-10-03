<?php
/**
 * Gen Brand Assets — regenera ativos de marca adulto.cloud a partir da arte
 *-fonte images/logo/logo.png (wordmark com alpha limpo).
 *
 * Uso:
 *   php scripts/gen_brand_assets.php            # gera tudo
 *   php scripts/gen_brand_assets.php admin      # só o logo do siteadmin
 *
 * Hoje: admin logo (templates/backend/default/assets/img/logo{,2x}.png).
 * - Recorta o bbox do wordmark (alpha opaco).
 * - Recolor: vermelho ".cloud" preservado; wordmark clara -> grafite (o
 *   header do admin é branco, o logo original era branco/invisível).
 * - Fit em 117x24 (attr do header.tpl) + versão 2x; downscale progressivo
 *   (metades sucessivas) em vez de um único imagecopyresampled.
 */
defined('_VALID') or define('_VALID', true);

$root = dirname(__DIR__);
$src  = $root . '/images/logo/logo.png';

if (!is_file($src)) {
    fwrite(STDERR, "fonte nao encontrada: $src\n");
    exit(1);
}

$what = isset($argv[1]) ? $argv[1] : 'all';

$im = @imagecreatefrompng($src);
if (!$im) {
    fwrite(STDERR, "falha ao ler $src\n");
    exit(1);
}
$w = imagesx($im);
$h = imagesy($im);

// 1) bbox de pixels visíveis (GD: alpha 0=opaco .. 127=transparente)
$minx = $w; $miny = $h; $maxx = -1; $maxy = -1;
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $a = (imagecolorat($im, $x, $y) >> 24) & 0x7F;
        if ($a > 40) continue;
        if ($x < $minx) $minx = $x;
        if ($x > $maxx) $maxx = $x;
        if ($y < $miny) $miny = $y;
        if ($y > $maxy) $maxy = $y;
    }
}
if ($maxx < 0) {
    fwrite(STDERR, "arte sem pixels visiveis\n");
    exit(1);
}
printf("bbox: %d,%d -> %d,%d (%dx%d)\n", $minx, $miny, $maxx, $maxy, $maxx - $minx + 1, $maxy - $miny + 1);

// 2) crop + recolor (admin: grafite no lugar da wordmark clara)
$cw = $maxx - $minx + 1;
$ch = $maxy - $miny + 1;
$crop = imagecreatetruecolor($cw, $ch);
imagealphablending($crop, false);
imagesavealpha($crop, true);
$transparent = imagecolorallocatealpha($crop, 0, 0, 0, 127);
imagefill($crop, 0, 0, $transparent);
imagealphablending($crop, true);
imagecopy($crop, $im, 0, 0, $minx, $miny, $cw, $ch);

for ($y = 0; $y < $ch; $y++) {
    for ($x = 0; $x < $cw; $x++) {
        $c = imagecolorat($crop, $x, $y);
        $a = ($c >> 24) & 0x7F;
        if ($a >= 120) continue;
        $r = ($c >> 16) & 0xFF;
        $g = ($c >> 8) & 0xFF;
        $b = $c & 0xFF;
        $isRed = ($r > 140 && $r > $g * 1.6 && $r > $b * 1.6);
        if ($isRed) continue; // ".cloud" mantem a cor da marca
        // wordmark clara -> grafite; luminancia original vira o gradiente
        $lum = (int) ((0.299 * $r + 0.587 * $g + 0.114 * $b) / 255);
        // mapeia luminancia p/ faixa grafite #16181B..#4A4E55 (invertido:
        // partes claras do brilho ficam mais escuras e estaveis no branco)
        $t = 1.0 - $lum;
        $nr = (int) (0x16 + $t * (0x4A - 0x16));
        $ng = (int) (0x18 + $t * (0x4E - 0x18));
        $nb = (int) (0x1B + $t * (0x55 - 0x1B));
        imagesetpixel($crop, $x, $y, imagecolorallocatealpha($crop, $nr, $ng, $nb, $a));
    }
}

// 3) fit dentro de WxH preservando aspecto (letterbox vertical centralizado)
function fit_in($src, $tw, $th) {
    $sw = imagesx($src);
    $sh = imagesy($src);
    $scale = min($tw / $sw, $th / $sh);
    $rw = max(1, (int) round($sw * $scale));
    $rh = max(1, (int) round($sh * $scale));
    // downscale progressivo (metades) p/ filtragem melhor que um unico salto
    $cur = $src;
    $cw = $sw; $chh = $sh;
    while ($cw / 2 > $rw) {
        $nw = max($rw, (int) floor($cw / 2));
        $nh = max(1, (int) round($chh * ($nw / $cw)));
        $half = imagecreatetruecolor($nw, $nh);
        imagealphablending($half, false);
        imagesavealpha($half, true);
        imagefill($half, 0, 0, imagecolorallocatealpha($half, 0, 0, 0, 127));
        imagealphablending($half, true);
        imagecopyresampled($half, $cur, 0, 0, 0, 0, $nw, $nh, $cw, $chh);
        $cur = $half;
        $cw = $nw; $chh = $nh;
    }
    $out = imagecreatetruecolor($tw, $th);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagealphablending($out, true);
    imagecopyresampled($out, $cur, (int) (($tw - $rw) / 2), (int) (($th - $rh) / 2),
        0, 0, $rw, $rh, imagesx($cur), imagesy($cur));
    return $out;
}

if ($what === 'all' || $what === 'admin') {
    $dst1 = $root . '/templates/backend/default/assets/img/logo.png';
    $dst2 = $root . '/templates/backend/default/assets/img/logo2x.png';
    $a1 = fit_in($crop, 117, 24);
    imagepng($a1, $dst1);
    $a2 = fit_in($crop, 234, 48);
    imagepng($a2, $dst2);
    echo "ok: $dst1 (117x24)\n";
    echo "ok: $dst2 (234x48)\n";
}
