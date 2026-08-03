<?php
/**
 * Inbound-mail and signature sanitizers.
 *
 * ATTACK cases must not leave a live event handler behind. LEGITIMATE cases must
 * come through untouched — over-stripping is a real bug too: an earlier version
 * of this filter ate the words out of ordinary prose ("set once=true") and
 * destroyed attribute values that merely began with "on" (href="online=1").
 *
 * Several expectations here encode BROWSER behaviour that was checked against a
 * real HTML5 parser, not assumed. Most importantly: '/' inside an UNQUOTED
 * attribute value does NOT start a new attribute, so `<img src=x/onerror=…>` is
 * a single attribute and must be preserved, whereas `<img/src=x onerror=…>` and
 * `<img src=x" onerror=…>` DO produce a live handler and must be stripped.
 */

require_once T_ROOT . '/lib/util.php';
t_extract_fn(T_ROOT . '/ajax/fetch.php', 'sanitize_html',           't_mail_sanitize');
t_extract_fn(T_ROOT . '/lib/prefs.php',  'sanitize_signature_html', 't_sig_sanitize');

/** Would a browser see an executable handler attribute in this output? */
function t_has_handler($out) {
    return (bool)preg_match('#[\s/"\']on[a-z0-9_-]+\s*=#i', (string)$out);
}

$ATTACKS = [
    'quote-adjacent (double)'     => '<img src="x"onerror="alert(1)">',
    'quote-adjacent (single)'     => "<img src='x'onerror='alert(1)'>",
    'quote-adjacent, unquoted'    => '<img src="x"onerror=alert(1)>',
    'quote then spaced handler'   => '<img src="x"  onerror="alert(1)">',
    'space separated'             => '<img src=x onerror="alert(1)">',
    'slash before attribute NAME' => '<img/src=x onerror=alert(1)>',
    'unbalanced double quote'     => '<img src=x" onerror=alert(1)>',
    'unbalanced single quote'     => "<img src=x' onerror=alert(1)>",
    'newline separated'           => "<img src=x\nonerror=alert(1)>",
    'tab separated'               => "<img src=\"x\"\tonerror=alert(1)>",
    'first attribute'             => '<body onload="alert(1)">',
    'uppercase'                   => '<IMG SRC="x"ONERROR="alert(1)">',
    'mixed case with spaces'      => '<img src="x"OnErRoR = "alert(1)">',
    'dashed handler name'         => '<div data-x="1"on-foo="alert(1)">',
    'chained handlers'            => '<img src="x"onerror="a()"onload=b()>',
    'quoted value containing >'   => '<img alt="a>b"onerror="alert(1)">',
    'revealed by tag removal'     => '<img src=x o<script></script>nerror=alert(1)>',
    'after an empty value'        => '<img src=""onerror="alert(1)">',
];

$LEGIT = [
    // name                      => [input, substring that must survive]
    'plain link'                 => ['<a href="https://example.com/page?x=1">Click</a>', 'example.com/page?x=1'],
    'url containing "on" word'   => ['<a href="https://ex.com/?mode=online=1">Go</a>',   'mode=online=1'],
    'prose with quotes'          => ['<p>The value "online=yes" is required.</p>',       '"online=yes"'],
    'prose with on= words'       => ['<p>Set once=true and only=false in config.</p>',   'once=true and only=false'],
    'href value starting on'     => ['<a href="online=1">click</a>',                     'href="online=1"'],
    'title value starting on'    => ['<td title="one=1"><b>Q3</b></td>',                 'title="one=1"'],
    'slash in unquoted value'    => ['<img src=x/onerror=alert(1)>',                     'x/onerror=alert(1)'],
    'unquoted URL with slashes'  => ['<img src=http://ex.com/a.png>',                    'http://ex.com/a.png'],
    'inline image'               => ['<img src="https://ex.com/a.png" alt="pic">',       'alt="pic"'],
    'styled table'               => ['<table style="width:100%"><tr><td>c</td></tr></table>', 'width:100%'],
    'bold and italic'            => ['<p><b>bold</b> and <i>italic</i></p>',             '<b>bold</b>'],
    'attribute named onward'     => ['<div data-onward="keep me">x</div>',               'keep me'],
    'valueless attribute'        => ['<td nowrap><b>x</b></td>',                         'nowrap'],
];

foreach ([['inbound mail', 't_mail_sanitize'], ['signature / out-of-office', 't_sig_sanitize']] as [$label, $fn]) {
    t_group($label . ' — no handler may survive');
    foreach ($ATTACKS as $name => $payload) {
        t_ok($name, !t_has_handler($fn($payload)), trim($fn($payload)));
    }
    t_group($label . ' — legitimate content must survive');
    foreach ($LEGIT as $name => [$payload, $keep]) {
        t_contains($name, $fn($payload), $keep);
    }
}

t_group('script and URL scheme removal');
t_not_contains('script element removed',  t_mail_sanitize('<p>a</p><script>alert(1)</script>'), 'alert(1)');
t_contains('javascript: href defused',    t_mail_sanitize('<a href="javascript:alert(1)">x</a>'), 'href="#"');
t_not_contains('svg element removed',     t_mail_sanitize('<svg><desc>x</desc></svg>'), '<svg');
t_contains('body text kept',              t_mail_sanitize('<html><body><p>hello</p></body></html>'), 'hello');
t_not_contains('doctype stripped',        t_mail_sanitize('<!doctype html><p>x</p>'), 'doctype');

t_group('performance guard (hot path — runs on every message open)');
$crafted = str_repeat('<a ', 60000) . 'q"z>';
$t0 = microtime(true);
t_mail_sanitize($crafted);
$craftedMs = (microtime(true) - $t0) * 1000;
// This construct once scaled quadratically: 60k took ~4.8s, 120k ~19s.
t_ok('crafted input stays linear (<1500ms for 60k tags)', $craftedMs < 1500, sprintf('%.0f ms', $craftedMs));

$news = '<html><body><table style="width:100%">'
      . str_repeat('<tr><td style="padding:8px"><a href="https://e.com/x?a=1">t</a>'
                 . '<img src="https://e.com/i.png" alt="p"></td></tr>', 400)
      . '</table><p>Ordinary prose with once=true inside.</p></body></html>';
$t0 = microtime(true);
for ($i = 0; $i < 10; $i++) t_mail_sanitize($news);
$perMsg = (microtime(true) - $t0) * 1000 / 10;
t_ok('realistic newsletter under 30ms/message', $perMsg < 30, sprintf('%.2f ms', $perMsg));
