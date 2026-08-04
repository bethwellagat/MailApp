<?php
/**
 * Workspace branding — the domain derivation, and the validation that guards
 * what a user may store in data/brand.json.
 *
 * These values are not ordinary settings: resolve_brand() feeds a <title>, a
 * <meta> tag and the PWA manifest JSON. A name that breaks any one of those
 * breaks the page for every account on the domain, so the write path is checked
 * as carefully as the sanitizers are.
 */

require_once T_ROOT . '/lib/brand.php';

/* brand_clean_text() lives in the endpoint, which bootstraps a session on
   include — lift it out by tokenizer, the way the sanitizer tests do. */
t_extract_fn(T_ROOT . '/ajax/brand.php', 'brand_clean_text', 't_brand_clean');

t_group('name derived from the host');
/* The whole point of the derivation is that one deployment serves many client
   domains unchanged, so each of these shapes has to land somewhere sensible. */
t_eq('plain domain',              brand_derived('acme.com')['name'],                'Acme');
t_eq('mail. prefix stripped',     brand_derived('mail.acme.com')['name'],           'Acme');
t_eq('outlook. prefix stripped',  brand_derived('outlook.taurusfzc.com')['name'],   'Taurusfzc');
t_eq('webmail. prefix stripped',  brand_derived('webmail.acme.com')['name'],        'Acme');
t_eq('two-part suffix',           brand_derived('mail.acme.co.ke')['name'],         'Acme');
t_eq('hyphens become words',      brand_derived('radiant-comms.com')['name'],       'Radiant Comms');
t_eq('underscores become words',  brand_derived('acme_corp.com')['name'],           'Acme Corp');
t_eq('port is ignored',           brand_derived('acme.com:8443')['name'],           'Acme');
t_eq('case is normalised',        brand_derived('MAIL.ACME.COM')['name'],           'Acme');

t_group('hosts with no meaningful name');
/* An IP has no second-level label. Picking one anyway produced a workspace
   called "0" for anyone reaching the app by address — visible in Settings as
   the value you would fall back to, which made the whole panel look broken. */
t_eq('IPv4 address',   brand_derived('127.0.0.1')['name'],      'WorkSpace');
t_eq('LAN address',    brand_derived('192.168.1.50')['name'],   'WorkSpace');
t_eq('IPv6 loopback',  brand_derived('::1')['name'],            'WorkSpace');
t_eq('empty host',     brand_derived('')['name'],               'WorkSpace');

t_group('derived is independent of the stored override');
/* Settings quotes the derived name in "leave empty to use X". If that reported
   the resolved value it would quote the override back at you — naming the thing
   you are trying to clear as the thing you would get by clearing it. */
$derivedName = brand_derived('outlook.taurusfzc.com')['name'];
t_eq('derivation ignores data/brand.json', $derivedName, 'Taurusfzc');
t_ok('a colour is always present', preg_match('/^#[0-9a-f]{6}$/i', brand_derived('acme.com')['color']) === 1,
     brand_derived('acme.com')['color']);

t_group('stored text is made safe for a title, a meta tag and JSON');
t_eq('plain text survives',       t_brand_clean('Taurus Communications', 60), 'Taurus Communications');
t_eq('surrounding space trimmed', t_brand_clean('  Acme  ', 60),              'Acme');
t_eq('newlines collapse',         t_brand_clean("Acme\nCorp", 60),            'Acme Corp');
t_eq('tabs collapse',             t_brand_clean("Acme\tCorp", 60),            'Acme Corp');
t_eq('NUL and control bytes go',  t_brand_clean("Ac\x00me\x1FCorp", 60),      'Ac me Corp');
t_eq('runs of space collapse',    t_brand_clean('Acme     Corp', 60),         'Acme Corp');
t_eq('empty stays empty',         t_brand_clean('   ', 60),                   '');

t_group('length is capped');
t_eq('over-long name truncated',    strlen(t_brand_clean(str_repeat('x', 500), 60)), 60);
t_eq('over-long tagline truncated', strlen(t_brand_clean(str_repeat('x', 500), 80)), 80);
/* Truncation must not split a multi-byte character — a half-written UTF-8
   sequence would corrupt the manifest JSON it ends up in. */
$multi = t_brand_clean(str_repeat('é', 100), 60);
t_ok('multibyte truncation stays valid UTF-8', mb_check_encoding($multi, 'UTF-8'), bin2hex(substr($multi, -4)));
t_eq('multibyte counted as characters', mb_strlen($multi, 'UTF-8'), 60);

t_group('markup in a name cannot escape its contexts');
/* The value is escaped at each output site rather than stripped here — these
   assert the stored form is what the escapers will receive, so a regression in
   cleaning cannot quietly pre-authorise something. */
$hostile = t_brand_clean('</title><script>alert(1)</script>', 60);
t_ok('angle brackets are preserved, not silently dropped', strpos($hostile, '<script>') !== false, $hostile);
t_eq('htmlspecialchars neutralises it',
     htmlspecialchars($hostile, ENT_QUOTES, 'UTF-8'),
     '&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;');
t_ok('json_encode neutralises it for the manifest',
     json_decode(json_encode(['name' => $hostile]), true)['name'] === $hostile);

t_group('colour is only ever a literal hex value');
/* This one lands in a meta tag and the manifest unquoted-ish, so resolve_brand
   refuses anything that is not exactly six hex digits. */
$colourOk = function ($v) { return preg_match('/^#[0-9a-fA-F]{6}$/', trim($v)) === 1; };
foreach (['#0078d4', '#FFFFFF', '#000000', '#1f6feb'] as $good) {
    t_ok("accepts $good", $colourOk($good));
}
foreach (['red', '#fff', '#00zzff', 'rgb(0,0,0)', '#0078d4;background:url(x)', '', 'javascript:1'] as $bad) {
    t_ok("rejects " . ($bad === '' ? '(empty)' : $bad), !$colourOk($bad));
}
