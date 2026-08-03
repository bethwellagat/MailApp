<?php
/**
 * Inline-CSS defuser.
 *
 * A message must not be able to escape its own container and paint over the
 * authenticated UI — a full-screen overlay needs no JavaScript at all, so the CSP
 * does not help. Ordinary email styling must survive untouched.
 */

require_once T_ROOT . '/lib/util.php';
require_once T_ROOT . '/lib/mailhtml.php';
require_once T_ROOT . '/lib/prefs.php';
function t_css_mail($h) { return sanitize_html($h); }
function t_css_sig($h)  { return sanitize_signature_html($h); }

/**
 * Did anything viewport-escaping survive? Models what a CSS parser resolves
 * rather than the raw bytes: comments are dropped and hex escapes decoded
 * (\66 ixed really does mean "fixed").
 */
function t_css_escapes($out) {
    $c = strtolower((string)$out);
    $c = preg_replace('#/\*.*?\*/#s', '', $c);
    $c = preg_replace_callback('#\\\\([0-9a-f]{1,6})\s?#i', fn($m) => chr(hexdec($m[1])), $c);
    $c = str_replace('\\', '', $c);
    if (preg_match('#position\s*:\s*(fixed|sticky)#', $c))  return 'position';
    if (preg_match('#z-index\s*:\s*-?\d{2,}#', $c))         return 'z-index';
    if (preg_match('#(expression|behaviou?r|javascript|vbscript|@import)\s*[:(]#', $c)) return 'script-css';
    return '';
}

$ATTACKS = [
    'full-screen overlay'      => '<div style="position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:99999;background:#fff">Sign in</div>',
    'uppercase FIXED'          => '<div style="POSITION:FIXED">x</div>',
    'spaced colon'             => '<div style="position : fixed">x</div>',
    'comment evasion'          => '<div style="position:/*c*/fixed">x</div>',
    'hex escape evasion'       => '<div style="position:\\66 ixed">x</div>',
    'UNQUOTED style attribute' => '<div style=position:fixed;z-index:9999>x</div>',
    'single-quoted'            => "<div style='position:fixed'>x</div>",
    'sticky variant'           => '<div style="position:sticky;top:0">x</div>',
    'max z-index'              => '<div style="z-index:2147483647">x</div>',
    'huge negative z-index'    => '<div style="z-index:-99999">x</div>',
    'expression()'             => '<div style="width:expression(alert(1))">x</div>',
    'expression with comment'  => '<div style="width:expr/*x*/ession(alert(1))">x</div>',
    '@import'                  => '<div style="@import url(//evil)">x</div>',
    'overlay among real decls' => '<div style="color:red;position:fixed;z-index:500">x</div>',
];

$LEGIT = [
    'basic text style'   => ['<div style="color:#333;font-size:14px">Hi</div>',        'color:#333;font-size:14px'],
    'table layout'       => ['<table style="width:100%;border-collapse:collapse"><tr><td>a</td></tr></table>', 'width:100%;border-collapse:collapse'],
    'position relative'  => ['<div style="position:relative">x</div>',                 'position:relative'],
    'small z-index'      => ['<span style="z-index:2;position:relative">x</span>',     'z-index:2'],
    'background image'   => ['<div style="background-image:url(\'https://ex.com/a.png\')">x</div>', 'https://ex.com/a.png'],
    'padding and margin' => ['<td style="padding:12px 8px;margin:0 auto">c</td>',      'padding:12px 8px'],
    'font stack'         => ['<p style="font-family:Arial,Helvetica,sans-serif">t</p>', 'Arial,Helvetica,sans-serif'],
];

// Both sanitizers share defuse_inline_css(); they once carried separate copies
// and only one of them received the hardening, so both are exercised here.
foreach ([['inbound mail', 't_css_mail'], ['signature / out-of-office', 't_css_sig']] as [$label, $fn]) {
    t_group($label . ' — nothing may escape the message container');
    foreach ($ATTACKS as $name => $payload) {
        $esc = t_css_escapes($fn($payload));
        t_ok($name, $esc === '', $esc !== '' ? "$esc survived: " . trim($fn($payload)) : '');
    }
    t_group($label . ' — ordinary styling must survive');
    foreach ($LEGIT as $name => [$payload, $keep]) {
        t_contains($name, $fn($payload), $keep);
    }
}
