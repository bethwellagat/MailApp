<?php
/**
 * QR codes, in PHP, without a library: enough of ISO/IEC 18004 to show an
 * authenticator app an otpauth:// URI. Byte mode, error-correction level M,
 * versions 1 to 10 (up to 213 bytes), every mask tried and the best kept,
 * drawn as SVG so it is crisp at any size and needs no image library.
 *
 * STATUS: groundwork, not yet used anywhere. The Reed–Solomon generator
 * polynomials, the EC codewords, the format bits and the version bits are
 * verified against a reference encoder; the assembled symbol has not yet
 * been decoded by an independent reader. Do not wire it in until it has.
 */

/* ---------- GF(256) with the QR polynomial x^8 + x^4 + x^3 + x^2 + 1 ---------- */

function _qr_tables() {
    static $t = null;
    if ($t !== null) return $t;
    $exp = array_fill(0, 512, 0); $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x; $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) $x ^= 0x11D;
    }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $t = [$exp, $log];
}
function _qr_mul($a, $b) { if ($a === 0 || $b === 0) return 0; [$exp, $log] = _qr_tables(); return $exp[$log[$a] + $log[$b]]; }

/** The generator polynomial for $n error-correction codewords: coefficients, highest degree first. */
function qr_rs_generator($n) {
    [$exp] = _qr_tables();
    $g = [1];
    for ($i = 0; $i < $n; $i++) {
        $next = array_fill(0, count($g) + 1, 0);
        foreach ($g as $j => $c) {
            $next[$j]     ^= $c;
            $next[$j + 1] ^= _qr_mul($c, $exp[$i]);
        }
        $g = $next;
    }
    return $g;
}

/** Reed–Solomon error-correction codewords for one block. */
function qr_rs_encode(array $data, $n) {
    $g = qr_rs_generator($n);
    $res = array_fill(0, $n, 0);
    foreach ($data as $d) {
        $coef = $d ^ $res[0];
        array_shift($res); $res[] = 0;
        if ($coef !== 0) for ($j = 0; $j < $n; $j++) $res[$j] ^= _qr_mul($g[$j + 1], $coef);
    }
    return $res;
}

/* ---------- version tables, level M ---------- */

/** [total codewords, EC codewords per block, [blocks in group 1, data cw each], [blocks in group 2, data cw each]] */
function _qr_version_m($v) {
    static $t = [
        1  => [26,   10, [1, 16],  [0, 0]],
        2  => [44,   16, [1, 28],  [0, 0]],
        3  => [70,   26, [1, 44],  [0, 0]],
        4  => [100,  18, [2, 32],  [0, 0]],
        5  => [134,  24, [2, 43],  [0, 0]],
        6  => [172,  16, [4, 27],  [0, 0]],
        7  => [196,  18, [4, 31],  [0, 0]],
        8  => [242,  22, [2, 38],  [2, 39]],
        9  => [292,  22, [3, 36],  [2, 37]],
        10 => [346,  26, [4, 43],  [1, 44]],
    ];
    return $t[$v] ?? null;
}
function _qr_alignment_centres($v) {
    static $t = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 52]];
    return $t[$v];
}
function qr_data_capacity_m($v) { $i = _qr_version_m($v); return $i ? $i[2][0] * $i[2][1] + $i[3][0] * $i[3][1] : 0; }

/** The smallest version (1–10) whose byte-mode capacity at level M holds $len bytes. */
function qr_version_for($len) {
    for ($v = 1; $v <= 10; $v++) {
        $cap = qr_data_capacity_m($v) * 8;
        $header = 4 + ($v <= 9 ? 8 : 16);   // mode + count indicator
        if ($header + $len * 8 <= $cap) return $v;
    }
    return null;
}

/* ---------- the bit stream and codewords ---------- */

function qr_codewords($text, $v) {
    $info = _qr_version_m($v);
    $bytes = array_values(unpack('C*', (string)$text));
    $capBits = qr_data_capacity_m($v) * 8;
    $bits = '0100';                                                  // byte mode
    $bits .= str_pad(decbin(count($bytes)), $v <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    $bits .= str_repeat('0', min(4, $capBits - strlen($bits)));      // terminator
    if (strlen($bits) % 8) $bits .= str_repeat('0', 8 - strlen($bits) % 8);
    $pad = ['11101100', '00010001']; $i = 0;
    while (strlen($bits) < $capBits) { $bits .= $pad[$i++ % 2]; }
    $data = array_map('bindec', str_split($bits, 8));

    // Split into blocks, compute EC per block, then interleave.
    $blocks = []; $pos = 0;
    foreach ([$info[2], $info[3]] as [$count, $size]) {
        for ($b = 0; $b < $count; $b++) { $blocks[] = array_slice($data, $pos, $size); $pos += $size; }
    }
    $ecs = array_map(fn($blk) => qr_rs_encode($blk, $info[1]), $blocks);
    $out = [];
    $maxData = max(array_map('count', $blocks));
    for ($i = 0; $i < $maxData; $i++) foreach ($blocks as $blk) if (isset($blk[$i])) $out[] = $blk[$i];
    for ($i = 0; $i < $info[1]; $i++) foreach ($ecs as $ec) $out[] = $ec[$i];
    return $out;
}

/* ---------- the matrix ---------- */

function _qr_bch($value, $poly, $bits) {
    // value << bits, then remainder of division by the generator polynomial
    $v = $value << $bits;
    $top = strlen(decbin($poly)) - 1;
    for ($i = strlen(decbin($v)) - 1; $i >= $top; $i--) if (($v >> $i) & 1) $v ^= $poly << ($i - $top);
    return $v;
}
/** 15 bits: level M (00) + mask, BCH(15,5), masked with 101010000010010. */
function qr_format_bits($mask) {
    $data = (0b00 << 3) | $mask;
    return (($data << 10) | _qr_bch($data, 0b10100110111, 10)) ^ 0b101010000010010;
}
/** 18 bits for versions 7+: version + BCH(18,6). */
function qr_version_bits($v) { return ($v << 12) | _qr_bch($v, 0b1111100100101, 12); }

function _qr_blank($size) { return array_fill(0, $size, array_fill(0, $size, null)); }
function _qr_set_square(array &$m, $r, $c, $size, $dark) { for ($i = 0; $i < $size; $i++) for ($j = 0; $j < $size; $j++) $m[$r + $i][$c + $j] = $dark; }

/** Function patterns and reserved areas; returns the matrix with null where data goes. */
function qr_function_patterns($v) {
    $size = 17 + 4 * $v;
    $m = _qr_blank($size);
    $finder = function (&$m, $r, $c) use ($size) {
        for ($i = -1; $i <= 7; $i++) for ($j = -1; $j <= 7; $j++) {
            $rr = $r + $i; $cc = $c + $j;
            if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) continue;
            $edge = max(abs($i - 3), abs($j - 3));
            $m[$rr][$cc] = ($i >= 0 && $j >= 0 && $i <= 6 && $j <= 6) ? ($edge !== 2 && $edge !== 3 ? true : ($edge === 2 ? false : false)) : false;
        }
        // the ring at distance 2 is light, distance 3 dark, centre 3x3 dark, outer ring dark
        for ($i = 0; $i <= 6; $i++) for ($j = 0; $j <= 6; $j++) {
            $edge = max(abs($i - 3), abs($j - 3));
            $m[$r + $i][$c + $j] = ($edge === 3 || $edge <= 1);
        }
    };
    $finder($m, 0, 0); $finder($m, 0, $size - 7); $finder($m, $size - 7, 0);
    // timing
    for ($i = 8; $i < $size - 8; $i++) { if ($m[6][$i] === null) $m[6][$i] = ($i % 2 === 0); if ($m[$i][6] === null) $m[$i][6] = ($i % 2 === 0); }
    // alignment
    $centres = _qr_alignment_centres($v);
    foreach ($centres as $r) foreach ($centres as $c) {
        if ($m[$r][$c] !== null) continue;   // overlaps a finder
        for ($i = -2; $i <= 2; $i++) for ($j = -2; $j <= 2; $j++) $m[$r + $i][$c + $j] = (max(abs($i), abs($j)) !== 1);
    }
    // format areas (reserved, filled later) and the dark module
    for ($i = 0; $i < 9; $i++) { if ($i !== 6) { $m[8][$i] = $m[8][$i] ?? false; $m[$i][8] = $m[$i][8] ?? false; } }
    for ($i = 0; $i < 8; $i++) { $m[8][$size - 1 - $i] = $m[8][$size - 1 - $i] ?? false; $m[$size - 1 - $i][8] = $m[$size - 1 - $i][8] ?? false; }
    $m[$size - 8][8] = true;
    if ($v >= 7) for ($i = 0; $i < 6; $i++) for ($j = 0; $j < 3; $j++) { $m[$i][$size - 11 + $j] = false; $m[$size - 11 + $j][$i] = false; }
    return $m;
}

function _qr_place_format(array &$m, $v, $mask) {
    $size = count($m);
    $bits = qr_format_bits($mask);
    $get = fn($i) => (($bits >> (14 - $i)) & 1) === 1;   // bit 14 first
    // around the top-left finder
    $pos1 = [[8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],[7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8]];
    foreach ($pos1 as $i => [$r, $c]) $m[$r][$c] = $get($i);
    // the second copy: bits 0..6 down the right of the bottom-left finder, 7..14 along the bottom of the top-right finder
    for ($i = 0; $i < 7; $i++) $m[$size - 1 - $i][8] = $get($i);
    for ($i = 7; $i < 15; $i++) $m[8][$size - 15 + $i] = $get($i);
    if ($v >= 7) {
        $vb = qr_version_bits($v);
        for ($i = 0; $i < 18; $i++) {
            $bit = (($vb >> $i) & 1) === 1;
            $m[(int)floor($i / 3)][$size - 11 + ($i % 3)] = $bit;
            $m[$size - 11 + ($i % 3)][(int)floor($i / 3)] = $bit;
        }
    }
}

function _qr_mask_fn($mask, $r, $c) {
    switch ($mask) {
        case 0: return ($r + $c) % 2 === 0;
        case 1: return $r % 2 === 0;
        case 2: return $c % 3 === 0;
        case 3: return ($r + $c) % 3 === 0;
        case 4: return ((int)floor($r / 2) + (int)floor($c / 3)) % 2 === 0;
        case 5: return (($r * $c) % 2) + (($r * $c) % 3) === 0;
        case 6: return ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0;
        default: return ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0;
    }
}

/** Data bits into the zigzag, masked. $template has nulls where data goes. */
function _qr_place_data(array $template, array $codewords, $mask) {
    $m = $template; $size = count($m);
    $bits = '';
    foreach ($codewords as $cw) $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
    $remainder = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];
    $bits .= str_repeat('0', $remainder[(int)(($size - 17) / 4)]);
    $i = 0; $len = strlen($bits); $pair = 0;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) $col = 5;   // skip the vertical timing column
        $up = ($pair++ % 2) === 0;  // the rightmost pair climbs; direction alternates per pair
        for ($k = 0; $k < $size; $k++) {
            $row = $up ? $size - 1 - $k : $k;
            foreach ([$col, $col - 1] as $c) {
                if ($m[$row][$c] !== null) continue;
                $bit = $i < $len ? $bits[$i] === '1' : false; $i++;
                $m[$row][$c] = _qr_mask_fn($mask, $row, $c) ? !$bit : $bit;
            }
        }
    }
    return $m;
}

/** The four penalty rules of the specification; lower is better. */
function qr_penalty(array $m) {
    $size = count($m); $p = 0;
    // 1: runs of five or more
    for ($r = 0; $r < $size; $r++) { $run = 1; for ($c = 1; $c < $size; $c++) { if ($m[$r][$c] === $m[$r][$c - 1]) { if (++$run === 5) $p += 3; elseif ($run > 5) $p++; } else $run = 1; } }
    for ($c = 0; $c < $size; $c++) { $run = 1; for ($r = 1; $r < $size; $r++) { if ($m[$r][$c] === $m[$r - 1][$c]) { if (++$run === 5) $p += 3; elseif ($run > 5) $p++; } else $run = 1; } }
    // 2: 2x2 blocks
    for ($r = 0; $r < $size - 1; $r++) for ($c = 0; $c < $size - 1; $c++) { $v = $m[$r][$c]; if ($m[$r][$c + 1] === $v && $m[$r + 1][$c] === $v && $m[$r + 1][$c + 1] === $v) $p += 3; }
    // 3: finder-like patterns 1011101 with 0000 on either side
    $pat = [true, false, true, true, true, false, true];
    for ($r = 0; $r < $size; $r++) for ($c = 0; $c <= $size - 11; $c++) {
        $row = $m[$r];
        $a = array_slice($row, $c, 11); $col = []; for ($i = 0; $i < 11; $i++) $col[] = $m[$c + $i][$r];
        foreach ([$a, $col] as $seq) {
            if (array_slice($seq, 0, 4) === [false, false, false, false] && array_slice($seq, 4, 7) === $pat) $p += 40;
            if (array_slice($seq, 0, 7) === $pat && array_slice($seq, 7, 4) === [false, false, false, false]) $p += 40;
        }
    }
    // 4: dark proportion
    $dark = 0; foreach ($m as $row) foreach ($row as $x) if ($x) $dark++;
    $pct = $dark * 100 / ($size * $size);
    $p += (int)(min(abs((int)floor($pct / 5) * 5 - 50), abs((int)ceil($pct / 5) * 5 - 50)) / 5) * 10;
    return $p;
}

/** The finished symbol: a square array of booleans (true = dark), or null if the text is too long. */
function qr_matrix($text, &$versionOut = null) {
    $v = qr_version_for(strlen((string)$text));
    if ($v === null) return null;
    $versionOut = $v;
    $cw = qr_codewords($text, $v);
    $template = qr_function_patterns($v);
    $best = null; $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $m = _qr_place_data($template, $cw, $mask);
        _qr_place_format($m, $v, $mask);
        $score = qr_penalty($m);
        if ($score < $bestScore) { $bestScore = $score; $best = $m; }
    }
    return $best;
}

/** SVG with a quiet zone of 4 modules, one path for all dark modules. */
function qr_svg($text, $scale = 4) {
    $m = qr_matrix($text);
    if (!$m) return '';
    $size = count($m); $q = 4; $dim = ($size + 2 * $q) * $scale;
    $d = '';
    for ($r = 0; $r < $size; $r++) for ($c = 0; $c < $size; $c++) if ($m[$r][$c]) $d .= 'M' . (($c + $q) * $scale) . ' ' . (($r + $q) * $scale) . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z';
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . $dim . '" height="' . $dim . '" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="100%" height="100%" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
}
