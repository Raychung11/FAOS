<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Self-contained QR Code encoder (ISO/IEC 18004).
 *
 * Scope chosen for this system: byte mode, error-correction level M,
 * versions 1-10 (auto-selected). That covers every reference string this
 * BOS produces (e.g. "STK-20260516-000001") with comfortable margin and
 * strong damage tolerance for printed kiosk labels.
 *
 * Renders to PNG (GD) or SVG. No Composer / network dependency.
 */
final class Qr
{
    /** Data codewords per version, ECC level M. Index 1..10. */
    private const DATA_CW = [1=>16,2=>28,3=>44,4=>64,5=>86,6=>108,7=>124,8=>154,9=>182,10=>216];

    /** EC codewords per block, ECC level M. */
    private const EC_CW = [1=>10,2=>16,3=>26,4=>18,5=>24,6=>16,7=>18,8=>22,9=>22,10=>26];

    /** Block layout [ [numBlocks, dataPerBlock], ... ] for ECC level M. */
    private const BLOCKS = [
        1=>[[1,16]], 2=>[[1,28]], 3=>[[1,44]], 4=>[[2,32]], 5=>[[2,43]],
        6=>[[4,27]], 7=>[[4,31]], 8=>[[2,38],[2,39]], 9=>[[3,36],[2,37]],
        10=>[[4,43],[1,44]],
    ];

    /** Alignment-pattern centre coordinates per version. */
    private const ALIGN = [
        1=>[], 2=>[6,18], 3=>[6,22], 4=>[6,26], 5=>[6,30], 6=>[6,34],
        7=>[6,22,38], 8=>[6,24,42], 9=>[6,26,46], 10=>[6,28,50],
    ];

    /** Pre-computed 18-bit version information (versions 7-10). */
    private const VERSION_INFO = [7=>0x07C94, 8=>0x085BC, 9=>0x09A99, 10=>0x0A4D3];

    private static array $expTable = [];
    private static array $logTable = [];

    // ---- Public API --------------------------------------------------------

    public static function matrix(string $text): array
    {
        self::initGalois();
        $bytes = array_values(unpack('C*', $text));
        $version = self::pickVersion(count($bytes));
        $bits = self::buildBitStream($bytes, $version);
        $codewords = self::bitsToCodewords($bits, $version);
        $final = self::interleave($codewords, $version);
        return self::buildMatrix($final, $version);
    }

    public static function png(string $text, int $scale = 6, int $margin = 4): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $size = ($n + 2 * $margin) * $scale;
        $img = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, $size, $size, $white);
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($m[$r][$c]) {
                    $x = ($c + $margin) * $scale;
                    $y = ($r + $margin) * $scale;
                    imagefilledrectangle($img, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    public static function svg(string $text, int $scale = 6, int $margin = 4): string
    {
        $m = self::matrix($text);
        $n = count($m);
        $dim = ($n + 2 * $margin) * $scale;
        $rects = '';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($m[$r][$c]) {
                    $x = ($c + $margin) * $scale;
                    $y = ($r + $margin) * $scale;
                    $rects .= "<rect x=\"$x\" y=\"$y\" width=\"$scale\" height=\"$scale\"/>";
                }
            }
        }
        return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$dim\" height=\"$dim\" "
            . "viewBox=\"0 0 $dim $dim\" shape-rendering=\"crispEdges\">"
            . "<rect width=\"$dim\" height=\"$dim\" fill=\"#fff\"/>"
            . "<g fill=\"#000\">$rects</g></svg>";
    }

    // ---- Galois field GF(256) ---------------------------------------------

    private static function initGalois(): void
    {
        if (self::$expTable) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$expTable[$i] = $x;
            self::$logTable[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$expTable[$i] = self::$expTable[$i - 255];
        }
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$expTable[self::$logTable[$a] + self::$logTable[$b]];
    }

    // ---- Encoding ----------------------------------------------------------

    private static function pickVersion(int $byteLen): int
    {
        for ($v = 1; $v <= 10; $v++) {
            $ccBits = $v <= 9 ? 8 : 16;
            $capacityBits = self::DATA_CW[$v] * 8;
            $needed = 4 + $ccBits + $byteLen * 8;
            if ($needed <= $capacityBits) {
                return $v;
            }
        }
        throw new \RuntimeException('Data too long for supported QR versions (max v10).');
    }

    private static function buildBitStream(array $bytes, int $version): string
    {
        $ccBits = $version <= 9 ? 8 : 16;
        $bits = '0100'; // byte mode
        $bits .= str_pad(decbin(count($bytes)), $ccBits, '0', STR_PAD_LEFT);
        foreach ($bytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }
        $totalBits = self::DATA_CW[$version] * 8;

        // Terminator (up to 4 zero bits).
        $bits .= str_repeat('0', min(4, $totalBits - strlen($bits)));
        // Pad to byte boundary.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }
        // Pad bytes 11101100 / 00010001 alternating.
        $pad = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $totalBits) {
            $bits .= $pad[$i++ % 2];
        }
        return $bits;
    }

    /** @return int[] data codewords */
    private static function bitsToCodewords(string $bits, int $version): array
    {
        $cw = [];
        for ($i = 0, $len = strlen($bits); $i < $len; $i += 8) {
            $cw[] = bindec(substr($bits, $i, 8));
        }
        return $cw;
    }

    private static function rsEncode(array $data, int $ecLen): array
    {
        // Generator polynomial.
        $gen = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coef) {
                $next[$j]     ^= self::gfMul($coef, self::$expTable[$i]);
                $next[$j + 1] ^= $coef;
            }
            $gen = $next;
        }
        // Polynomial division remainder.
        $rem = array_merge($data, array_fill(0, $ecLen, 0));
        for ($i = 0, $n = count($data); $i < $n; $i++) {
            $factor = $rem[$i];
            if ($factor === 0) {
                continue;
            }
            foreach ($gen as $j => $coef) {
                $rem[$i + $j] ^= self::gfMul($coef, $factor);
            }
        }
        return array_slice($rem, count($data));
    }

    private static function interleave(array $codewords, int $version): array
    {
        $ecLen = self::EC_CW[$version];
        $dataBlocks = [];
        $ecBlocks = [];
        $pos = 0;
        foreach (self::BLOCKS[$version] as [$count, $dataPer]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($codewords, $pos, $dataPer);
                $pos += $dataPer;
                $dataBlocks[] = $block;
                $ecBlocks[] = self::rsEncode($block, $ecLen);
            }
        }
        $maxData = max(array_map('count', $dataBlocks));
        $out = [];
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $blk) {
                if (isset($blk[$i])) {
                    $out[] = $blk[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ecBlocks as $blk) {
                $out[] = $blk[$i];
            }
        }
        return $out;
    }

    // ---- Matrix construction ----------------------------------------------

    private static function buildMatrix(array $codewords, int $version): array
    {
        $size = 17 + $version * 4;
        $m = array_fill(0, $size, array_fill(0, $size, null));   // null = unset
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinder($m, $reserved, 0, 0, $size);
        self::placeFinder($m, $reserved, 0, $size - 7, $size);
        self::placeFinder($m, $reserved, $size - 7, 0, $size);
        self::placeTiming($m, $reserved, $size);
        self::placeAlignment($m, $reserved, $version);

        // Dark module.
        $m[$size - 8][8] = 1;
        $reserved[$size - 8][8] = true;

        self::reserveFormat($reserved, $size);
        if ($version >= 7) {
            self::reserveVersion($reserved, $size);
        }

        self::placeData($m, $reserved, $codewords, $size);

        // Pick best mask by penalty.
        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $cand = self::applyMask($m, $reserved, $mask, $size);
            self::placeFormat($cand, $mask, $size);
            if ($version >= 7) {
                self::placeVersion($cand, $version, $size);
            }
            $score = self::penalty($cand, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $cand;
            }
        }
        // Normalise null -> 0.
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                $best[$r][$c] = $best[$r][$c] ? 1 : 0;
            }
        }
        return $best;
    }

    private static function placeFinder(array &$m, array &$res, int $top, int $left, int $size): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $top + $r;
                $cc = $left + $c;
                if ($rr < 0 || $rr >= $size || $cc < 0 || $cc >= $size) {
                    continue;
                }
                $inBox = ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6);
                $isDark = $inBox && (
                    $r === 0 || $r === 6 || $c === 0 || $c === 6 ||
                    ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)
                );
                $m[$rr][$cc] = $isDark ? 1 : 0;
                $res[$rr][$cc] = true;
            }
        }
    }

    private static function placeTiming(array &$m, array &$res, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            if ($m[6][$i] === null) {
                $m[6][$i] = $bit;
                $res[6][$i] = true;
            }
            if ($m[$i][6] === null) {
                $m[$i][6] = $bit;
                $res[$i][6] = true;
            }
        }
    }

    private static function placeAlignment(array &$m, array &$res, int $version): void
    {
        $coords = self::ALIGN[$version];
        $n = count($coords);
        foreach ($coords as $i => $r) {
            foreach ($coords as $j => $c) {
                // Skip the three finder corners.
                if (($i === 0 && $j === 0) ||
                    ($i === 0 && $j === $n - 1) ||
                    ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $dark = (max(abs($dr), abs($dc)) !== 1);
                        $m[$r + $dr][$c + $dc] = $dark ? 1 : 0;
                        $res[$r + $dr][$c + $dc] = true;
                    }
                }
            }
        }
    }

    private static function reserveFormat(array &$res, int $size): void
    {
        for ($i = 0; $i <= 8; $i++) {
            $res[8][$i] = true;
            $res[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $res[8][$size - 1 - $i] = true;
            $res[$size - 1 - $i][8] = true;
        }
    }

    private static function reserveVersion(array &$res, int $size): void
    {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $res[$i][$size - 11 + $j] = true;
                $res[$size - 11 + $j][$i] = true;
            }
        }
    }

    private static function placeData(array &$m, array $res, array $codewords, int $size): void
    {
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $len = strlen($bits);
        $idx = 0;
        $up = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // skip vertical timing column
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $up ? $size - 1 - $i : $i;
                for ($k = 0; $k < 2; $k++) {
                    $c = $col - $k;
                    if (!$res[$row][$c] && $m[$row][$c] === null) {
                        $bit = $idx < $len ? (int) $bits[$idx] : 0;
                        $idx++;
                        $m[$row][$c] = $bit;
                    }
                }
            }
            $up = !$up;
        }
    }

    private static function maskBit(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => (($r * $c) % 2) + (($r * $c) % 3) === 0,
            6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            7 => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            default => false,
        };
    }

    private static function applyMask(array $m, array $res, int $mask, int $size): array
    {
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                if (!$res[$r][$c] && $m[$r][$c] !== null && self::maskBit($mask, $r, $c)) {
                    $m[$r][$c] ^= 1;
                }
            }
        }
        return $m;
    }

    private static function placeFormat(array &$m, int $mask, int $size): void
    {
        // ECC level M = 0b00; 5-bit data = (00 << 3) | mask.
        $data = ($mask & 0x7);
        $bch = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ($bch & (1 << $i)) {
                $bch ^= 0x537 << ($i - 10);
            }
        }
        $format = (($data << 10) | $bch) ^ 0x5412;

        for ($i = 0; $i < 15; $i++) {
            $bit = ($format >> $i) & 1;
            // Around top-left.
            if ($i < 6) {
                $m[8][$i] = $bit;
            } elseif ($i === 6) {
                $m[8][7] = $bit;
            } elseif ($i === 7) {
                $m[8][8] = $bit;
            } elseif ($i === 8) {
                $m[7][8] = $bit;
            } else {
                $m[14 - $i][8] = $bit;
            }
            // Mirrored copy.
            if ($i < 8) {
                $m[$size - 1 - $i][8] = $bit;
            } else {
                $m[8][$size - 15 + $i] = $bit;
            }
        }
        $m[$size - 8][8] = 1; // dark module
    }

    private static function placeVersion(array &$m, int $version, int $size): void
    {
        $info = self::VERSION_INFO[$version] ?? null;
        if ($info === null) {
            return;
        }
        for ($i = 0; $i < 18; $i++) {
            $bit = ($info >> $i) & 1;
            $r = intdiv($i, 3);
            $c = $i % 3;
            $m[$r][$size - 11 + $c] = $bit;
            $m[$size - 11 + $c][$r] = $bit;
        }
    }

    private static function penalty(array $m, int $size): int
    {
        $score = 0;
        // Rule 1: runs of 5+ same colour (rows + cols).
        for ($r = 0; $r < $size; $r++) {
            $runC = 1; $runR = 1;
            for ($c = 1; $c < $size; $c++) {
                if ($m[$r][$c] === $m[$r][$c - 1]) { $runC++; }
                else { if ($runC >= 5) { $score += $runC - 2; } $runC = 1; }
                if ($m[$c][$r] === $m[$c - 1][$r]) { $runR++; }
                else { if ($runR >= 5) { $score += $runR - 2; } $runR = 1; }
            }
            if ($runC >= 5) { $score += $runC - 2; }
            if ($runR >= 5) { $score += $runR - 2; }
        }
        // Rule 2: 2x2 blocks.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                $v = $m[$r][$c];
                if ($v === $m[$r][$c+1] && $v === $m[$r+1][$c] && $v === $m[$r+1][$c+1]) {
                    $score += 3;
                }
            }
        }
        // Rule 3: finder-like patterns.
        $pat1 = [1,0,1,1,1,0,1,0,0,0,0];
        $pat2 = [0,0,0,0,1,0,1,1,1,0,1];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c <= $size - 11; $c++) {
                $rowSeg = []; $colSeg = [];
                for ($k = 0; $k < 11; $k++) {
                    $rowSeg[] = $m[$r][$c + $k];
                    $colSeg[] = $m[$c + $k][$r];
                }
                if ($rowSeg === $pat1 || $rowSeg === $pat2) { $score += 40; }
                if ($colSeg === $pat1 || $colSeg === $pat2) { $score += 40; }
            }
        }
        // Rule 4: dark/light balance.
        $dark = 0;
        foreach ($m as $row) { $dark += array_sum($row); }
        $total = $size * $size;
        $pct = $dark * 100 / $total;
        $score += (int) (floor(abs($pct - 50) / 5) * 10);
        return $score;
    }
}
