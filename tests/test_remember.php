<?php
/**
 * Stay signed in: the cookie holds the secret, the server holds the lock.
 */
$GLOBALS['MAILAPP_DATA_DIR'] = t_tmpdir() . '/data';
require_once T_ROOT . '/lib/remember.php';

t_group('remember: the token');
$t = remember_token_make();
t_ok('selector is 24 hex, secret is url-safe base64 of 32 bytes', preg_match('/^[a-f0-9]{24}$/', $t['selector']) === 1 && preg_match('/^[A-Za-z0-9_-]{43}$/', $t['secret']) === 1);
t_eq('a cookie value parses back', remember_token_parse(remember_token_string($t)), $t);
t_eq('garbage does not', [remember_token_parse('abc'), remember_token_parse($t['selector'] . '.short'), remember_token_parse(123)], [null, null, null]);
t_ok('the key is 32 bytes and the same for the same secret', strlen(remember_key('s')) === 32 && remember_key('s') === remember_key('s') && remember_key('s') !== remember_key('t'));

t_group('remember: device names');
t_eq('Chrome on macOS', remember_ua_hint('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'), 'Chrome on macOS');
t_eq('Safari on iPhone', remember_ua_hint('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'), 'Safari on iPhone');
t_eq('Edge on Windows', remember_ua_hint('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0'), 'Edge on Windows');
t_eq('Firefox on Android', remember_ua_hint('Mozilla/5.0 (Android 14; Mobile; rv:130.0) Gecko/130.0 Firefox/130.0'), 'Firefox on Android');
t_eq('nothing recognisable', remember_ua_hint('curl/8.0'), 'Browser on a device');

t_group('remember: issue and open');
$acct = ['email' => 'me@x.com', 'password' => 'pw-secret', 'imap_host' => 'imap.x', 'imap_port' => 993, 'imap_ssl' => true, 'smtp_host' => 'smtp.x', 'display_name' => 'Me'];
$T = 1792400000;
$issued = remember_issue($acct, 'Mozilla/5.0 (Macintosh) Chrome/130.0 Safari/537.36', $T);
t_ok('issued', is_array($issued) && remember_token_parse($issued['cookie']) !== null);
t_eq('expires in 30 days', $issued['expires'], $T + 30 * 86400);
$raw = (string)file_get_contents(remember_path($issued['selector']));
t_ok('the record holds no password and no secret', strpos($raw, 'pw-secret') === false && strpos($raw, remember_token_parse($issued['cookie'])['secret']) === false);
t_ok('the record file is private', (fileperms(remember_path($issued['selector'])) & 0777) === 0600);
$opened = remember_open(remember_token_parse($issued['cookie']), $T + 10);
t_ok('the cookie opens it', is_array($opened) && $opened['account']['password'] === 'pw-secret' && $opened['account']['email'] === 'me@x.com');
t_eq('the device is listed, with its name', array_map(fn($d) => $d['device'], remember_list('me@x.com', '', $T + 10)), ['Chrome on macOS']);
t_ok('…and marked current when it is', remember_list('me@x.com', $issued['selector'], $T + 10)[0]['current'] === true);
t_ok('another account sees no devices', remember_list('other@x.com', '', $T + 10) === []);

t_group('remember: a wrong secret is theft');
$t2 = remember_token_parse($issued['cookie']); $t2['secret'] = strrev($t2['secret']);
t_ok('it does not open', remember_open($t2, $T + 10) === null);
t_ok('…and the device is revoked on the spot', remember_read($issued['selector']) === null);
t_ok('…so the real cookie no longer works either', remember_open(remember_token_parse($issued['cookie']), $T + 10) === null);

t_group('remember: rotation');
$issued = remember_issue($acct, 'Mozilla/5.0 (iPhone) Version/17 Safari/604', $T);
$old = remember_token_parse($issued['cookie']);
$opened = remember_open($old, $T + 100);
$newCookie = remember_rotate($opened['record'], $opened['account'], '', $T + 100);
t_ok('a new cookie for the same device', is_string($newCookie) && remember_token_parse($newCookie)['selector'] === $old['selector'] && remember_token_parse($newCookie)['secret'] !== $old['secret']);
t_ok('the new cookie opens', remember_open(remember_token_parse($newCookie), $T + 200) !== null);
t_ok('the old cookie is now theft — and revokes the device', remember_open($old, $T + 200) === null && remember_read($old['selector']) === null);

t_group('remember: expiry, cap, revoke all');
$issued = remember_issue($acct, '', $T);
t_ok('an expired record does not open and is removed', remember_open(remember_token_parse($issued['cookie']), $T + 31 * 86400) === null && remember_read($issued['selector']) === null);
$cookies = [];
for ($i = 0; $i < REMEMBER_MAX + 2; $i++) $cookies[] = remember_issue($acct, '', $T + $i)['cookie'];
t_eq('at most REMEMBER_MAX devices; the oldest go', count(remember_list('me@x.com', '', $T + 100)), REMEMBER_MAX);
t_ok('the oldest cookie no longer opens', remember_open(remember_token_parse($cookies[0]), $T + 100) === null);
t_ok('the newest does', remember_open(remember_token_parse(end($cookies)), $T + 100) !== null);
t_eq('revoke all', remember_revoke_all('me@x.com', $T + 100), REMEMBER_MAX);
t_eq('…leaves nothing', remember_list('me@x.com', '', $T + 100), []);
$issued = remember_issue($acct, '', $T);
$id = remember_list('me@x.com', '', $T + 1)[0]['id'];
t_ok('a list id resolves to its device and only its account', remember_selector_for_id('me@x.com', $id) === $issued['selector'] && remember_selector_for_id('other@x.com', $id) === null);
t_ok('a nameless account cannot be remembered', remember_issue(['email' => '', 'password' => 'x'], '', $T) === null);
