<?php
/**
 * Rendering and sanitizing the HTML/text of a mail message.
 *
 * Extracted from ajax/fetch.php so these can be tested directly: an endpoint file
 * EXECUTES when included, so tests previously had to lift sanitize_html() out by
 * tokenizing the source. They are pure string functions with no session, IMAP or
 * request state, which is exactly why they belong in a library.
 */

require_once __DIR__ . '/util.php'; // strip_event_handlers(), defuse_inline_css(), TAG_MATCH_RE

/**
 * Find where the "previous message" / quoted content starts in a rendered HTML
 * body. Returns the byte offset, or false if no quote boundary is detected.
 *
 * Detection heuristics, ordered by reliability:
 *  - <blockquote> tag (Apple Mail, Gmail, this app's own quoted block)
 *  - <hr> immediately preceding "From: … Sent: … Subject:" (Outlook reply quote)
 *  - <div id="appendonsend" / id="divRplyFwdMsg" (Outlook on the web)
 *  - <div class="gmail_quote">
 *  - "On <date>, <name> wrote:" line wrapped in a tag
 */
function find_quote_cutoff($html) {
    if ($html === '' || $html === null) return false;
    $candidates = [];

    if (preg_match('/<blockquote\b/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = $m[0][1];
    }
    if (preg_match('/<div\s+id\s*=\s*["\']appendonsend["\']/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = $m[0][1];
    }
    if (preg_match('/<div\s+id\s*=\s*["\']divRplyFwdMsg["\']/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = $m[0][1];
    }
    if (preg_match('/<div\s+class\s*=\s*["\'][^"\']*\bgmail_quote\b/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = $m[0][1];
    }
    if (preg_match('/<hr\b[^>]*>(?=[\s\S]{0,1500}?\bFrom:[\s\S]{0,500}?\bSent:[\s\S]{0,500}?\bSubject:)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = $m[0][1];
    }

    // Outlook reply quote without an <hr> separator — find the "From:" that's
    // followed shortly by Sent: + Subject:, then walk back to the containing
    // element so the trim covers the whole quote block, not just text.
    if (preg_match_all('/\bFrom:/', $html, $froms, PREG_OFFSET_CAPTURE)) {
        foreach ($froms[0] as $fmatch) {
            $pos    = $fmatch[1];
            $window = substr($html, $pos, 1500);
            if (stripos($window, 'Sent:') !== false && stripos($window, 'Subject:') !== false) {
                $tagStart = strrpos(substr($html, 0, $pos), '<');
                if ($tagStart !== false && substr($html, $tagStart + 1, 1) !== '/') {
                    $candidates[] = $tagStart;
                }
                break;
            }
        }
    }

    // "On <date>, <name> wrote:" — anchored on a date-like token (Today /
    // Yesterday / digit / weekday / month) so phrases like "on holiday … wrote"
    // don't false-match. Walk back to the start of the containing element.
    $datePrefix = '(?:Today|Yesterday|\d|(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[,\s])';
    if (preg_match('/\bOn\s+' . $datePrefix . '[^<]{1,250}?\swrote\s*:/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $onPos    = $m[0][1];
        $tagStart = strrpos(substr($html, 0, $onPos), '<');
        $candidates[] = ($tagStart !== false && substr($html, $tagStart + 1, 1) !== '/')
            ? $tagStart
            : $onPos;
    }

    if (empty($candidates)) return false;
    return min($candidates);
}

/**
 * Split rendered body into (visible main, collapsed quote). Only trims when
 * the "main" portion has substantive content, so a fully-quoted forward isn't
 * left blank.
 */
function trim_quoted($html) {
    $cutoff = find_quote_cutoff($html);
    if ($cutoff === false) return ['main' => $html, 'quote' => ''];

    $main  = substr($html, 0, $cutoff);
    $quote = substr($html, $cutoff);
    if (mb_strlen(trim(strip_tags($main))) < 30) {
        return ['main' => $html, 'quote' => ''];
    }
    return ['main' => $main, 'quote' => $quote];
}

/**
 * Wrap the rendered body so the quoted/forwarded portion collapses behind
 * a button. Keeps the main reply visible by default, hides the rest.
 */
function wrap_collapsible_quote($body) {
    $split = trim_quoted($body);
    if ($split['quote'] === '') return $body;
    return $split['main']
         . '<div class="email-quote-trim">'
         . '<button class="email-quote-toggle" type="button" aria-expanded="false"'
         .   ' aria-label="Show trimmed content" title="Show trimmed content">'
         .   '<span class="email-quote-dot"></span><span class="email-quote-dot"></span><span class="email-quote-dot"></span>'
         . '</button>'
         . '<div class="email-quote-content" hidden>' . $split['quote'] . '</div>'
         . '</div>';
}

function plain_to_html($text) {
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $linked  = preg_replace(
        '#(https?://[^\s<>"\']+)#i',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $escaped
    );
    return '<div style="white-space:pre-wrap;font-family:inherit;">' . $linked . '</div>';
}

/**
 * Sanitize inbound (attacker-controlled) HTML email for display — the highest-risk
 * surface in the app. Strips scriptable/embedding elements, inline event handlers,
 * dangerous URL schemes (javascript:/vbscript: and any non-raster data: URI), and
 * CSS-based vectors, while preserving the layout, inline styles, links, and raster
 * images legitimate mail relies on. Mirrors the rigor of sanitize_signature_html()
 * in lib/prefs.php; kept separate so the two contexts can be tuned independently.
 */
function sanitize_html($html) {
    if ($html === '' || $html === null) return '';

    // 1+2) Remove scriptable/embedding elements (with their content) and the
    //       standalone tags that inject script/resources or hijack URLs
    //       (<base> can rewrite every relative link). Run to a FIXED POINT so a
    //       tag reassembled by removing an inner one (e.g. <scr<script>ipt>) is
    //       caught on a later pass. Bounded to avoid pathological inputs.
    $withContent = ['script','style','iframe','object','embed','applet',
                    'svg','math','template','noscript','frame','frameset','title'];

    // Inline event handlers (onclick=, onerror=, …) are stripped INSIDE tag markup
    // only. A handler is executable solely as an attribute, so scoping it to tags
    // both closes a bypass and stops the filter eating ordinary prose — an email
    // reading "set once=true and only=false" previously lost those words.
    //
    // Within a tag an attribute may begin after whitespace, '/', OR the quote that
    // closed the previous value: `<img src="x"onerror=alert(1)>` is the classic
    // bypass of a whitespace-only anchor, and browsers do parse and run it. The
    // boundary character is captured and restored so the preceding attribute (and
    // its closing quote) survives intact.
    $stripHandlers = 'strip_event_handlers';
    // Possessive quantifiers: no backtracking, so a pathological body can't stall
    // this. The quoted alternatives let an attribute value legitimately contain
    // '>'; the trailing ["'] alternative consumes a LONE unbalanced quote, without
    // which a tag like <img src=x" onerror=…> failed to match at all and its
    // handler was left untouched.
    $tagRe = TAG_MATCH_RE;

    $pass = 0;
    do {
        $before = $html;
        foreach ($withContent as $tag) {
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', '', $html);
            $html = preg_replace('#</?' . $tag . '\b[^>]*>#i', '', $html); // stray open/close
        }
        $html = preg_replace('#<\s*/?\s*(link|meta|base|param|form|input|button)\b[^>]*>#i', '', $html);
        // Inside the fixed-point loop so a handler revealed by removing a tag, and
        // chained handlers (`"onerror="a()"onload=b()`), are peeled off on later passes.
        // Never assign a possibly-NULL preg result straight onto $html: on a PCRE
        // limit that would blank the entire message body (and, in prefs, SAVE an
        // empty signature). Keep the last good value instead.
        $stripped = preg_replace_callback($tagRe, fn($m) => $stripHandlers($m[0]), $html);
        if ($stripped !== null) $html = $stripped;
    } while ($html !== $before && ++$pass < 30);
    // Unwrap document-structure tags but keep their inner content. Also drop any
    // stray <!doctype …> — harmless when rendered, but a doctype node at the start
    // of a contenteditable (e.g. a resumed draft) breaks the editor's line breaks.
    $html = preg_replace('#</?(html|head|body)\b[^>]*>#i', '', $html);
    $html = preg_replace('#<!doctype[^>]*>#i', '', $html);

    // (3) Inline event handlers are stripped inside the fixed-point loop above.

    // 4) Neutralize dangerous URL schemes on attributes that navigate or load.
    $urlAttrs = 'href|src|xlink:href|action|formaction|background|poster|cite|longdesc|dynsrc|lowsrc';
    // javascript:/vbscript: (quoted then unquoted)
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)("|\')\s*(?:javascript|vbscript)\s*:[^"\']*\2#i', '$1$2#$2', $html);
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)(?:javascript|vbscript)\s*:[^\s>]*#i', '$1#', $html);
    // data: URIs except allowed raster images — blocks data:image/svg+xml etc. (quoted then unquoted)
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)("|\')\s*data\s*:\s*(?!image/(?:png|jpeg|gif|webp)\b)[^"\']*\2#i', '$1$2#$2', $html);
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)data\s*:\s*(?!image/(?:png|jpeg|gif|webp)\b)[^\s>]*#i', '$1#', $html);

    // 5) Defuse CSS-based vectors inside inline styles.
    $defuseCss = 'defuse_inline_css'; // shared with sanitize_signature_html — see lib/util.php
    $html = preg_replace_callback('#(\sstyle\s*=\s*")([^"]*)(")#i', fn($m) => $m[1] . $defuseCss($m[2]) . $m[3], $html);
    $html = preg_replace_callback("#(\sstyle\s*=\s*')([^']*)(')#i", fn($m) => $m[1] . $defuseCss($m[2]) . $m[3], $html);
    // Unquoted form: `style=position:fixed;z-index:9999` is valid HTML (an unquoted
    // value simply runs to the next space or '>'), and the two callbacks above only
    // see quoted values — so without this the whole defuser is trivially bypassed.
    $html = preg_replace_callback('#(\sstyle\s*=\s*)([^"\'\s>][^\s>]*)#i', fn($m) => $m[1] . $defuseCss($m[2]), $html);

    // 6) Force external links to open safely in a new tab.
    $html = preg_replace('#<a\b([^>]*)>#i', '<a$1 target="_blank" rel="noopener noreferrer">', $html);

    return $html;
}
