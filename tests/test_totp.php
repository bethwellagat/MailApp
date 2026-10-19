<?php
/**
 * Two-factor: TOTP against the RFC's own vectors, base32, backup codes.
 */
require_once T_ROOT . '/lib/totp.php';

t_group('totp: base32');
t_eq('RFC 4648 vectors', [base32_encode_raw(''), base32_encode_raw('f'), base32_encode_raw('fo'), base32_encode_raw('foo'), base32_encode_raw('foobar')], ['', 'MY', 'MZXQ', 'MZXW6', 'MZXW6YTBOI']);
t_eq('decode is the inverse, case- and space-tolerant', base32_decode_raw('mzxw 6ytb oi'), 'foobar');
t_eq('stray characters are dropped before decoding', base32_decode_raw('MZX!'), base32_decode_raw('MZX'));
t_ok('a new secret is 32 base32 characters', preg_match('/^[A-Z2-7]{32}$/', totp_new_secret()) === 1);

t_group('totp: RFC 6238 test vectors (SHA-1, secret 12345678901234567890)');
$secret = base32_encode_raw('12345678901234567890');
foreach ([[59, '94287082'], [1111111109, '07081804'], [1111111111, '14050471'], [1234567890, '89005924'], [2000000000, '69279037'], [20000000000, '65353130']] as [$t, $expect]) {
    t_eq("T=$t → $expect", totp_code($secret, $t, 30, 8), $expect);
}
t_eq('six digits are the last six', totp_code($secret, 59, 30, 6), '287082');

t_group('totp: verifying');
$t = 1111111111;   // counter 37037037
$now = totp_code($secret, $t);
t_eq('the current code is accepted and the counter returned', totp_verify($secret, $now, $t), totp_counter($t));
t_eq('one step early is accepted (drift)', totp_verify($secret, totp_code($secret, $t - 30), $t), totp_counter($t) - 1);
t_eq('one step late is accepted (drift)', totp_verify($secret, totp_code($secret, $t + 30), $t), totp_counter($t) + 1);
t_eq('two steps away is not', totp_verify($secret, totp_code($secret, $t + 90), $t), null);
t_eq('a code already used is spent', totp_verify($secret, $now, $t, totp_counter($t)), null);
t_eq('…and so is an older one', totp_verify($secret, totp_code($secret, $t - 30), $t, totp_counter($t)), null);
t_eq('spaces in the typed code are fine', totp_verify($secret, substr($now, 0, 3) . ' ' . substr($now, 3), $t), totp_counter($t));
t_eq('letters are not', totp_verify($secret, 'abcdef', $t), null);
t_eq('a wrong code is not', totp_verify($secret, '000000', $t) === null || $now === '000000', true);

t_group('totp: the uri');
t_eq('otpauth with issuer, label and secret', totp_uri('Taurus Mail', 'info@taurusfzc.com', 'MZXW6YTBOI'), 'otpauth://totp/Taurus%20Mail:info%40taurusfzc.com?secret=MZXW6YTBOI&issuer=Taurus%20Mail&algorithm=SHA1&digits=6&period=30');
t_eq('pretty secret in fours', totp_secret_pretty('MZXW6YTBOIMZXW6Y'), 'MZXW 6YTB OIMZ XW6Y');

t_group('totp: backup codes');
[$plain, $hashes] = totp_backup_codes();
t_ok('ten codes, five-dash-five, from an unambiguous alphabet', count($plain) === 10 && !array_filter($plain, fn($c) => !preg_match('/^[a-hj-km-np-z2-9]{5}-[a-hj-km-np-z2-9]{5}$/', $c)));
$left = totp_backup_use(strtoupper($plain[3]), $hashes);
t_ok('a code is accepted once, in any case or spacing, and removed', is_array($left) && count($left) === 9 && totp_backup_use($plain[3], $left) === null);
t_eq('a wrong code matches nothing', totp_backup_use('zzzzz-zzzzz', $hashes), null);
