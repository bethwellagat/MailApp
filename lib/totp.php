<?php
/**
 * Time-based one-time passwords (RFC 6238 over RFC 4226 HOTP), base32
 * (RFC 4648) for the secret, and the otpauth:// URI authenticator apps read.
 *
 * SHA-1, 30-second steps, six digits — what Google Authenticator, Authy,
 * 1Password, Microsoft Authenticator and the rest all expect by default.
 * Pure functions throughout; the clock is a parameter so the RFC's own test
 * vectors run as tests.
 */

function base32_encode_raw($bytes) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = ''; $out = '';
    foreach (str_split((string)$bytes) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    return $out;
}

function base32_decode_raw($text) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string)$text));
    if ($text === '') return '';
    $bits = '';
    foreach (str_split($text) as $c) { $v = strpos($alphabet, $c); if ($v === false) return null; $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) $out .= chr(bindec($byte)); }
    return $out;
}

/** A fresh secret: 160 bits, as base32 (32 characters). */
function totp_new_secret() { return base32_encode_raw(random_bytes(20)); }

/** The HOTP value for one counter. */
function hotp_code($rawSecret, $counter, $digits = 6) {
    $msg  = pack('J', (int)$counter);                  // 8-byte big-endian counter
    $hash = hash_hmac('sha1', $msg, $rawSecret, true);
    $off  = ord($hash[19]) & 0x0F;
    $bin  = ((ord($hash[$off]) & 0x7F) << 24) | (ord($hash[$off + 1]) << 16) | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
    return str_pad((string)($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

function totp_counter($time, $period = 30) { return (int)floor($time / $period); }

function totp_code($base32Secret, $time = null, $period = 30, $digits = 6) {
    $raw = base32_decode_raw($base32Secret);
    if ($raw === null || $raw === '') return null;
    return hotp_code($raw, totp_counter($time ?? time(), $period), $digits);
}

/**
 * Accept the code for the current step and one step either side (clock
 * drift), but never a counter at or below $lastCounter — a code, once used,
 * is spent. Returns the counter that matched, or null.
 */
function totp_verify($base32Secret, $code, $time = null, $lastCounter = -1, $window = 1, $period = 30, $digits = 6) {
    $code = preg_replace('/\s+/', '', (string)$code);
    if (!preg_match('/^\d{' . $digits . '}$/', $code)) return null;
    $raw = base32_decode_raw($base32Secret);
    if ($raw === null || $raw === '') return null;
    $now = totp_counter($time ?? time(), $period);
    for ($d = -$window; $d <= $window; $d++) {
        $c = $now + $d;
        if ($c <= $lastCounter) continue;
        if (hash_equals(hotp_code($raw, $c, $digits), $code)) return $c;
    }
    return null;
}

/** What the authenticator app scans. Issuer and account are URL-encoded; the secret is base32 as is. */
function totp_uri($issuer, $account, $base32Secret, $period = 30, $digits = 6) {
    $label = rawurlencode((string)$issuer) . ':' . rawurlencode((string)$account);
    return 'otpauth://totp/' . $label . '?secret=' . $base32Secret . '&issuer=' . rawurlencode((string)$issuer) . '&algorithm=SHA1&digits=' . (int)$digits . '&period=' . (int)$period;
}

/** The secret in groups of four, for typing by hand. */
function totp_secret_pretty($base32Secret) { return trim(chunk_split((string)$base32Secret, 4, ' ')); }

/**
 * Backup codes: ten of them, 10 characters, shown once; only hashes are
 * kept. Returns [plain codes, stored hashes].
 */
function totp_backup_codes($n = 10) {
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';   // no 0/o/1/l/i
    $plain = []; $hashes = [];
    for ($i = 0; $i < $n; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $plain[]  = substr($c, 0, 5) . '-' . substr($c, 5);
        $hashes[] = hash('sha256', $c);
    }
    return [$plain, $hashes];
}

/** A backup code matches one stored hash: returns the remaining hashes, or null if it matched none. */
function totp_backup_use($code, array $hashes) {
    $c = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$code));
    if (strlen($c) !== 10) return null;
    $h = hash('sha256', $c);
    foreach ($hashes as $i => $stored) {
        if (hash_equals((string)$stored, $h)) { unset($hashes[$i]); return array_values($hashes); }
    }
    return null;
}
