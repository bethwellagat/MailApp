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
    t_ok('search plans its attempts rather than hand-building a criterion',
         strpos($f, 'search_plan($p, $full)') !== false && preg_match('/\\$criterion = \\$attempt\\[.criterion.\\]/', $f) === 1);
    /* Call sites only — "search_collect(" alone also matches the function's own
       definition, which let one caller plus the definition pass as two callers. */
    t_ok('search and starred page through one collector', substr_count($f, ' search_collect(') >= 2,
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

t_group('templates: wired end to end');
$ib = (string)@file_get_contents(T_ROOT . '/inbox.php');
t_ok('compose has a Templates button and its menu', strpos($ib, 'id="composeTplBtn"') !== false && strpos($ib, 'id="composeTplMenu"') !== false);
$js = (string)@file_get_contents(T_ROOT . '/assets/app.js');
t_ok('the page asks the server to render, never substitutes itself', strpos($js, "action: 'render'") !== false && preg_match('#replace\(/\\\{#', $js) === 0);
t_ok('an inserted template never duplicates the signature', preg_match("/function saveCurrentAsTemplate.*?querySelectorAll\('\.email-signature'\).*?remove\(\)/s", $js) === 1);
$h = (string)@file_get_contents(T_ROOT . '/settings.php');
t_ok('Templates pane and nav', strpos($h, 'data-target="templates"') !== false && strpos($h, '<section id="templates"') !== false);
t_ok('the signature toolbar handler is scoped to its own pane', strpos($h, "querySelectorAll('#signature .sig-tool')") !== false && strpos($h, "querySelectorAll('.sig-tool')") === false);
$a = (string)@file_get_contents(T_ROOT . '/ajax/templates.php');
t_ok('the endpoint needs a session and CSRF on writes', strpos($a, "empty(\$_SESSION['email'])") !== false && strpos($a, 'csrf_require()') !== false);
$l = (string)@file_get_contents(T_ROOT . '/lib/templates.php');
t_ok('bodies pass through the signature sanitiser', strpos($l, 'sanitize_signature_html(') !== false);

t_group('lists: sort, all in folder, rename, spam');
$f = (string)@file_get_contents(T_ROOT . '/ajax/fetch.php');
t_ok('a sorted list asks IMAP for the order and fetches the page by UID', preg_match("/action === 'messages'.*?list_sort_params\\(.*?imap_sort\\(.*?FT_UID/s", $f) === 1);
t_ok('every bulk endpoint can take the whole folder', substr_count($f, 'resolve_uid_set($mbox, $uids)') >= 5 && strpos($f, "if (!empty(\$_POST['all'])) return ['all'];") !== false);
t_ok('…bounded, with the remainder reported', strpos($f, 'bulk_uid_window($all, 5000)') !== false && substr_count($f, "'remaining' => \$BULK_REMAINING") >= 5);
t_ok('folders can be renamed, standard ones not', preg_match("/action === 'rename_folder'.*?Cannot rename a standard mail folder.*?imap_renamemailbox\\(/s", $f) === 1);
$ib = (string)@file_get_contents(T_ROOT . '/inbox.php');
t_ok('the list has a sort control and a select-all-in-folder link', strpos($ib, 'id="listSortSelect"') !== false && strpos($ib, 'id="listSelectFolder"') !== false);
t_ok('spam is one click in the action bar and the context menu', strpos($ib, 'id="abSpam"') !== false && strpos($ib, 'data-msg-action="spam"') !== false);
t_ok('rename sits in the folder menu', strpos($ib, 'data-action="rename-folder"') !== false);
$js = (string)@file_get_contents(T_ROOT . '/assets/app.js');
t_ok('all-in-folder sends all=1 instead of a list', preg_match('/function fanOutAction.*?\\{ all: 1 \\}/s', $js) === 1);
t_ok('…and never offers an undo it cannot honour', preg_match('/function actDelete.*?!wholeFolder\\) \\? capturedMsgIds\\(uids\\) : \\[\\]/s', $js) === 1 && preg_match('/function actArchive.*?wholeFolder \\? \\[\\] : capturedMsgIds/s', $js) === 1);
t_ok('the remembered order is restored before the first load', strpos($js, 'loadListSort();') !== false && strpos($js, "localStorage.getItem('mailapp.listSort')") !== false);
t_ok('spam from inside Junk is "Not spam"', preg_match('/function actSpam.*?inJunk\\(\\) \\? \'INBOX\' : junk\\.name/s', $js) === 1);
t_ok('folder actions carry the account they belong to', preg_match("/apiPost\\('rename_folder', withAcct\\(/", $js) === 1 && preg_match("/apiPost\\('delete_folder', withAcct\\(/", $js) === 1);

t_group('rules: richer conditions, wired');
$h = (string)@file_get_contents(T_ROOT . '/settings.php');
foreach (['ruleMode', 'ruleCc', 'ruleLarger', 'ruleSmaller', 'ruleOlder', 'ruleNewer', 'ruleQuery'] as $id) {
    t_ok("the filter modal has #$id, read and filled", strpos($h, 'id="' . $id . '"') !== false && substr_count($h, "\$('" . $id . "')") >= 2);
}
t_ok('the list says "or" for any-mode rules', strpos($h, "r.match.mode === 'any'") !== false);
$a = (string)@file_get_contents(T_ROOT . '/ajax/rules.php');
t_ok('a query the parser rejects is refused on add and update', substr_count($a, 'rule_query_errors(') >= 2);
$l = (string)@file_get_contents(T_ROOT . '/lib/rules.php');
t_ok('the rule query is translated by the search translator, not a second parser', strpos($l, 'search_to_imap(search_parse(') !== false);

t_group('labels: wired end to end');
$ib = (string)@file_get_contents(T_ROOT . '/inbox.php');
t_ok('the action bar has a Label menu and the sidebar a label list', strpos($ib, 'id="abLabel"') !== false && strpos($ib, 'id="labelDropdown"') !== false && strpos($ib, 'id="labelList"') !== false && strpos($ib, 'id="labelNewBtn"') !== false);
t_ok('the tag icon exists in both sprites', strpos($ib, '<symbol id="ic-tag"') !== false && strpos((string)@file_get_contents(T_ROOT . '/settings.php'), '<symbol id="ic-tag-s"') !== false);
$js = (string)@file_get_contents(T_ROOT . '/assets/app.js');
t_ok('chips are filled after the list, like paperclips', strpos($js, 'loadLabelFlags(seq);') !== false && strpos($js, "action: 'flags'") !== false);
t_ok('a label click is a search in the shared language', preg_match('/function searchByLabel.*?in:anywhere label:/s', $js) === 1);
t_ok('the label menu is disabled in the unified inbox', strpos($js, "$('abLabel').disabled = !has || isUnifiedView()") !== false);
t_ok('applying a label can take the whole folder', preg_match("/function applyLabel.*?body\\.append\\('all', '1'\\)/s", $js) === 1);
$h = (string)@file_get_contents(T_ROOT . '/settings.php');
t_ok('Labels pane and nav', strpos($h, 'data-target="labels"') !== false && strpos($h, '<section id="labels"') !== false && strpos($h, 'id="lblSwatches"') !== false);
t_ok('a filter can add a label', strpos($h, 'id="ruleLabel"') !== false && substr_count($h, "\$('ruleLabel')") >= 2);
$a = (string)@file_get_contents(T_ROOT . '/ajax/labels.php');
t_ok('the endpoint needs a session and CSRF on writes', strpos($a, "empty(\$_SESSION['email'])") !== false && strpos($a, 'csrf_require()') !== false);
t_ok('flags come from one KEYWORD search per label, not one per message', strpos((string)@file_get_contents(T_ROOT . '/lib/labels.php'), "'KEYWORD ' . label_keyword(\$l)") !== false);

t_group('lists: a sorted list is shown in the server\'s order');
$js = (string)@file_get_contents(T_ROOT . '/assets/app.js');
t_ok('date headings and the pinned section only in date order', preg_match('/const byDate = .*?state\.listSort\.by === \'date\'.*?const group = byDate \? dateGroupLabel/s', $js) === 1);
t_ok('the sender sort is named for what IMAP sorts by: the address', strpos((string)@file_get_contents(T_ROOT . '/inbox.php'), 'Sender address A–Z') !== false && strpos($js, "'by sender address'") !== false);

t_group('contacts: richer fields, wired');
$h = (string)@file_get_contents(T_ROOT . '/settings.php');
foreach (['contactPhone', 'contactCompany', 'contactRole', 'contactGroupsInput', 'contactNotes'] as $id) {
    t_ok("the contact form has #$id, read and filled", strpos($h, 'id="' . $id . '"') !== false && substr_count($h, "\$('" . $id . "')") >= 2);
}
t_ok('import, export and duplicates are reachable', strpos($h, 'action=import') !== false && strpos($h, 'action=export&amp;format=vcf') !== false && strpos($h, 'action=export&amp;format=csv') !== false && strpos($h, "api('duplicates')") !== false && strpos($h, 'action=merge') !== false);
$js = (string)@file_get_contents(T_ROOT . '/assets/app.js');
t_ok('a group in compose becomes its members', preg_match('/function acChoose.*?c\.group\s*\?\s*\(c\.emails/s', $js) === 1 && strpos($js, 'ac-item-group') !== false);
$a = (string)@file_get_contents(T_ROOT . '/ajax/contacts.php');
t_ok('an import file is bounded and parsed by content', strpos($a, '4 * 1048576') !== false && strpos($a, 'contacts_parse_any(') !== false);
t_ok('export downloads as a file', strpos($a, 'Content-Disposition: attachment') !== false);

t_group('stay signed in: wired, and only the cookie can open it');
$sess = (string)@file_get_contents(T_ROOT . '/lib/session.php');
t_ok('session_boot signs a remembered device in', strpos($sess, 'remember_boot()') !== false && preg_match('/session_enforce_lifetime\(\);.*?remember_boot\(\)/s', $sess) === 1);
$ix = (string)@file_get_contents(T_ROOT . '/index.php');
t_ok('the login form offers it and issues on success', strpos($ix, 'name="remember"') !== false && preg_match("/login_throttle_record\\(\\\$email, true\\);.*?remember_issue\\(/s", $ix) === 1);
$lo = (string)@file_get_contents(T_ROOT . '/logout.php');
t_ok('logout forgets the device', strpos($lo, 'remember_forget_current()') !== false);
$rm = (string)@file_get_contents(T_ROOT . '/lib/remember.php');
t_ok('the cookie is HttpOnly and SameSite', strpos($rm, "'httponly' => true, 'samesite' => 'Lax'") !== false);
t_ok('the key is derived from the secret on every use', strpos($rm, "hash('sha256', (string)\$secret, true)") !== false && strpos($rm, 'remember_key($t[\'secret\'])') !== false && strpos($rm, "remember_key(\$token['secret'])") !== false);
t_ok('a wrong secret revokes the device', preg_match('/function remember_open.*?remember_revoke\(\$token\[\'selector\'\]\).*?wrong secret/s', $rm) === 1);
$h = (string)@file_get_contents(T_ROOT . '/settings.php');
t_ok('Security pane and nav', strpos($h, 'data-target="security"') !== false && strpos($h, '<section id="security"') !== false && strpos($h, 'id="secForgetAll"') !== false);
$a = (string)@file_get_contents(T_ROOT . '/ajax/security.php');
t_ok('the endpoint needs a session and CSRF on writes, and never shows selectors', strpos($a, "empty(\$_SESSION['email'])") !== false && strpos($a, 'csrf_require()') !== false && strpos($a, 'remember_list(') !== false);

t_group('the installation registry: wired, and nobody else may drive it');
$r = (string)@file_get_contents(T_ROOT . '/registry.php');
t_ok('the ingest endpoint is inert unless this install is the registry', strpos($r, 'if (!$server) {') !== false && strpos($r, "'Not found'") !== false);
t_ok('…never opens a session',            strpos($r, 'session_boot') === false && strpos($r, 'session_start') === false);
t_ok('…takes POST only',                   strpos($r, "'POST required'") !== false);
t_ok('…verifies the signature before storing', preg_match('/registry_sig_ok\(.*?\n.*?Forbidden.*?registry_store\(/s', $r) === 1);
t_ok('…bounds the body',                   strpos($r, 'strlen($body) > 8192') !== false);
t_ok('…and signs its reply, so only the registry can ask an install to update', strpos($r, "\$reply['sig'] = registry_sign(") !== false);
$l = (string)@file_get_contents(T_ROOT . '/lib/registry.php');
t_ok('an install verifies the reply before acting on it', preg_match('/function registry_checkin.*?registry_sig_ok\(\$canon.*?registry_apply_update\(\)/s', $l) === 1);
t_ok('…and only when its own switch allows it', preg_match('/function registry_checkin.*?empty\(\$c\[.auto_update.\]\).*?registry_apply_update\(\)/s', $l) === 1);
t_ok('…and only where a visitor is not left waiting', preg_match('/function registry_checkin.*?registry_may_apply\(\$background\).*?registry_apply_update\(\)/s', $l) === 1);
t_ok('a redirected POST keeps its payload (these hosts rewrite /registry.php)', strpos($l, 'CURLOPT_POSTREDIR') !== false);
t_ok('an install that was never pointed at a registry sends nothing', preg_match('/function registry_tick.*?registry_configured\(\)/s', $l) === 1);
$sess = (string)@file_get_contents(T_ROOT . '/lib/session.php');
t_ok('every request ends with a check-in',  strpos($sess, 'registry_tick()') !== false);
$c = (string)@file_get_contents(T_ROOT . '/lib/cron.php');
t_ok('the scheduler is where an update is applied', preg_match('/registry_configured\(\)\) \$summary\[.registry.\] = registry_checkin\(true\)/', $c) === 1);
$a = (string)@file_get_contents(T_ROOT . '/ajax/registry.php');
t_ok('every write is the admin\'s',         strpos($a, 'reg_admin_only($isAdmin);') !== false && strpos($a, 'csrf_require()') !== false);
t_ok('the key is never shown to anyone else', strpos($a, "(\$server && \$isAdmin) ? \$server['secret'] : ''") !== false);
t_ok('a registry address must be https (or a loopback for development)', strpos($a, "strpos(\$url, 'https://') !== 0 && !\$local") !== false);
$h = (string)@file_get_contents(T_ROOT . '/settings.php');
t_ok('Installations pane and nav',          strpos($h, 'data-target="installations"') !== false && strpos($h, '<section id="installations"') !== false && strpos($h, 'id="regUpdateAll"') !== false);
t_ok('Diagnostics names the registry, so where an install reports is never hidden', strpos($h, 'id="diagRegistry"') !== false);
t_ok('an install that has just rewritten its own files says so', strpos($h, 'This install updated itself') !== false);
t_ok('the real updater is reached only through a seam, so no test can rewrite the tree', preg_match('/function registry_apply_update.*?REGISTRY_APPLY.*?update_apply\\(\\)/s', $l) === 1 && substr_count($l, 'update_apply()') === 1);

t_group('automatic updates: every copy follows a release on its own');
$a = (string)@file_get_contents(T_ROOT . '/lib/autoupdate.php');
t_ok('on unless switched off',               preg_match('/function auto_enabled.*?!array_key_exists\(.auto., \$c\)/s', $a) === 1);
t_ok('the repository is asked on a schedule, not on every request', strpos($a, 'UPDATE_AUTO_INTERVAL') !== false && preg_match('/last_check.*?UPDATE_AUTO_INTERVAL/s', $a) === 1);
t_ok('a failing release is not retried in a loop', strpos($a, 'failed_sha') !== false && strpos($a, 'UPDATE_AUTO_RETRY') !== false);
t_ok('files are only rewritten where a visitor is not left waiting', preg_match('/auto_may_apply\(\$background\).*?auto_check\(\)/s', $a) === 1);
t_ok('GitHub and the updater are both behind seams', strpos($a, "AUTOUPDATE_CHECK") !== false && strpos($a, "AUTOUPDATE_APPLY") !== false
     && substr_count($a, 'update_check()') === 1 && substr_count($a, 'update_apply()') === 1);
$c = (string)@file_get_contents(T_ROOT . '/lib/cron.php');
t_ok('the scheduler follows the repository before it reports in', preg_match('/auto_tick\(true\).*?registry_checkin\(true\)/s', $c) === 1);
$sess = (string)@file_get_contents(T_ROOT . '/lib/session.php');
t_ok('…and so does an ordinary request, where that is safe', strpos($sess, 'auto_tick(false)') !== false);
$h = (string)@file_get_contents(T_ROOT . '/settings.php');
t_ok('the switch is in Software update, with what it last did', strpos($h, 'id="updateAutoToggle"') !== false && strpos($h, 'id="updateAutoState"') !== false);
$u = (string)@file_get_contents(T_ROOT . '/ajax/update.php');
t_ok('…and only the admin may throw it', preg_match("/action === 'auto'/", $u) === 1 && strpos($u, 'csrf_require();') !== false);
$lib = (string)@file_get_contents(T_ROOT . '/tests/lib.php');
t_ok('THE SUITE CANNOT REACH GITHUB OR REWRITE THE TREE', strpos($lib, 'function t_safe_seams') !== false && strpos($lib, 't_safe_seams();') !== false
     && strpos($lib, 'AUTOUPDATE_APPLY') !== false && strpos($lib, 'REGISTRY_APPLY') !== false);

t_group('search: a sentence is understood, not spelled out');
$f = (string)@file_get_contents(T_ROOT . '/ajax/fetch.php');
t_ok('the body is searched unless speed is asked for', strpos($f, "(\$_GET['scope'] ?? '') !== 'headers'") !== false);
t_ok('the rungs are tried in order and the first answer wins', preg_match('/foreach \(\$plan\[.attempts.\] as \$attempt\).*?if \(\(int\)\$try\[.total.\] > 0\) break;/s', $f) === 1);
t_ok('…and the page is told which rung answered', strpos($f, "'matched'        => \$used['mode']") !== false && strpos($f, "'note'           =>") !== false);
t_ok('ranking holds the whole candidate set before paging', strpos($f, '$need     = $rank ? $reach :') !== false);
t_ok('…and asks the server which words each message has', preg_match('/foreach \(\$rank\[.words.\] as \$w\).*?imap_search\(\$box, search_word_key/s', $f) === 1);
t_ok('…bounded, so a long query cannot fan out', strpos($f, 'count($words) <= 6 && count($folders) <= 8') !== false);
$l = (string)@file_get_contents(T_ROOT . '/lib/search.php');
t_ok('a quoted phrase is never stemmed', preg_match('/function search_terms.*?preg_match\(.\/\\\\s\/., \$t\).*?exact/s', $l) === 1);
t_ok('operators are carried onto every rung, not relaxed', preg_match('/function search_plan.*?\$fixed = /s', $l) === 1);

t_group('search: best match is a choice, and the page says what it did');
$js = (string)@file_get_contents(T_ROOT . '/assets/app.js');
t_ok('the whole message is searched by default', strpos($js, "scope = scope || 'full'") !== false);
t_ok('best-match order is asked for explicitly', strpos($js, "searchRank = (v === 'best')") !== false && strpos($js, "'&rank=' + (searchRank ? '1' : '0')") !== false);
t_ok('…and the cache knows the difference', substr_count($js, "searchRank ? 'best' : 'order'") >= 2);
t_ok('the rung that answered is shown above the rows', preg_match('/searchNote\s+= data\.note/', $js) === 1 && strpos($js, "escapeHtml(searchNote)") !== false);
t_ok('date headings are dropped when the rows are in best-match order', strpos($js, "searchSort === 'date' && !searchRanked") !== false);
$ib = (string)@file_get_contents(T_ROOT . '/inbox.php');
t_ok('the sort control offers it first', preg_match('/<option value="best">Best match<\/option>\s*<option value="date:desc">Newest first/', $ib) === 1);
$f = (string)@file_get_contents(T_ROOT . '/ajax/fetch.php');
t_ok('the server takes the choice, and defaults to it', strpos($f, "isset(\$_GET['rank']) ? (\$_GET['rank'] === '1')") !== false);
t_ok('a whole-message search is bounded in time', strpos($f, '$deadline = microtime(true) + 12.0') !== false
     && strpos($f, "microtime(true) > \$rank['deadline']") !== false
     && preg_match('/if \(microtime\(true\) > \$deadline\) break;/', $f) === 1);
