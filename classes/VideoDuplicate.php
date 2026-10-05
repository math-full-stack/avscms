<?php
defined('_VALID') or die('Restricted Access!');

/**
 * VideoDuplicate - impressao digital de conteudo para barrar videos duplicados.
 *
 * O dedup por URL (DedupManager) so pega o MESMO endereco: o mesmo arquivo
 * vindo de um mirror, de outra fonte ou com query string diferente passa
 * direto. Aqui a identidade e o CONTEUDO, nao a URL.
 *
 * Como funciona:
 *   1. 8 frames em cinza 16x16, em timestamps proporcionais a duracao;
 *   2. media dos 8 blocos 8x8 (o que cancela ruido de cena);
 *   3. DCT-II 8x8 e threshold na mediana -> 64 bits (16 hex).
 *   4. Busca no banco os videos com duracao parecida e compara por
 *      distancia de Hamming; abaixo de MAX_DISTANCE e o mesmo conteudo.
 *
 * Por que perceptual e nao md5 do arquivo: a conversao sempre re-encoda
 * (scripts/convert_videos.php) e o grabber pode aplicar marca d'agua, entao
 * os bytes mudam a cada pipeline. Medido no corpus local: re-encode do mesmo
 * arquivo (meia resolucao, 400kbps) da distancia 0; 6 videos distintos ficaram
 * entre 26 e 36 de 64 - o corte em 10 tem folga de sobra.
 *
 * A amostragem acompanha a duracao, entao videos com duracao muito diferente nao
 * casam (mesmo conteudo cortado 2s da frente deu distancia 24). Isso e
 * proposital: a porta para auto-bloqueio e a BlocklistManager, que o admin
 * destrava em um clique na aba Blocklist.
 */
class VideoDuplicate {

    /** Distancia de Hamming maxima para considerar o mesmo conteudo. */
    const MAX_DISTANCE = 10;

    /** Frames por video / minimo aceito antes de desistir. */
    const FRAMES = 8;
    const MIN_FRAMES = 5;

    /** Amostra e comparacao. */
    const SCALE = 16;
    const BLOCK = 8;

    private static $colExists = null;

    /** Contador de nibbles 0-15 (hamming por nibble). */
    private static $nibblePop = array(0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4);

    /**
     * Kill switch: AVS_DUP_PHASH=0 (ou false/no) desliga o bloqueio sem
     * precisar remover a coluna nem reverter codigo.
     */
    public static function enabled() {
        $flag = strtolower(trim((string)getenv('AVS_DUP_PHASH')));
        if ($flag === '') return true;
        return !in_array($flag, array('0', 'false', 'no', 'off'), true);
    }

    /**
     * Impressao digital de um arquivo local, ou '' quando nao da para
     * (arquivo ausente/corrompido, ffmpeg indisponivel, video curto demais).
     * A string devolvida tem 16 chars hex; '' e o sinal de "nao sei", e o
     * chamador deve seguir o fluxo normal sem bloquear.
     *
     * @param string $file     caminho do arquivo de video
     * @param float  $duration duracao em segundos (0 = medir com ffprobe)
     * @return string
     */
    public static function computePhash($file, $duration = 0) {
        global $config;

        if (!function_exists('probe_video_duration')) return '';
        if (empty($file) || !file_exists($file) || filesize($file) < 1024) return '';
        $ffmpeg = isset($config['ffmpeg']) ? $config['ffmpeg'] : '';
        if ($ffmpeg === '' || !file_exists($ffmpeg)) return '';

        $duration = floatval($duration) > 0 ? floatval($duration) : probe_video_duration($file);
        if ($duration <= 1) return '';

        $rawPath = sys_get_temp_dir() . '/avs_phash_' . getmypid() . '.raw';
        $block = self::BLOCK;
        $scale = self::SCALE;
        $sum = array();
        for ($y = 0; $y < $block; $y++) $sum[$y] = array_fill(0, $block, 0.0);

        $got = 0;
        for ($i = 0; $i < self::FRAMES; $i++) {
            $t = $duration * ($i + 0.5) / self::FRAMES;
            $frame = self::fetchFrame($ffmpeg, $file, $t, $rawPath, $scale);
            if ($frame === false) continue;
            for ($y = 0; $y < $block; $y++) {
                for ($x = 0; $x < $block; $x++) {
                    $sum[$y][$x] += ord($frame[$y * $scale + $x]);
                }
            }
            $got++;
        }
        @unlink($rawPath);
        if ($got < self::MIN_FRAMES) return '';

        $mean = array();
        for ($y = 0; $y < $block; $y++) {
            for ($x = 0; $x < $block; $x++) $mean[$y][$x] = $sum[$y][$x] / $got;
        }

        $dct = self::dct2($mean);
        $coef = array();
        for ($l = 0; $l < $block; $l++) {
            for ($k = 0; $k < $block; $k++) $coef[$l * $block + $k] = $dct[$l][$k];
        }

        // Mediana dos 63 coeficientes de baixa frequencia (DC fora: ele carrega
        // so o brilho medio). O bit 63 fica sempre 1 para o hex ter 16 chars;
        // bit constante nao altera a distancia.
        $rest = array();
        for ($i = 1; $i < 64; $i++) $rest[] = $coef[$i];
        sort($rest);
        $median = $rest[(int)(count($rest) / 2)];

        $bits = '';
        for ($i = 1; $i < 64; $i++) $bits .= ($coef[$i] > $median) ? '1' : '0';
        $bits .= '1';

        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $n = 0;
            for ($j = 0; $j < 4; $j++) $n = ($n << 1) | (int) $bits[$i + $j];
            $hex .= dechex($n);
        }
        return $hex;
    }

    /**
     * Procura um video ja publicado com o mesmo conteudo.
     *
     * @param  string $phash      hash do arquivo novo
     * @param  float  $duration   duracao do arquivo novo (segundos)
     * @param  int    $excludeVid VID a ignorar (o proprio video em processamento)
     * @return array  ['vid' => int, 'distance' => int] ou array() quando nao ha
     *                duplicata, o hash e invalido ou a coluna ainda nao existe
     */
    public static function findDuplicate($phash, $duration, $excludeVid = 0) {
        global $conn;

        if (!self::enabled()) return array();
        $phash = strtolower(trim((string)$phash));
        if (strlen($phash) !== 16 || !self::columnExists()) return array();

        $duration = floatval($duration);
        if ($duration <= 0) return array();

        // Mesma fonte sempre gera a mesma duracao; a tolerancia cobre
        // arredondamento de encoder/container, nao cortes diferentes.
        $tol = max(1.0, $duration * 0.005);
        $sql = "SELECT VID, phash, duration FROM video
                WHERE phash <> ''
                AND phash <> " . $conn->qStr($phash) . "
                AND VID <> " . intval($excludeVid) . "
                AND active <> '0'
                AND duration > 0
                AND duration BETWEEN " . ($duration - $tol) . " AND " . ($duration + $tol) . "
                LIMIT 300";

        try { $rs = $conn->Execute($sql); } catch (Exception $e) { return array(); } catch (Throwable $e) { return array(); }
        if (!$rs || $rs->EOF) return array();

        $best = array();
        while (!$rs->EOF) {
            $d = self::hamming($phash, $rs->fields['phash']);
            if ($d <= self::MAX_DISTANCE && (empty($best) || $d < $best['distance'])) {
                $best = array('vid' => intval($rs->fields['VID']), 'distance' => $d);
            }
            $rs->MoveNext();
        }
        return $best;
    }

    /**
     * Distancia de Hamming entre dois hashes hex de 16 chars.
     * @return int 0-64 (64 quando algum dos lados e invalido)
     */
    public static function hamming($a, $b) {
        $a = strtolower(trim((string)$a));
        $b = strtolower(trim((string)$b));
        if (strlen($a) !== 16 || strlen($b) !== 16) return 64;
        $d = 0;
        for ($i = 0; $i < 16; $i++) {
            $d += self::$nibblePop[hexdec($a[$i]) ^ hexdec($b[$i])];
        }
        return $d;
    }

    /**
     * A coluna phash existe? Sem a migration o fluxo inteiro degrada para
     * "nada e duplicata" em vez de estourar erro de SQL.
     */
    private static function columnExists() {
        global $conn;
        if (self::$colExists !== null) return self::$colExists;
        try {
            $rs = $conn->Execute("SHOW COLUMNS FROM `video` LIKE 'phash'");
            self::$colExists = ($rs && !$rs->EOF);
        } catch (Exception $e) {
            self::$colExists = false;
        } catch (Throwable $e) {
            self::$colExists = false;
        }
        return self::$colExists;
    }

    /**
     * Um frame em tons de cinza SCALE x SCALE, como bytes crus.
     * @return string|false 256 bytes, ou false se o ffmpeg nao entregou frame
     */
    private static function fetchFrame($ffmpeg, $file, $t, $rawPath, $scale) {
        $need = $scale * $scale;
        $cmd = sprintf('%s -v error -ss %s -i %s -frames:v 1 -an -sn -dn -vf scale=%d:%d -pix_fmt gray -f rawvideo -y %s 2>/dev/null',
            escapeshellarg($ffmpeg), escapeshellarg((string)$t), escapeshellarg($file),
            $scale, $scale, escapeshellarg($rawPath));
        exec($cmd, $out, $code);
        if ($code !== 0 || !file_exists($rawPath)) return false;
        $data = file_get_contents($rawPath);
        if ($data === false || strlen($data) < $need) return false;
        return substr($data, 0, $need);
    }

    /**
     * DCT-II 2D de uma matriz BLOCK x BLOCK.
     * @param  array $m matriz quadrada de floats
     * @return array mesma dimensao
     */
    private static function dct2($m) {
        static $cos = null;
        $n = self::BLOCK;
        if ($cos === null) {
            $cos = array();
            for ($k = 0; $k < $n; $k++) {
                for ($i = 0; $i < $n; $i++) {
                    $cos[$k][$i] = cos(M_PI * (2 * $i + 1) * $k / (2 * $n));
                }
            }
        }

        $tmp = array();
        for ($y = 0; $y < $n; $y++) {
            for ($k = 0; $k < $n; $k++) {
                $s = 0.0;
                for ($x = 0; $x < $n; $x++) $s += $m[$y][$x] * $cos[$k][$x];
                $tmp[$y][$k] = $s;
            }
        }

        $out = array();
        for ($l = 0; $l < $n; $l++) {
            for ($k = 0; $k < $n; $k++) {
                $s = 0.0;
                for ($y = 0; $y < $n; $y++) $s += $tmp[$y][$k] * $cos[$l][$y];
                $out[$l][$k] = $s;
            }
        }
        return $out;
    }
}