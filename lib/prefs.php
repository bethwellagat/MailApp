<?php
/**
 * Per-user preference storage. Keyed by sha256(email).
 * Stored as JSON in data/prefs/. The data/ directory has an .htaccess
 * that denies all web access.
 */

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
    $tmp     = $file . '.tmp';
    $ok = false;
    if (@file_put_contents($tmp, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @chmod($tmp, 0600);
        $ok = @rename($tmp, $file);
    }
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
    $pass = 0;
    do {
        $before = $html;
        foreach ($withContent as $tag) {
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', '', $html);
            $html = preg_replace('#</?' . $tag . '\b[^>]*>#i', '', $html); // stray open/close
        }
        $html = preg_replace('#<\s*/?\s*(link|meta|base|param|form|input|button)\b[^>]*>#i', '', $html);
    } while ($html !== $before && ++$pass < 30);

    // 3) Strip inline event handlers (onclick=, onerror=, …) in any quoting
    //    style. Anchor on whitespace OR '/', so a slash-separated handler in a
    //    compact tag (e.g. <img src=x/onerror=…>) cannot slip past the filter.
    $html = preg_replace('#[\s/]on[a-z0-9_-]+\s*=\s*"[^"]*"#i', '', $html);
    $html = preg_replace("#[\s/]on[a-z0-9_-]+\s*=\s*'[^']*'#i", '', $html);
    $html = preg_replace('#[\s/]on[a-z0-9_-]+\s*=\s*[^\s>]+#i', '', $html);

    // 4) Neutralize dangerous URL schemes on attributes that navigate or load.
    $urlAttrs = 'href|src|xlink:href|action|formaction|background|poster|cite|longdesc|dynsrc|lowsrc';
    // javascript:/vbscript: (quoted then unquoted)
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)("|\')\s*(?:javascript|vbscript)\s*:[^"\']*\2#i', '$1$2#$2', $html);
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)(?:javascript|vbscript)\s*:[^\s>]*#i', '$1#', $html);
    // data: URIs except allowed raster images (quoted then unquoted)
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)("|\')\s*data\s*:\s*(?!image/(?:png|jpeg|gif|webp)\b)[^"\']*\2#i', '$1$2#$2', $html);
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)data\s*:\s*(?!image/(?:png|jpeg|gif|webp)\b)[^\s>]*#i', '$1#', $html);

    // 5) Defuse CSS-based vectors inside style="" / style=''.
    $defuseCss = function ($css) {
        return preg_replace('#(expression|behavio[u]?r|javascript|vbscript|@import)\s*[:(]#i', 'blocked-', $css);
    };
    $html = preg_replace_callback('#(\sstyle\s*=\s*")([^"]*)(")#i', fn($m) => $m[1] . $defuseCss($m[2]) . $m[3], $html);
    $html = preg_replace_callback("#(\sstyle\s*=\s*')([^']*)(')#i", fn($m) => $m[1] . $defuseCss($m[2]) . $m[3], $html);

    return $html;
}
