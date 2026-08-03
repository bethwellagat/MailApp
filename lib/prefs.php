<?php
/**
 * Per-user preference storage. Keyed by sha256(email).
 * Stored as JSON in data/prefs/. The data/ directory has an .htaccess
 * that denies all web access.
 */

require_once __DIR__ . '/util.php'; // atomic_write_json()

function _prefs_dir() {
    return __DIR__ . '/../data/prefs';
}

function _prefs_file($email) {
    $dir = _prefs_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/' . hash('sha256', strtolower(trim($email))) . '.json';
}

function default_prefs() {
    return [
        'signature'           => '',
        'auto_append'         => true,
        'append_on_replies'   => true,
        'workspace_logo'      => '',
        'density'             => 'compact', // comfortable | cozy | compact
        'theme'               => 'system',  // system | light | dark
        'notifications'       => false,     // desktop new-mail notifications
        'display_name'        => '',        // From-name override, editable in Settings (empty = use login value)
        'remote_images'       => false,     // false = block remote images until asked (stops sender read-tracking)
    ];
}

function load_prefs($email) {
    $defaults = default_prefs();
    if (!$email) return $defaults;
    $file = _prefs_file($email);
    if (!is_file($file)) return $defaults;
    $raw = @file_get_contents($file);
    if ($raw === false) return $defaults;
    $data = @json_decode($raw, true);
    if (!is_array($data)) return $defaults;
    return array_merge($defaults, $data);
}

function save_prefs($email, $changes) {
    if (!$email || !is_array($changes)) return false;
    $file = _prefs_file($email);
    // Serialize the load→merge→write so two concurrent saves (e.g. theme in one
    // tab, density in another) can't clobber each other's keys. We hold an
    // exclusive lock across the fresh read and the write, so each save merges
    // its changes over the very latest on-disk state.
    $lock = @fopen($file . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $current = load_prefs($email);
    $merged  = array_merge($current, $changes);
    $ok      = atomic_write_json($file, $merged);
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
    return $ok;
}

define('SIGNATURE_MAX_BYTES', 1048576); // 1MB
define('LOGO_MAX_BYTES', 524288);       // 512KB

function sanitize_logo_data_uri($value) {
    if ($value === '' || $value === null) return '';
    if (!is_string($value)) return '';
    // Raster images only — SVG is excluded because it can carry <script>.
    if (!preg_match('#^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=\r\n]+)$#i', $value, $m)) {
        return null;
    }
    // Verify the bytes actually match the declared type so a non-image payload
    // can't masquerade under an image/* label.
    $type = strtolower($m[1]);
    $bin  = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($bin === false || strlen($bin) < 12) return null;
    if ($type === 'png'  && substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
    if ($type === 'jpeg' && substr($bin, 0, 3) !== "\xFF\xD8\xFF")      return null;
    if ($type === 'gif'  && substr($bin, 0, 4) !== 'GIF8')              return null;
    if ($type === 'webp' && !(substr($bin, 0, 4) === 'RIFF' && substr($bin, 8, 4) === 'WEBP')) return null;
    return $value;
}

/**
 * Best-effort regex sanitizer for the rich-text signature / out-of-office body.
 * Defense-in-depth, not a full HTML parser: it strips scriptable elements,
 * inline event handlers, and dangerous URL schemes while preserving the simple
 * formatting (bold/italic/links/lists) and inline raster images these editors
 * actually produce.
 */
function sanitize_signature_html($html) {
    if ($html === '' || $html === null) return '';

    // 1+2) Remove scriptable/embedding elements (with their content) and the
    //       standalone resource/script-injecting tags. Run to a FIXED POINT so a
    //       tag reassembled by removing an inner one (e.g. <scr<script>ipt>) is
    //       caught on a later pass. Bounded to avoid pathological inputs.
    $withContent = ['script','style','iframe','object','embed','applet',
                    'svg','math','template','noscript','frame','frameset','title'];

    // Inline event handlers are stripped INSIDE tag markup only — scoping it to
    // tags closes the `<img src="x"onerror=…>` bypass (an attribute can start
    // right after the quote that closed the previous value, and browsers run it)
    // and stops the filter eating ordinary prose. The boundary character is
    // captured and restored so the preceding attribute stays intact. Mirrors
    // sanitize_html() in ajax/fetch.php — keep the two in step.
    $stripHandlers = 'strip_event_handlers'; // shared with sanitize_html — see lib/util.php
    $tagRe = TAG_MATCH_RE;

    $pass = 0;
    do {
        $before = $html;
        foreach ($withContent as $tag) {
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', '', $html);
            $html = preg_replace('#</?' . $tag . '\b[^>]*>#i', '', $html); // stray open/close
        }
        $html = preg_replace('#<\s*/?\s*(link|meta|base|param|form|input|button)\b[^>]*>#i', '', $html);
        // In-loop so a handler revealed by tag removal, and chained handlers, are
        // peeled off on later passes.
        // Never assign a possibly-NULL preg result straight onto $html: on a PCRE
        // limit that would blank the entire message body (and, in prefs, SAVE an
        // empty signature). Keep the last good value instead.
        $stripped = preg_replace_callback($tagRe, fn($m) => $stripHandlers($m[0]), $html);
        if ($stripped !== null) $html = $stripped;
    } while ($html !== $before && ++$pass < 30);

    // 4) Neutralize dangerous URL schemes on attributes that navigate or load.
    $urlAttrs = 'href|src|xlink:href|action|formaction|background|poster|cite|longdesc|dynsrc|lowsrc';
    // javascript:/vbscript: (quoted then unquoted)
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)("|\')\s*(?:javascript|vbscript)\s*:[^"\']*\2#i', '$1$2#$2', $html);
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)(?:javascript|vbscript)\s*:[^\s>]*#i', '$1#', $html);
    // data: URIs except allowed raster images (quoted then unquoted)
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)("|\')\s*data\s*:\s*(?!image/(?:png|jpeg|gif|webp)\b)[^"\']*\2#i', '$1$2#$2', $html);
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)data\s*:\s*(?!image/(?:png|jpeg|gif|webp)\b)[^\s>]*#i', '$1#', $html);

    // 5) Defuse CSS-based vectors inside style="" / style=''.
    // Same defuser as inbound mail (lib/util.php). These two had drifted: only
    // sanitize_html() received the comment/escape/position/z-index hardening, so a
    // signature or out-of-office body could still carry a viewport-escaping style.
    $defuseCss = 'defuse_inline_css';
    $html = preg_replace_callback('#(\sstyle\s*=\s*")([^"]*)(")#i', fn($m) => $m[1] . $defuseCss($m[2]) . $m[3], $html);
    $html = preg_replace_callback("#(\sstyle\s*=\s*')([^']*)(')#i", fn($m) => $m[1] . $defuseCss($m[2]) . $m[3], $html);
    // Unquoted form: style=position:fixed;z-index:9999 is valid HTML and the two
    // callbacks above only see quoted values.
    $html = preg_replace_callback('#(\sstyle\s*=\s*)([^"\'\s>][^\s>]*)#i', fn($m) => $m[1] . $defuseCss($m[2]), $html);

    return $html;
}
