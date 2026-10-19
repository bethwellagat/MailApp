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

t_group('icons swapped in by script also exist');
/**
 * The guard above only sees icons written as href="#ic-…" in markup. app.js
 * also swaps icons at runtime — setAttribute('href', open ? '#ic-fold' :
 * '#ic-unfold') — and those references are invisible to a regex looking for an
 * href attribute. Delete such a symbol and nothing fails; the button simply
 * renders an empty box, and only in the state that swaps it in, which is
 * exactly the kind of blank control this app has shipped before.
 *
 * So: every '#ic-…' string literal anywhere in app.js must resolve to a symbol
 * defined in the page that script drives.
 */
$js   = @file_get_contents(T_ROOT . '/assets/app.js');
$html = @file_get_contents(T_ROOT . '/inbox.php');
if (is_string($js) && is_string($html)) {
    preg_match_all('/<symbol[^>]*\bid="([^"]+)"/i', $html, $def);
    preg_match_all('/[\'"]#(ic-[a-z0-9-]+)[\'"]/i', $js, $lit);
    $refs    = array_values(array_unique($lit[1]));
    $missing = array_values(array_diff($refs, $def[1]));
    t_ok('every scripted icon literal is defined', $missing === [],
         'undefined: ' . implode(', ', $missing));
    t_ok('the scan found scripted icons to check', count($refs) > 0, 'found ' . count($refs));
}

t_group('the conversation expander is wired end to end');
/**
 * A toolbar button is three separate pieces — the markup, the two sprite
 * symbols and the handler — and losing any one of them leaves a control that
 * looks fine and does nothing.
 */
if (is_string($html) && is_string($js)) {
    t_ok('the button exists',            strpos($html, 'id="expandAllBtn"') !== false);
    t_ok('it starts hidden',             preg_match('/id="expandAllBtn"[^>]*\shidden/', $html) === 1,
         'a single-message conversation has nothing to expand');
    t_ok('it announces its state',       strpos($html, 'aria-expanded') !== false);
    foreach (['ic-unfold', 'ic-fold'] as $sym) {
        t_ok("sprite defines $sym", strpos($html, 'id="' . $sym . '"') !== false);
    }
    t_ok('a click handler is attached',  strpos($js, "\$('expandAllBtn').addEventListener") !== false);
    t_ok('the toggle is kept in step',   substr_count($js, 'updateExpandAllBtn()') >= 4,
         'renderThread, the loading and error paths, and the per-card click must all refresh it');
    t_ok('bodies load through one shared path', substr_count($js, 'loadThreadMsgBody(') >= 3,
         'the click handler and Expand all must not each carry their own fetch');
}

t_group('the search field stays readable while you type in it');
/**
 * The search box used to turn WHITE on focus, because it sat on a coloured
 * brand band — so a dark-theme rule forced its text to #1a1d23 to stay legible
 * against that white. The iPadOS redesign gave the field a dark fill instead
 * and never removed the colour rule, leaving near-black text on a dark fill:
 * 1.32:1 measured, which reads as "I type and nothing appears".
 *
 * Two things keep that from coming back.
 */
$focusRules = [];
foreach (t_blocks_for($css, '.topbar-search-input') as [$sel, $body]) {
    if (strpos($sel, ':focus') === false) continue;
    if (strpos($sel, '::placeholder') !== false) continue;
    $focusRules[] = [$sel, $body];
}
t_ok('the focused search field has rules to check', $focusRules !== [], 'none found');

/* 1. Nothing may force a dark colour onto it from a theme rule again. The field
      is dark-filled in dark mode, so a dark colour there is illegible by
      construction — and the page's own rule is the only one entitled to say
      what the focused colours are. */
$themeForced = [];
foreach ($focusRules as [$sel, $body]) {
    if (strpos($sel, 'theme-dark') === false) continue;
    $c = t_decls($body)['color'] ?? '';
    if ($c === '') continue;
    /* A literal hex darker than mid-grey is the shape of the old bug. */
    if (preg_match('/^#([0-9a-f]{6})$/i', trim($c), $m)) {
        $v = hexdec($m[1]);
        $lumish = ((($v >> 16) & 255) + (($v >> 8) & 255) + ($v & 255)) / 3;
        if ($lumish < 128) $themeForced[] = "$sel → color: $c";
    }
}
t_ok('no dark-theme rule forces a dark colour on the focused search field',
     $themeForced === [], implode(' | ', $themeForced));

/* 2. The page states the focused colours itself, rather than inheriting them
      from the base rule by winning a source-order tie. Both selectors are
      (0,2,0) without the `body.` prefix, so re-ordering the file would hand the
      white background — and its dark text — straight back. */
$own = null;
foreach ($focusRules as [$sel, $body]) {
    if (strpos($sel, '.mail-page') === false) continue;
    $own = [$sel, t_decls($body)];
}
t_ok('the mail page owns the focused search state', $own !== null,
     'no .mail-page rule for .topbar-search-input:focus');
if ($own !== null) {
    [$sel, $decls] = $own;
    t_ok('it declares its own background', isset($decls['background']),
         'without it the base rule turns the field white: ' . $sel);
    t_ok('it declares its own text colour', isset($decls['color']),
         'without it the base rule paints dark text: ' . $sel);
    t_ok('it wins on specificity, not on source order',
         strpos($sel, 'body.mail-page') !== false,
         'plain .mail-page ties with .topbar-search-input:focus — got: ' . $sel);
}

t_group('search: one definition of what a query means');
/**
 * The endpoint must parse with search_parse() and run through
 * search_collect(). A hand-built criterion anywhere in the endpoint would be
 * a second definition of what a search means — the same drift that once let
 * the list and the reading pane disagree about a conversation.
 */
$f = @file_get_contents(T_ROOT . '/ajax/fetch.php');
t_ok('fetch.php is readable', is_string($f) && $f !== '');
if (is_string($f)) {
    t_ok('the endpoint requires lib/search.php',       strpos($f, 'lib/search.php') !== false);
    t_ok('the endpoint requires lib/attachindex.php',  strpos($f, 'lib/attachindex.php') !== false);
    t_ok('search parses with search_parse()',          strpos($f, 'search_parse($q)') !== false);
    t_ok('search translates with search_to_imap()',    strpos($f, 'search_to_imap($p, $full)') !== false);
    /* Call sites only — "search_collect(" alone also matches the function's own
       definition, which let one caller plus the definition pass as two callers. */
    t_ok('search and starred page through one collector', substr_count($f, '$out = search_collect(') >= 2,
         'found ' . substr_count($f, '$out = search_collect(') . ' call sites');
    t_ok('no hand-built OR criterion remains in the endpoint', strpos($f, 'OR OR SUBJECT') === false);
    t_ok('starred reads a page parameter',
         preg_match("/action === 'starred'.*?\\\$_GET\\['page'\\]/s", $f) === 1);
    t_ok('attach_flags feeds the attachment index',
         preg_match("/action === 'attach_flags'.*?attach_index_merge\\(/s", $f) === 1);
    t_ok('the search response says what was sent to IMAP', strpos($f, "'criterion'") !== false,
         'a bug report with the criterion in it is a bug report that can be reproduced');
    t_ok('a has: filter is answered from the index, never guessed',
         preg_match('/attach_index_filter\\(\\$idx, \\$uids, \\$attach\\)/', $f) === 1);
}

t_group('search: the filter bar is wired end to end');
/**
 * A chip, its handler, the function it calls and the request it produces are
 * four separate pieces; losing any one leaves a control that looks fine and
 * does nothing. The Unread and Attachments chips are singled out because
 * their old behaviour — filtering the 25 rows on screen — was the defect.
 */
$h  = @file_get_contents(T_ROOT . '/inbox.php');
$js = @file_get_contents(T_ROOT . '/assets/app.js');
if (is_string($h) && is_string($js)) {
    t_ok('the bar exists and starts hidden', preg_match('/id="searchFilters"[^>]*\shidden/', $h) === 1);
    foreach (['is|unread', 'is|flagged', 'has|attachment', 'has|no-attachment', 'from|', 'in|anywhere'] as $c) {
        [$op, $val] = explode('|', $c);
        t_ok("chip $op:$val is in the markup", strpos($h, 'data-op="' . $op . '" data-val="' . $val . '"') !== false);
    }
    t_ok('the newer-than and sort selects exist', strpos($h, 'id="searchNewer"') !== false && strpos($h, 'id="searchSort"') !== false);
    t_ok('the bar\'s handler is attached',        strpos($js, "\$('searchFilters').addEventListener") !== false);
    t_ok('chips edit the query through one function', substr_count($js, 'searchToggleOperator(') >= 2);
    t_ok('chips light from the server\'s parse, not from a regex over the text',
         strpos($js, 'for (const c of searchParsed)') !== false);
    t_ok('Unread and Attachments chips ask the server',
         preg_match("/f === 'unread' \\|\\| f === 'attachments'/", $js) === 1);
    t_ok('…scoped to the folder being viewed',   strpos($js, "' in:\"' + state.currentFolder + '\"'") !== false);
    t_ok('the request carries page, sort and dir', strpos($js, "'&page=' + page + '&sort='") !== false);
    t_ok('the header shows the true total, not the rows loaded', strpos($js, "' · ' + searchTotal + noun") !== false);
    t_ok('the pager pages the search while searching',
         preg_match("/prevPage.*?state\\.searchActive.*?searchPage - 1/s", $js) === 1);
    t_ok('starred passes its page',               strpos($js, "apiGet('starred', withAcct({ page: page") !== false);
    t_ok('Enter searches at once',                strpos($js, "if (e.key === 'Enter') { e.preventDefault(); runSearchNow(); }") !== false);
}

t_group('unattended jobs: one body, two callers');
/**
 * Each job's sweep used to live inline in its endpoint. It now lives in
 * lib/jobs.php, and both the endpoint and the scheduler call it — so the
 * sweep a user triggers from a tab and the sweep the cron runs at 3 am are
 * the same code. An inline copy creeping back into an endpoint is the drift
 * this guards against.
 */
$jobs = @file_get_contents(T_ROOT . '/lib/jobs.php');
t_ok('lib/jobs.php defines the four jobs', is_string($jobs)
     && substr_count($jobs, 'function job_') === 4
     && strpos($jobs, 'function job_outbox_process') !== false && strpos($jobs, 'function job_ooo_process') !== false);
foreach (['ajax/outbox.php' => ['process', 'job_outbox_process'], 'ajax/rules.php' => ['run_new', 'job_rules_run_new'],
          'ajax/snooze.php' => ['wake', 'job_snooze_wake'], 'ajax/out_of_office.php' => ['process', 'job_ooo_process']] as $file => [$action, $fn]) {
    $src = @file_get_contents(T_ROOT . '/' . $file);
    t_ok("$file $action calls $fn", is_string($src) && preg_match("/action === '$action'.*?$fn\\(\\\$email\\)/s", $src) === 1);
}
$src = @file_get_contents(T_ROOT . '/ajax/out_of_office.php');
t_ok('the out-of-office endpoint no longer sends mail itself', is_string($src) && strpos($src, 'smtp_send(') === false);
$src = @file_get_contents(T_ROOT . '/ajax/rules.php');
t_ok('rule matching lives in the library, not the endpoint',
     is_string($src) && strpos($src, 'function rule_find_matches') === false
     && strpos((string)@file_get_contents(T_ROOT . '/lib/rules.php'), 'function rule_find_matches') !== false);
$src = @file_get_contents(T_ROOT . '/ajax/snooze.php');
t_ok('message lookup lives in the library, not the endpoint',
     is_string($src) && strpos($src, 'function find_uids_by_message_id') === false
     && strpos((string)@file_get_contents(T_ROOT . '/lib/snooze.php'), 'function find_uids_by_message_id') !== false);

t_group('cron.php is a door with a lock');
$c = @file_get_contents(T_ROOT . '/cron.php');
t_ok('it checks the token in constant time', is_string($c) && strpos($c, 'cron_token_ok(') !== false);
t_ok('it never starts a session',            is_string($c) && strpos($c, 'session_boot') === false && strpos($c, 'session_start') === false);
t_ok('the CLI path needs no token',           is_string($c) && strpos($c, "PHP_SAPI === 'cli'") !== false);
t_ok('a refusal says nothing useful',         is_string($c) && strpos($c, "'Forbidden'") !== false);
$a = @file_get_contents(T_ROOT . '/ajax/cron.php');
t_ok('the signed-in side requires CSRF on writes', is_string($a) && strpos($a, 'csrf_require()') !== false);
t_ok('…and only seals an account from THIS session', is_string($a) && strpos($a, 'account_get($id)') !== false);
t_ok('…and gates the token and the log on the admin', is_string($a) && substr_count($a, '$isAdmin') >= 4);

t_group('failures are written down');
$u = @file_get_contents(T_ROOT . '/lib/util.php');
t_ok('the fatal guard logs before it answers', is_string($u) && preg_match("/function json_fatal_guard.*?app_log\\('fatal'/s", $u) === 1);
t_ok('a failed IMAP open is logged',            is_string($u) && strpos($u, "app_log('warn', 'imap: open failed'") !== false);
t_ok('…without the password ever being passed to it', is_string($u) && preg_match('/_imap_log_open_failure\\(\\$host, \\$port, \\$user\\)/', $u) === 1
     && strpos($u, '_imap_log_open_failure($host, $port, $user, $pass') === false);

t_group('the Settings panes are wired');
$h = @file_get_contents(T_ROOT . '/settings.php');
if (is_string($h)) {
    t_ok('Background tasks pane and nav',  strpos($h, 'data-target="tasks"') !== false && strpos($h, '<section id="tasks"') !== false);
    t_ok('Diagnostics pane and nav',       strpos($h, 'data-target="diagnostics"') !== false && strpos($h, '<section id="diagnostics"') !== false);
    t_ok('a switch per account is rendered from the server\'s answer', strpos($h, "d.accounts.map(") !== false);
    t_ok('the cron commands come from the server, never guessed in JS', strpos($h, "d.php_cmd") !== false && strpos($h, "d.curl_cmd") !== false && strpos($h, "d.schedule") !== false);
    t_ok('writes carry the CSRF token',   preg_match("/ajax\\/cron\\.php.*?'X-CSRF-Token': window\\.__CSRF__/s", $h) === 1);
    t_ok('a failed switch is reverted, not pretended', strpos($h, 'cb.checked = !cb.checked') !== false);
}

t_group('the scheduler wakes itself');
$sess = (string)@file_get_contents(T_ROOT . '/lib/session.php');
t_ok('every request ends with a tick',          strpos($sess, "register_shutdown_function('cron_tick_shutdown')") !== false && strpos($sess, 'function cron_tick_shutdown') !== false);
t_ok('…that stands aside after a fatal',        preg_match('/function cron_tick_shutdown.*?error_get_last\(\)/s', $sess) === 1);
$c = (string)@file_get_contents(T_ROOT . '/cron.php');
t_ok('cron.php answers probes without running',  strpos($c, "'probe' => true") !== false);
t_ok('…runs hops',                               strpos($c, 'cron_hop(') !== false);
t_ok('…and rate-limits a URL-driven run',        strpos($c, 'cron_due(') !== false);
$l = (string)@file_get_contents(T_ROOT . '/lib/cron.php');
t_ok('a hop survives its caller hanging up',     preg_match('/function cron_hop\(.*?ignore_user_abort\(true\)/s', $l) === 1);
t_ok('a run closes any visitor session before mirroring credentials', preg_match('/function cron_run_all\(.*?session_write_close\(\).*?cron_mirror_account\(/s', $l) === 1);
t_ok('the address is learned only from a signed-in request', preg_match('/function cron_learn_base_url\(.*?\$_SESSION\[\'email\'\]/s', $l) === 1);
t_ok('the loopback keeps the token on this machine first', preg_match("/'name' => 'local', 'connect' => '127\\.0\\.0\\.1'/", $l) === 1);
$h = (string)@file_get_contents(T_ROOT . '/settings.php');
t_ok('the pane promises no cron job',            strpos($h, 'No cron job is needed') !== false);
t_ok('the host cron is tucked away as optional', strpos($h, '<details class="settings-details"') !== false && strpos($h, 'Host cron job (optional)') !== false);
t_ok('the state comes from the server',          strpos($h, 'd.status') !== false && strpos($l, 'function cron_status_words') !== false);
t_ok('the probe follows a pretty-URL redirect',  preg_match('/function cron_probe\(.*?cron_http_fetch\(/s', $l) === 1);
t_ok('a trigger uses the path the probe found',  preg_match("/function cron_trigger\(.*?\\\$t\['path'\]/s", $l) === 1);
t_ok('a credential outlives an empty queue',     strpos($l, 'CRED_IDLE_SECONDS') !== false && strpos($h, 'after 30 days with nothing pending') !== false);
$a = (string)@file_get_contents(T_ROOT . '/ajax/cron.php');
t_ok('switching on re-probes unless a loopback is confirmed', strpos($a, 'if (!cron_is_loopback_method($st[\'trigger\'][\'method\']) || (cron_now() - (int)$st[\'trigger\'][\'checked\']) > 3600) cron_probe();') !== false && strpos($a, "action === 'enable'") !== false);
t_ok('an admin can re-check on demand',           preg_match("/action === 'reprobe'.*?isAdmin.*?cron_probe\(\)/s", $a) === 1 && strpos($h, "action: 'reprobe'") !== false);
