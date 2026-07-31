<?php
/**
 * Small shared helpers used across the lib/ modules.
 */

if (!function_exists('ini_bytes')) {
    /**
     * Parse a PHP ini shorthand size ("2M", "512K", "1G", or a plain byte count)
     * into an integer number of bytes. Returns 0 for empty / "-1" (unlimited) /
     * unparseable input, so callers can treat 0 as "no known limit". Mirrors PHP's
     * own K/M/G shorthand (binary — powers of 1024).
     */
    function ini_bytes($val) {
        $val = trim((string)$val);
        if ($val === '' || $val === '-1') return 0;
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)/i', $val, $m)) return 0;
        $n = (float)$m[1];
        switch (strtoupper($m[2])) {
            case 'G': $n *= 1024; // fall through
            case 'M': $n *= 1024; // fall through
            case 'K': $n *= 1024;
        }
        return (int)$n;
    }
}

if (!function_exists('poll_gate')) {
    /**
     * Shared cross-tab/-request throttle for background poll jobs — the in-app
     * "cron" (no real cron on the host). Returns true AT MOST once per $seconds
     * per (email, job) across every open tab, so a job's expensive IMAP work runs
     * once per window instead of once per tab per poll.
     *
     * A per-account timestamp file guarded by a NON-BLOCKING lock: the first caller
     * in the window wins, stamps the time, returns true; concurrent callers fail the
     * lock (or see the fresh timestamp) and return false. Fails OPEN only if the
     * lock/dir is unusable, so a job is never permanently stalled. Cheap: one small
     * file read, plus a write only when due.
     */
    function poll_gate($email, $job, $seconds) {
        if (!$email) return true;
        $dir = __DIR__ . '/../data/poll';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $file = $dir . '/' . hash('sha256', strtolower(trim($email))) . '.json';
        $lock = @fopen($file . '.lock', 'c');
        if (!$lock) return true;                                                 // can't lock → fail open
        if (!@flock($lock, LOCK_EX | LOCK_NB)) { @fclose($lock); return false; } // another request is deciding
        $data = @json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) $data = [];
        $now = time();
        $due = ($now - (int) ($data[$job] ?? 0)) >= (int) $seconds;
        if ($due) {
            $data[$job] = $now;
            atomic_write_json($file, $data); // the last non-atomic data/ writer — now tmp+rename like the rest
        }
        @flock($lock, LOCK_UN);
        @fclose($lock);
        return $due;
    }
}

if (!function_exists('atomic_write_json')) {
    /**
     * Write $data as JSON to $file atomically. Returns true on success.
     *
     * Every per-user store under data/ funnels through here so the same guarantee
     * holds everywhere. The temp file name is UNIQUE PER WRITER, which is the
     * whole point: a fixed "<file>.tmp" is shared by every concurrent writer of
     * the same file, and file_put_contents() TRUNCATES the temp at open BEFORE it
     * can take LOCK_EX. So writer B could blank writer A's temp inside A's
     * write→rename window, and A would then publish an empty/partial file over
     * live user state — silently wiping saved filters, vacation config, or
     * contacts (load_*() sees a non-array and hands back empty defaults).
     * A per-writer temp makes that collision impossible.
     *
     * The rename() itself is the atomic publish step: readers see either the old
     * file or the new one, never a half-written one. No lock is taken on the temp
     * (it is private to this writer); callers that need load→modify→save to be
     * serialized must still hold their own lock around the whole sequence.
     */
    function atomic_write_json($file, $data) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        $tmp = $file . '.' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json) === false) return false;
        @chmod($tmp, 0600);
        if (@rename($tmp, $file)) return true;
        @unlink($tmp); // rename failed (disk full / permissions) — don't leave an orphan
        return false;
    }
}

/* ---------------------------------------------------------------------------
 * TLS trust for IMAP/SMTP.
 *
 * Every connection used to be made with certificate checking switched off
 * (/novalidate-cert, verify_peer=false) while the account password travelled
 * over it — so anyone on the path to a REMOTE mail host could present any
 * certificate and read the password and the whole mailbox.
 *
 * Turning verification on unconditionally would lock people out: cheap boxes
 * routinely serve mail over a self-signed certificate. So:
 *   - loopback / private-range hosts stay relaxed (traffic never leaves the
 *     machine or the LAN, and self-signed is the norm there);
 *   - every other host is VALIDATED;
 *   - if a validated login fails, the login path retries relaxed, records that
 *     this host needs it, and continues. Nobody is ever locked out, and hosts
 *     with a good certificate get real protection everywhere from then on.
 * ------------------------------------------------------------------------- */

if (!function_exists('tls_host_is_local')) {
    /** Loopback or private-range host: no meaningful interception exposure. */
    function tls_host_is_local($host) {
        $h = strtolower(trim((string)$host));
        if ($h === '') return false;
        $h = trim($h, '[]');
        if ($h === 'localhost' || $h === '127.0.0.1' || $h === '::1') return true;
        if (filter_var($h, FILTER_VALIDATE_IP)) {
            // Not-public == private/reserved range.
            return !filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return false;
    }
}

if (!function_exists('tls_relaxed_hosts')) {
    function _tls_state_file() { return __DIR__ . '/../data/tls.json'; }

    /** Hosts already proven to need relaxed TLS (map host => iso timestamp). */
    function tls_relaxed_hosts() {
        $d = @json_decode((string)@file_get_contents(_tls_state_file()), true);
        return is_array($d) ? $d : [];
    }
    function tls_host_needs_relaxed($host) {
        $h = strtolower(trim((string)$host));
        return $h !== '' && isset(tls_relaxed_hosts()[$h]);
    }
    /** Record that $host could only be reached without certificate validation. */
    function tls_remember_relaxed($host) {
        $h = strtolower(trim((string)$host));
        if ($h === '') return;
        $lk = store_lock(_tls_state_file());
        $d  = tls_relaxed_hosts();
        if (!isset($d[$h])) {
            $d[$h] = gmdate('c');
            atomic_write_json(_tls_state_file(), $d);
        }
        store_unlock($lk);
    }
    /** Should this host's certificate be verified? */
    function tls_verify_enabled($host) {
        return !tls_host_is_local($host) && !tls_host_needs_relaxed($host);
    }
    /**
     * IMAP mailbox-ref flags for this host. Single source of truth — every
     * endpoint builds its {host:port...} ref through here.
     */
    function imap_tls_flags($host, $ssl) {
        if (!$ssl) return '/imap/notls';
        return tls_verify_enabled($host) ? '/imap/ssl' : '/imap/ssl/novalidate-cert';
    }
}

if (!function_exists('ensure_data_guards')) {
    /**
     * Make sure data/ carries every web-deny file we can ship, creating any that
     * are missing. Written from CODE rather than shipped as files because the
     * updater deliberately never copies into data/ — so a new protective file
     * placed there in the repo would never reach an existing install. Doing it
     * here means "Update now" heals every deployment.
     *
     * data/ holds signatures, workspace logos, brand overrides, filters,
     * contacts, the outbox and the update token. Serving any of it to the web is
     * a straight PII/secret disclosure.
     *
     *   .htaccess   — Apache (both mod_authz_core and pre-2.4 syntax)
     *   web.config  — IIS
     *   index.html  — empty, so a mis-set autoindex lists nothing
     *
     * nginx/lighttpd read NONE of these — they need a server-block rule, which no
     * PHP code can install. The Settings page therefore probes for exposure from
     * the browser and warns the admin (see the exposure probe file below).
     *
     * Costs one stat() per request once the sentinel exists.
     */
    function ensure_data_guards() {
        $dir      = __DIR__ . '/../data';
        $sentinel = $dir . '/.guards-v1';
        if (is_file($sentinel)) return; // fast path
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return;

        $files = [
            '.htaccess' =>
                "# Deny all web access — this directory holds private user data.\n" .
                "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
                "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
            'web.config' =>
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n" .
                "    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n" .
                "  </system.webServer>\n</configuration>\n",
            'index.html' => "",
            // Harmless known content. If a browser can read this, the whole
            // directory is exposed — Settings fetches it to detect exactly that.
            'exposure-probe.txt' =>
                "webmail-data-directory-is-web-readable\n" .
                "If you can read this over HTTP, your server is serving the private data/\n" .
                "directory. Deny it in your server config (see the note in Settings).\n",
        ];
        foreach ($files as $name => $body) {
            $path = $dir . '/' . $name;
            if (!file_exists($path)) {
                @file_put_contents($path, $body);
                @chmod($path, 0644);
            }
        }
        @file_put_contents($sentinel, "guards written " . gmdate('c') . "\n");
        @chmod($sentinel, 0600);
    }
}

if (!function_exists('store_lock')) {
    /**
     * Bounded exclusive lock for a load→modify→save sequence on a data/ store,
     * using the same "<file>.lock" the background sweeps take, so an interactive
     * save and a sweep serialize against each other.
     *
     * The wait is BOUNDED (and we proceed without the lock if it expires) because
     * the sweeps hold this lock across slow IMAP/SMTP work — a plain blocking
     * flock() here would freeze the user's "Save" button for seconds. Proceeding
     * unlocked is safe: the sweeps reconcile against a fresh read before writing,
     * so they can no longer clobber a user-owned field. The lock just collapses
     * the remaining narrow window between two concurrent interactive saves.
     *
     * Returns a handle for store_unlock(), or false if the lock wasn't acquired
     * (PHP also releases any held lock automatically when the script exits).
     */
    function store_lock($file, $maxWaitMs = 1000) {
        $h = @fopen($file . '.lock', 'c');
        if (!$h) return false;
        $deadline = microtime(true) + ($maxWaitMs / 1000);
        do {
            if (@flock($h, LOCK_EX | LOCK_NB)) return $h;
            usleep(25000); // 25ms
        } while (microtime(true) < $deadline);
        @fclose($h);
        return false;
    }
    function store_unlock($h) {
        if ($h) { @flock($h, LOCK_UN); @fclose($h); }
    }
}

if (!function_exists('gen_uuid')) {
    /** RFC 4122 version-4 UUID, e.g. "f47ac10b-58cc-4372-a567-0e02b2c3d479". */
    function gen_uuid() {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }
}
