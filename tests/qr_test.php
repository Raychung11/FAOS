<?php
declare(strict_types=1);

require __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Qr;

$pass = 0;
$fail = 0;
function check(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else       { $fail++; echo "  FAIL  $label\n"; }
}

// 1. Known QR format-info strings for ECC level M (ISO/IEC 18004 Annex C).
$expected = [
    0 => 0b101010000010010, 1 => 0b101000100100101,
    2 => 0b101111001111100, 3 => 0b101101101001011,
    4 => 0b100010111111001, 5 => 0b100000011001110,
    6 => 0b100111110010111, 7 => 0b100101010100000,
];
foreach ($expected as $mask => $exp) {
    $data = $mask & 0x7;            // EC level M = 00
    $bch = $data << 10;
    for ($i = 14; $i >= 10; $i--) {
        if ($bch & (1 << $i)) { $bch ^= 0x537 << ($i - 10); }
    }
    $fmt = (($data << 10) | $bch) ^ 0x5412;
    check("format-info BCH mask $mask", $fmt === $exp);
}

// 2. Matrix structural integrity for several payloads / versions.
foreach (['STK-20260516-000001', 'SAL-20260516-000099', str_repeat('A', 120)] as $payload) {
    $m = Qr::matrix($payload);
    $n = count($m);
    check("matrix square ($payload)", $n === count($m[0]) && (($n - 17) % 4 === 0));

    // Finder pattern centre dark at top-left (3,3).
    check("finder TL centre dark ($payload)", $m[3][3] === 1);
    check("finder TR centre dark ($payload)", $m[3][$n - 4] === 1);
    check("finder BL centre dark ($payload)", $m[$n - 4][3] === 1);
    // Finder ring light at (1,1).
    check("finder TL ring light ($payload)", $m[1][1] === 0);
    // Timing pattern alternates on row 6.
    $timingOk = true;
    for ($i = 8; $i < $n - 8; $i++) {
        if ($m[6][$i] !== (($i % 2 === 0) ? 1 : 0)) { $timingOk = false; break; }
    }
    check("timing row valid ($payload)", $timingOk);
    // Dark module always set.
    check("dark module set ($payload)", $m[$n - 8][8] === 1);
    // Only 0/1 values.
    $clean = true;
    foreach ($m as $row) {
        foreach ($row as $v) { if ($v !== 0 && $v !== 1) { $clean = false; } }
    }
    check("binary matrix ($payload)", $clean);
}

// 3. PNG + SVG render sanity.
$png = Qr::png('STK-20260516-000001');
check('PNG signature', str_starts_with($png, "\x89PNG\r\n\x1a\n"));
$svg = Qr::svg('STK-20260516-000001');
check('SVG well-formed', str_starts_with($svg, '<svg') && str_ends_with($svg, '</svg>'));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
