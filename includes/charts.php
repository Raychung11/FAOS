<?php
/**
 * AdvisorOS — Dependency-free inline SVG charts.
 * No JS, no external library (network-policy safe) — pure server-side
 * SVG strings sized to the data. Used by the Strategic Report.
 */

declare(strict_types=1);

/**
 * Donut chart. $parts = [ [label, value, color], ... ].
 * Renders an empty ring when the total is non-positive.
 */
function svg_donut(array $parts, int $size = 150, string $centre = ''): string
{
    $r = $size / 2 - 14;
    $cx = $size / 2;
    $c = 2 * M_PI * $r;
    $total = array_sum(array_map(fn ($p) => max(0.0, (float) $p[1]), $parts));

    $segs = '';
    $off = 0.0;
    if ($total > 0) {
        foreach ($parts as [$lbl, $val, $col]) {
            $val = max(0.0, (float) $val);
            if ($val <= 0) { continue; }
            $len = $val / $total * $c;
            $segs .= '<circle cx="' . $cx . '" cy="' . $cx . '" r="' . $r
                . '" fill="none" stroke="' . e($col) . '" stroke-width="16"'
                . ' stroke-dasharray="' . round($len, 2) . ' ' . round($c - $len, 2) . '"'
                . ' stroke-dashoffset="' . round(-$off, 2) . '"'
                . ' transform="rotate(-90 ' . $cx . ' ' . $cx . ')"/>';
            $off += $len;
        }
    } else {
        $segs = '<circle cx="' . $cx . '" cy="' . $cx . '" r="' . $r
            . '" fill="none" stroke="#eef2fb" stroke-width="16"/>';
    }

    $mid = $centre !== ''
        ? '<text x="' . $cx . '" y="' . ($cx + 5) . '" text-anchor="middle"'
          . ' font-size="18" font-weight="800" fill="#0B1F3A">' . e($centre) . '</text>'
        : '';

    return '<svg viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size
        . '" height="' . $size . '" role="img">' . $segs . $mid . '</svg>';
}

/** Semicircular gauge for a 0–100 score. */
function svg_gauge(float $pct, string $color, int $w = 200): string
{
    $pct = max(0.0, min(100.0, $pct));
    $r = 80;
    $cx = 100; $cy = 95;
    $ang = M_PI * (1 - $pct / 100);          // 180° → 0°
    $x = $cx + $r * cos($ang);
    $y = $cy - $r * sin($ang);
    $track = 'M20 95 A80 80 0 0 1 180 95';
    $arc = 'M20 95 A80 80 0 0 1 ' . round($x, 2) . ' ' . round($y, 2);

    return '<svg viewBox="0 0 200 120" width="' . $w . '" height="' . round($w * 0.6)
        . '" role="img">'
        . '<path d="' . $track . '" fill="none" stroke="#eef2fb" stroke-width="16" stroke-linecap="round"/>'
        . '<path d="' . $arc . '" fill="none" stroke="' . e($color)
        . '" stroke-width="16" stroke-linecap="round"/>'
        . '<text x="100" y="88" text-anchor="middle" font-size="26" font-weight="800"'
        . ' fill="#0B1F3A">' . (int) round($pct) . '</text>'
        . '<text x="100" y="108" text-anchor="middle" font-size="11" fill="#6b7686">/ 100</text>'
        . '</svg>';
}

/** Stacked horizontal range bar (low–mid–high) for a valuation. */
function svg_range(float $low, float $mid, float $high, int $w = 320): string
{
    $hi = max($high, 1.0);
    $lp = max(0.0, min(100.0, $low / $hi * 100));
    $mp = max(0.0, min(100.0, $mid / $hi * 100));
    return '<svg viewBox="0 0 320 44" width="' . $w . '" height="44" role="img">'
        . '<rect x="0" y="14" width="320" height="14" rx="7" fill="#eef2fb"/>'
        . '<rect x="' . round($lp * 3.2, 1) . '" y="14" width="'
        . round(max(2, (100 - $lp) * 3.2), 1) . '" height="14" rx="7" fill="#C9A227" opacity=".35"/>'
        . '<line x1="' . round($mp * 3.2, 1) . '" y1="9" x2="' . round($mp * 3.2, 1)
        . '" y2="33" stroke="#0B1F3A" stroke-width="3"/>'
        . '</svg>';
}
