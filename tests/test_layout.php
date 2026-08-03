<?php
/**
 * Stylesheet invariants that a browser would only reveal by someone clicking
 * the right control. These are cheap text checks; they are here because each
 * one corresponds to a bug that actually shipped.
 */

$css = file_get_contents(T_ROOT . '/assets/style.css');
t_ok('stylesheet is readable', is_string($css) && strlen($css) > 1000);

/**
 * Rules whose selector targets the element carrying class $class — the class as
 * a whole token, so ".topbar" does not also pick up ".topbar-end". Matching on a
 * plain substring made this check fire on descendants whose transform is
 * harmless centring, which is noise, and noise is how a real hit gets ignored.
 * Comments are stripped first so a warning that names a property does not read
 * as a declaration of it.
 */
function t_rules_for(string $css, string $class): array {
    $clean = preg_replace('#/\*.*?\*/#s', '', $css);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $clean, $m, PREG_SET_ORDER);
    $token = '/' . preg_quote($class, '/') . '(?![\w-])/';
    $out = [];
    foreach ($m as $r) {
        $sel = trim($r[1]);
        if ($sel === '' || $sel[0] === '@') continue;
        foreach (explode(',', $sel) as $one) {
            /* The class must be on the LAST compound of the selector — that is
               the element the rule actually styles. ".mail-page .topbar" counts;
               ".topbar .something-else" does not. */
            $last = preg_split('/[\s>+~]+/', trim($one));
            $last = end($last);
            if (preg_match($token, $last)) { $out[$sel] = $r[2]; break; }
        }
    }
    return $out;
}

/** Split a declaration body into [property => value], lowercased property. */
function t_decls(string $body): array {
    $out = [];
    foreach (explode(';', $body) as $d) {
        $p = strpos($d, ':');
        if ($p === false) continue;
        $out[strtolower(trim(substr($d, 0, $p)))] = trim(substr($d, $p + 1));
    }
    return $out;
}

t_group('no containing block above a position:fixed menu');
/**
 * filter / backdrop-filter / transform / perspective / contain:paint /
 * will-change all make an element the containing block for its position:fixed
 * descendants — and, for backdrop-filter, a scrolling/clipping context too.
 *
 * #moveDropdown is position:fixed, lives inside .actionbar, and is placed from
 * getBoundingClientRect(), i.e. in viewport coordinates. Put any of those
 * properties on the bar and the menu re-bases onto a 48px-tall box that clips
 * overflow: it renders 48px low and is scissored to nothing. v1.5.x shipped
 * exactly that, and the only symptom was "Move doesn't open".
 *
 * The bars have no content behind them, so none of these properties buys
 * anything there — the rule is simply: don't.
 */
$hosts  = ['.topbar', '.actionbar', '.ab-btn-wrap'];
$banned = ['filter', '-webkit-filter', 'backdrop-filter', '-webkit-backdrop-filter',
           'transform', '-webkit-transform', 'perspective', 'will-change'];

foreach ($hosts as $host) {
    $rules = t_rules_for($css, $host);
    t_ok("$host has rules to check", count($rules) > 0, 'found ' . count($rules));
    $bad = [];
    foreach ($rules as $sel => $body) {
        foreach (t_decls($body) as $prop => $value) {
            /* "none" is the initial value — it creates nothing. Anything else
               does, including a bare "blur(0)". */
            if (in_array($prop, $banned, true) && strtolower($value) !== 'none') {
                $bad[] = trim($sel) . " → $prop: $value";
            }
            if ($prop === 'contain' && preg_match('/paint|layout|strict|content/i', $value)) {
                $bad[] = trim($sel) . " → contain: $value";
            }
        }
    }
    t_ok("$host declares no containing-block property", $bad === [], implode(' | ', $bad));
}

t_group('the bars stay opaque');
/**
 * A translucent bar is only meaningful with something behind it, and the way to
 * get that effect is backdrop-filter — which is banned above. A part-transparent
 * bar with no blur just leaks the page colour through for no reason, so this
 * also guards against someone reintroducing the pairing.
 */
foreach (['.topbar', '.actionbar'] as $host) {
    $seen = 0;
    $bad = [];
    foreach (t_rules_for($css, $host) as $sel => $body) {
        if (strpos($sel, '.mail-page') === false) continue;   // only the redesign's own rules
        $decls = t_decls($body);
        if (!isset($decls['background']) && !isset($decls['background-color'])) continue;
        $seen++;
        $value = $decls['background'] ?? $decls['background-color'];
        if (preg_match('/color-mix|transparent|rgba?\([^)]*\/|hsla?\([^)]*\//i', $value)) {
            $bad[] = "$sel → background: $value";
        }
    }
    t_ok("$host has a redesign background rule", $seen > 0, "seen=$seen");
    t_ok("$host background is fully opaque", $bad === [], implode(' | ', $bad));
}

t_group('page scoping — neither redesign may leak');
/**
 * Each page carries one class on <body> — .mail-page, .settings-page,
 * .login-page. That is what lets any one of them be reverted alone, and what
 * stops one page restyling another. Every selector below the redesign marker
 * must therefore carry one of those scopes.
 */
$i = strpos($css, 'iPadOS Mail design language');
t_ok('inbox redesign block is present', $i !== false);
if ($i !== false) {
    $start = strrpos(substr($css, 0, $i), '/*');
    $block = preg_replace('#/\*.*?\*/#s', '', substr($css, $start));
    preg_match_all('/([^{}]+)\{/', $block, $m);
    $unscoped = [];
    $n = 0;
    foreach ($m[1] as $sel) {
        $sel = trim($sel);
        if ($sel === '' || $sel[0] === '@') continue;
        foreach (explode(',', $sel) as $one) {
            $one = trim($one);
            if ($one === '') continue;
            $n++;
            $scoped = false;
            foreach (['.mail-page', '.settings-page', '.login-page'] as $scope) {
                if (strpos($one, $scope) !== false) { $scoped = true; break; }
            }
            if (!$scoped) $unscoped[] = $one;
        }
    }
    t_ok('block has selectors to check', $n > 100, "n=$n");
    t_ok('every selector is page-scoped', $unscoped === [],
         count($unscoped) . ' unscoped: ' . implode(', ', array_slice($unscoped, 0, 6)));
}

t_group('stylesheet is well formed');
t_eq('braces balance', substr_count($css, '{'), substr_count($css, '}'));

t_group('every icon reference resolves');
/**
 * An <svg><use href="#ic-foo"> pointing at a symbol the page never defines
 * paints nothing at all. On a button with a text label that is a missing glyph;
 * on an icon-only button it is an invisible control. settings.php shipped with
 * two: the Add-contact "+" and the filter dialog's close "×", the latter being a
 * blank clickable square.
 *
 * Each page carries its own inline sprite, so this has to be checked per page.
 */
foreach (['index.php', 'inbox.php', 'settings.php'] as $page) {
    $html = @file_get_contents(T_ROOT . '/' . $page);
    t_ok("$page is readable", is_string($html) && $html !== '');
    if (!is_string($html)) continue;

    preg_match_all('/<symbol[^>]*\bid="([^"]+)"/i', $html, $def);
    preg_match_all('/<use[^>]*\bhref="#([^"]+)"/i', $html, $use);
    $missing = array_values(array_unique(array_diff($use[1], $def[1])));
    t_ok("$page: every <use> resolves to a defined <symbol>", $missing === [],
         'undefined: ' . implode(', ', $missing));
}

/* app.js builds markup for inbox.php, so its icon references have to exist
   there too — a runtime-only break a page-local check would miss. */
$js   = @file_get_contents(T_ROOT . '/assets/app.js');
$html = @file_get_contents(T_ROOT . '/inbox.php');
if (is_string($js) && is_string($html)) {
    preg_match_all('/<symbol[^>]*\bid="([^"]+)"/i', $html, $def);
    preg_match_all('/href=\\\\?["\']#(ic-[a-z0-9-]+)/i', $js, $m);
    $refs    = array_values(array_unique($m[1]));
    $missing = array_values(array_diff($refs, $def[1]));
    t_ok('app.js icon references exist in inbox.php', $missing === [],
         'undefined: ' . implode(', ', $missing));
    t_ok('app.js actually references icons', count($refs) > 5, 'found ' . count($refs));
}
