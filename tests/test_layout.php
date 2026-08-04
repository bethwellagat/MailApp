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

/**
 * Every rule whose last compound carries $class, as a flat list of
 * [selector, body] pairs.
 *
 * t_rules_for() returns a selector-keyed map, which is convenient until the
 * same selector appears twice — a base rule plus an @media override, say. The
 * map keeps only the last one, so the base rule's declarations disappear and a
 * check for them reports a false failure. Use this whenever the question is
 * "does any rule declare X", not "what does the winning rule say".
 */
function t_blocks_for(string $css, string $class): array {
    $clean = preg_replace('#/\*.*?\*/#s', '', $css);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $clean, $m, PREG_SET_ORDER);
    $token = '/' . preg_quote($class, '/') . '(?![\w-])/';
    $out = [];
    foreach ($m as $r) {
        $sel = trim($r[1]);
        if ($sel === '' || $sel[0] === '@') continue;
        foreach (explode(',', $sel) as $one) {
            $last = preg_split('/[\s>+~]+/', trim($one));
            $last = end($last);
            if (preg_match($token, $last)) { $out[] = [$sel, $r[2]]; break; }
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

t_group('a decorative floating overlay must not steal clicks');
/**
 * .app-footer is a position:fixed copyright pill that floats over the scrolling
 * page on both inbox.php and settings.php. It has nothing to click, but it was
 * declaring pointer-events:auto — so whatever scrolled under it went dead. On
 * Settings → Calendars that silently killed a feed's Show toggle and its
 * refresh/delete buttons whenever the row happened to sit behind the pill.
 *
 * Fixed overlays that carry no controls must opt out of hit-testing.
 */
/* Scanned rule-by-rule rather than via t_rules_for(): that helper keys its map
   by selector text, so a later `@media ... { .app-footer { display: none } }`
   silently replaces the base rule and its declarations vanish from the map. */
$footerRules = t_blocks_for($css, '.app-footer');
t_ok('.app-footer has a rule', $footerRules !== [], 'none found');
$declaresNone = false;
$declaresAuto = [];
foreach ($footerRules as [$sel, $body]) {
    $decls = t_decls($body);
    if (!isset($decls['pointer-events'])) continue;
    if ($decls['pointer-events'] === 'none') $declaresNone = true;
    if ($decls['pointer-events'] === 'auto') $declaresAuto[] = "$sel → pointer-events: auto";
}
t_ok('.app-footer opts out of hit-testing', $declaresNone, 'no pointer-events:none found');
t_ok('.app-footer never re-enables it', $declaresAuto === [], implode(' | ', $declaresAuto));

/* ...but the link inside it must stay clickable, or opting the pill out would
   take any future link with it. */
$linkAuto = false;
foreach (t_blocks_for($css, 'a') as [$sel, $body]) {
    if (strpos($sel, '.app-footer') === false) continue;
    if ((t_decls($body)['pointer-events'] ?? '') === 'auto') $linkAuto = true;
}
t_ok('a link inside the pill stays clickable', $linkAuto,
     '.app-footer a does not restore pointer-events');

t_group('a checkbox row is a row, not a column');
/**
 * .cal-field stacks a label above its input, which is right for a text field
 * and wrong for a checkbox. .cal-field-check asks for flex-direction:row but is
 * only (0,1,0), so `.settings-page .cal-field` (0,2,0) outranked it and left
 * every checkbox in Settings floating above its own caption — six of them in
 * the filter dialog alone.
 *
 * If the page-scoped column rule exists, a page-scoped row override must too.
 */
$colRule = false;
foreach (t_blocks_for($css, '.cal-field') as [$sel, $body]) {
    if (strpos($sel, '.settings-page') === false) continue;
    if ((t_decls($body)['flex-direction'] ?? '') === 'column') $colRule = true;
}
if ($colRule) {
    $rowFix = false;
    foreach (t_blocks_for($css, '.cal-field-check') as [$sel, $body]) {
        if (strpos($sel, '.settings-page') === false) continue;
        if ((t_decls($body)['flex-direction'] ?? '') === 'row') $rowFix = true;
    }
    t_ok('.settings-page .cal-field-check re-asserts row', $rowFix,
         '.settings-page .cal-field sets column with no check-variant override');
} else {
    t_ok('.settings-page .cal-field does not force a column', true);
}

t_group('inline emphasis stays inline');
/**
 * A rule styling standalone hint text also matched `p em`, turning every inline
 * <em> inside a paragraph into a padded, ruled block. The calendar help — one
 * sentence naming Google Calendar, Outlook 365 and iCloud — shattered into a
 * row per label with the trailing full stop orphaned on its own line.
 *
 * An <em> inside running text is emphasis, never a block.
 */
$blockEm = [];
foreach (t_blocks_for($css, 'em') as [$sel, $body]) {
    if (!preg_match('/\bp\s+em\b/', $sel)) continue;
    if ((t_decls($body)['display'] ?? '') === 'block') $blockEm[] = $sel;
}
t_ok('no rule makes a paragraph\'s <em> a block', $blockEm === [], implode(' | ', $blockEm));

t_group('the hidden attribute must actually hide');
/**
 * `hidden` is enforced from the browser's OWN stylesheet, and any author rule
 * that sets `display` beats the entire UA sheet regardless of specificity. The
 * reset's `svg { display: block }` is enough to defeat it, which is how the
 * sign-in page came to paint the eye and the crossed-out eye at the same time.
 *
 * This had already been patched three times, one selector at a time. A single
 * global rule is the only version that also covers the next element to use it.
 */
$globalHidden = false;
foreach (t_blocks_for($css, '[hidden]') as [$sel, $body]) {
    if (trim($sel) !== '[hidden]') continue;
    if (preg_match('/display\s*:\s*none\s*!important/i', $body)) $globalHidden = true;
}
t_ok('a global [hidden] rule enforces display:none !important', $globalHidden,
     'without it, any element-type display rule silently defeats the attribute');

t_group('hidden is toggled by attribute, never by property, on SVG');
/**
 * `hidden` is a reflected IDL property on HTMLElement only. <svg> is an
 * SVGElement, so `svg.hidden = true` sets a plain JS expando and never touches
 * the attribute — the icon simply never changes. It fails silently, which is
 * why it survived: the click handler ran, aria-label updated, and only the
 * glyph stayed wrong.
 */
foreach (['index.php', 'inbox.php', 'settings.php'] as $page) {
    $src = @file_get_contents(T_ROOT . '/' . $page);
    if (!is_string($src)) continue;

    /* Which ids belong to an <svg> element in this page's own markup? */
    preg_match_all('/<svg\b[^>]*\bid="([^"]+)"/i', $src, $svgIds);
    $svg = array_flip($svgIds[1]);

    /* var NAME = document.getElementById('ID')  →  NAME maps to ID */
    preg_match_all('/(?:var|let|const)\s+(\w+)\s*=\s*document\.getElementById\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $src, $vars, PREG_SET_ORDER);
    $varToId = [];
    foreach ($vars as $v) $varToId[$v[1]] = $v[2];

    /* NAME.hidden = ...  — an assignment, not a read */
    preg_match_all('/(\w+)\s*\.\s*hidden\s*=(?!=)/', $src, $assigns, PREG_SET_ORDER);
    $bad = [];
    foreach ($assigns as $a) {
        $name = $a[1];
        if (!isset($varToId[$name])) continue;
        if (isset($svg[$varToId[$name]])) $bad[] = "$name → #{$varToId[$name]} is an <svg>";
    }
    t_ok("$page: no .hidden assignment on an <svg>", $bad === [], implode(' | ', $bad));
}
/* And the login toggle specifically uses the attribute API. */
$login = @file_get_contents(T_ROOT . '/index.php');
if (is_string($login)) {
    t_ok('index.php toggles the eye icons by attribute',
         strpos($login, "eyeOpen.toggleAttribute('hidden'") !== false
         && strpos($login, "eyeOff.toggleAttribute('hidden'") !== false);
}

t_group('every page scope carries its own touch targets');
/**
 * The 44px work was done page by page — .mail-page, then .settings-page — and
 * .login-page was missed entirely, leaving 39px fields and a 34px reveal button
 * on the first screen a phone user ever touches. Each scope needs its own block
 * precisely because the scoping convention stops one page's rules reaching
 * another's.
 */
$clean = preg_replace('#/\*.*?\*/#s', '', $css);
foreach (['.mail-page', '.settings-page', '.login-page'] as $scope) {
    $found = false;
    /* Coarse-pointer blocks: @media (max-width: 820px), (pointer: coarse) */
    if (preg_match_all('/@media[^{]*pointer\s*:\s*coarse[^{]*\{(.*?)\n\}/s', $clean, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (strpos($block, $scope) !== false && preg_match('/min-(height|width)\s*:\s*44px/', $block)) {
                $found = true;
                break;
            }
        }
    }
    t_ok("$scope has a 44px touch block", $found, 'no coarse-pointer rules for this scope');
}
