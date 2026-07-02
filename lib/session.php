<?php
/**
 * Centralized session bootstrap + auth-security helpers.
 *
 * Every entry point includes this and calls session_boot() FIRST — before any
 * output and before reading $_SESSION — so the session cookie is always created
 * with hardened flags and stale sessions expire uniformly. The session id is the
 * only thing standing between an attacker and the live IMAP/SMTP credentials
 * held in $_SESSION, so these controls matter.
 *
 * Pure PHP, no dependencies. The login throttle stores nothing in the clear and
 * lives under data/ (web-denied by data/.htaccess).
 */

if (!function_exists('session_boot')) {
    function session_boot() {
        // Never leak PHP warnings/fatals (with absolute server paths + partial
        // output) to the browser — some shared hosts ship display_errors=On. Log
        // them instead, so an uncaught error surfaces as our clean JSON/page, not
        // a stack trace. Runs first on every entry point.
        @ini_set('display_errors', '0');
        @ini_set('log_errors', '1');
        error_reporting(E_ALL & ~E_DEPRECATED);
        if (session_status() === PHP_SESSION_ACTIVE) return;

        // Detect HTTPS directly or behind a TLS-terminating proxy.
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
              || (($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '') === 'on');

        // Refuse uninitialized/attacker-chosen session ids and SID-in-URL.
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            // Only mark Secure when actually on HTTPS, so plain-HTTP testing
            // still works while production (behind TLS) gets the flag.
            'secure'   => $https,
        ]);

        session_start();
        session_enforce_lifetime();
    }
}

if (!function_exists('session_enforce_lifetime')) {
    /**
     * Expire a logged-in session past the absolute or idle cap. Clearing
     * $_SESSION makes every endpoint's existing auth check reject the request
     * (401 for AJAX, redirect to login for pages) with no per-endpoint changes.
     */
    function session_enforce_lifetime() {
        if (empty($_SESSION['email'])) return;
        $now      = time();
        $ABSOLUTE = 12 * 3600; // hard cap measured from login
        $IDLE     =  2 * 3600; // sliding inactivity cap
        $login    = (int)($_SESSION['login_time'] ?? 0);
        $seen     = (int)($_SESSION['last_seen']  ?? $login);
        if (($login && $now - $login > $ABSOLUTE) || ($seen && $now - $seen > $IDLE)) {
            $_SESSION = [];
            @session_regenerate_id(true);
            return;
        }
        $_SESSION['last_seen'] = $now;
    }
}

/* ---------- Login brute-force throttle (dependency-free) ---------- */

if (!function_exists('login_throttle_file')) {
    function login_throttle_file($email) {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        // Hash IP+email so neither is stored in the clear.
        $key = hash('sha256', strtolower(trim((string)$email)) . '|' . $ip);
        return __DIR__ . '/../data/ratelimit/' . $key . '.json';
    }
}

if (!function_exists('login_throttle_retry_after')) {
    /** Seconds the client must wait before another attempt, or 0 if allowed. */
    function login_throttle_retry_after($email) {
        $f = login_throttle_file($email);
        if (!is_file($f)) return 0;
        $d = @json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || empty($d['fails'])) return 0;
        $now    = time();
        $WINDOW = 900; // count failures within the last 15 min
        $MAX    = 8;   // ...up to this many
        $LOCK   = 900; // then lock out for 15 min from the latest failure
        $fails  = array_filter((array)$d['fails'], fn($t) => $now - (int)$t < $WINDOW);
        if (count($fails) < $MAX) return 0;
        $wait = $LOCK - ($now - max($fails));
        return $wait > 0 ? $wait : 0;
    }
}

if (!function_exists('login_throttle_record')) {
    /** Record an attempt. On success the counter is cleared. */
    function login_throttle_record($email, $ok) {
        $dir = __DIR__ . '/../data/ratelimit';
        $f   = login_throttle_file($email);
        if ($ok) { @unlink($f); return; }
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $fp = @fopen($f, 'c+');
        if (!$fp) return;
        @flock($fp, LOCK_EX);
        $d     = @json_decode((string)stream_get_contents($fp), true);
        $now   = time();
        $fails = (is_array($d) && !empty($d['fails'])) ? (array)$d['fails'] : [];
        $fails[] = $now;
        // Keep only the last hour so the file can't grow without bound.
        $fails = array_values(array_filter($fails, fn($t) => $now - (int)$t < 3600));
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode(['fails' => $fails]));
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

/* ---------- Content-Security-Policy (XSS backstop) ---------- */

if (!function_exists('csp_nonce')) {
    /** Per-request nonce (hex, no special chars) for whitelisting inline <script>. */
    function csp_nonce() {
        static $nonce = null;
        if ($nonce === null) $nonce = bin2hex(random_bytes(16));
        return $nonce;
    }
}

if (!function_exists('csp_header')) {
    /**
     * Emit the CSP + related security headers for an HTML page. Must be called
     * before any output. This is the backstop behind the HTML sanitizer: even
     * if a crafted message slipped a handler past the regex filter, the policy
     * blocks inline-handler execution and non-allowlisted script loads.
     *
     * Allowlist rationale: jsdelivr hosts the on-demand Office/PDF preview libs
     * (xlsx/jszip/docx-preview); Google Fonts serves the UI font; inline <script>
     * blocks carry the per-request nonce; inline style="" attributes need
     * 'unsafe-inline' in style-src. img-src stays permissive so remote images in
     * mail still render.
     */
    function csp_header() {
        if (headers_sent()) return;
        $n = csp_nonce();
        $policy = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-$n' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self'",
            "img-src 'self' data: https: blob:",
            "connect-src 'self'",
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
        ]);
        header('Content-Security-Policy: ' . $policy);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
}
