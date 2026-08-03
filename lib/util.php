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
 * Inline event-handler removal for sanitized HTML.
 *
 * Shared by sanitize_html() (inbound mail) and sanitize_signature_html() so the
 * two can never drift apart.
 * ------------------------------------------------------------------------- */

if (!defined('TAG_MATCH_RE')) {
    /**
     * Matches one HTML start tag. Quoted alternatives come first so an attribute
     * value may legitimately contain '>'; the final ["'] alternative consumes a
     * LONE unbalanced quote — without it a tag like `<img src=x" onerror=…>`
     * matched nothing at all and its handler survived untouched. Possessive
     * throughout, so no input can cause catastrophic backtracking.
     */
    define('TAG_MATCH_RE', '#<[a-z][a-z0-9:-]*+(?:"[^"]*+"|\'[^\']*+\'|[^>"\']++|["\'])*+>#i');
}

if (!function_exists('defuse_inline_css')) {
    /**
     * Neutralise the dangerous parts of an inline style="" value. Shared by
     * sanitize_html() and sanitize_signature_html() — they previously carried
     * different copies, and only one of them received the hardening.
     */
    function defuse_inline_css($css) {
        // Comments first: a CSS parser drops them, so `expr/**/ession(` and
        // `position:/**/fixed` would otherwise walk straight past every rule below.
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        // Hex escapes (`\66 ixed` resolves to `fixed`) exist here only to obfuscate;
        // real mail never needs a backslash in an inline style. Dropping them turns
        // an escaped keyword into an invalid value instead of a live one.
        $css = str_replace('\\', '', $css);
        // Script-ish and stylesheet-import vectors.
        $css = preg_replace('#(expression|behavio[u]?r|javascript|vbscript|@import)\s*[:(]#i', 'blocked-', $css);
        // Positioning escape: `fixed`/`sticky` lift the element out of the message
        // and pin it to the viewport, so a message can paint a full-screen overlay
        // across the authenticated UI — a working phishing prompt needing no script
        // at all. Neither is legitimate in email. (`absolute` is left alone: it is
        // occasionally used for real layout and is contained by the paint
        // containment on .thread-msg-body.)
        $css = preg_replace('#position\s*:\s*(?:fixed|sticky)#i', 'position:static', $css);
        // Stacking escape: genuine mail layers with single digits; larger values
        // exist to sit on top of the app's own chrome.
        $css = preg_replace_callback('#z-index\s*:\s*(-?\d+)#i',
            fn($m) => abs((int)$m[1]) > 9 ? 'z-index:0' : $m[0], $css);
        return $css;
    }
}

if (!function_exists('strip_event_handlers')) {
    /**
     * Remove on*= attributes from a single start tag.
     *
     * This PARSES the tag's attributes rather than pattern-matching them. A regex
     * cannot tell an attribute's OPENING quote from a CLOSING one, so anchoring on
     * a quote character destroyed ordinary markup: `<a href="online=1">` lost its
     * href entirely, because the opening quote plus `on` + `line=` looked exactly
     * like a handler starting after a closed attribute.
     *
     * Walking name / '=' / value the way a parser does removes that ambiguity: an
     * attribute is dropped only when its NAME really is a handler, and every other
     * attribute is re-emitted exactly as written.
     */
    function strip_event_handlers($tag) {
        if (!preg_match('#^<([a-z][a-z0-9:-]*)#i', $tag, $m)) return $tag;
        $len       = strlen($tag);
        $i         = strlen($m[0]);
        $out       = '<' . $m[1];
        $selfClose = false;

        while ($i < $len) {
            while ($i < $len && (ctype_space($tag[$i]) || $tag[$i] === '/')) {
                if ($tag[$i] === '/') $selfClose = true;
                $i++;
            }
            if ($i >= $len || $tag[$i] === '>') break;

            $start = $i;
            while ($i < $len && !ctype_space($tag[$i]) && $tag[$i] !== '=' && $tag[$i] !== '>' && $tag[$i] !== '/') $i++;
            $attr = substr($tag, $start, $i - $start);
            if ($attr === '') { $i++; continue; }

            $value = null;
            $quote = '';
            $j = $i;
            while ($j < $len && ctype_space($tag[$j])) $j++;
            if ($j < $len && $tag[$j] === '=') {
                $j++;
                while ($j < $len && ctype_space($tag[$j])) $j++;
                if ($j < $len && ($tag[$j] === '"' || $tag[$j] === "'")) {
                    $quote = $tag[$j];
                    $j++;
                    $vs = $j;
                    while ($j < $len && $tag[$j] !== $quote) $j++;
                    $value = substr($tag, $vs, $j - $vs);
                    if ($j < $len) $j++;             // consume the closing quote
                } else {
                    $vs = $j;
                    while ($j < $len && !ctype_space($tag[$j]) && $tag[$j] !== '>') $j++;
                    $value = substr($tag, $vs, $j - $vs);
                }
                $i = $j;
            }

            // The only thing dropped: an attribute actually NAMED like a handler.
            if (preg_match('#^on[a-z0-9_.:-]*$#i', $attr)) continue;

            $out .= ' ' . $attr;
            if ($value !== null) $out .= '=' . ($quote !== '' ? $quote . $value . $quote : $value);
        }
        return $out . ($selfClose ? ' /' : '') . '>';
    }
}

if (!function_exists('expunge_only')) {
    /**
     * Expunge ONLY the messages this request flagged \Deleted.
     *
     * imap_expunge() permanently purges every \Deleted message in the mailbox —
     * including ones another client (a phone, Outlook, a second tab) flagged but
     * has not expunged yet. Those disappear for good, with no Trash copy.
     *
     * PHP's IMAP extension exposes no UID EXPUNGE (RFC 4315), so this uses the
     * standard workaround: work out which \Deleted messages are NOT ours, clear
     * their flag, expunge (which can now only take ours), then restore the flag.
     *
     * Failure mode is deliberately the safe one — if the script dies mid-way, a
     * foreign message merely loses a \Deleted FLAG and reappears; no mail is lost.
     *
     * $ourUids: the UIDs this request just moved/deleted.
     */
    function expunge_only($mbox, array $ourUids) {
        if (!$mbox) return;
        // Nothing of ours to purge — never run a blanket expunge "just in case".
        if (!$ourUids) return;
        if (!function_exists('imap_search') || !function_exists('imap_clearflag_full')) return;

        $all = @imap_search($mbox, 'DELETED', SE_UID);
        // FAIL CLOSED. imap_search returns false both on error and on "no match",
        // and we cannot tell them apart — so we cannot know whether another client
        // has messages flagged here. Expunging anyway would be exactly the
        // destructive behaviour this function exists to prevent. Skipping leaves
        // our own messages flagged \Deleted; the next operation cleans them up.
        if (!is_array($all)) return;

        $ours    = array_map('intval', $ourUids);
        $foreign = array_values(array_diff(array_map('intval', $all), $ours));

        if ($foreign) {
            // If we can't lift their flags, don't expunge — same reasoning.
            if (!@imap_clearflag_full($mbox, implode(',', $foreign), '\\Deleted', ST_UID)) return;
        }
        @imap_expunge($mbox);
        if ($foreign) @imap_setflag_full($mbox, implode(',', $foreign), '\\Deleted', ST_UID);
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

    /**
     * Open a mailbox, healing a certificate failure once.
     *
     * EVERY endpoint must go through this, not just the login screen. The
     * relaxed-TLS decision used to be made only when signing in, so a session that
     * was ALREADY open when verification was switched on — e.g. the user pressed
     * "Update now", which reloads the page but keeps the session — would hit a
     * self-signed host and simply fail: no inbox, no send, no drafts, for up to
     * the 12-hour session cap, with nothing in the UI hinting at a fix.
     *
     * On a verified-open failure that looks like a certificate problem, retry once
     * without verification; if that works, remember the host so later requests go
     * straight there (the retry then never happens again).
     */
    function imap_open_tls($host, $port, $ssl, $mailbox, $user, $pass, $opts = 0, $retries = 1) {
        $ref  = '{' . $host . ':' . (int)$port . imap_tls_flags($host, $ssl) . '}';
        $mbox = @imap_open($ref . $mailbox, $user, $pass, $opts, $retries);
        if ($mbox !== false || !$ssl || !tls_verify_enabled($host)) return $mbox;

        $certish = false;
        foreach ((@imap_errors() ?: []) as $e) {
            if (preg_match('#certificate|self[ -]?signed|verify|SSL#i', (string)$e)) { $certish = true; break; }
        }
        if (!$certish) return false;

        $relaxed = '{' . $host . ':' . (int)$port . '/imap/ssl/novalidate-cert}';
        $mbox = @imap_open($relaxed . $mailbox, $user, $pass, $opts, $retries);
        if ($mbox !== false) {
            tls_remember_relaxed($host);
            @imap_errors(); // drain the first attempt's noise
        }
        return $mbox;
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
        $allPresent = true;
        foreach ($files as $name => $body) {
            $path = $dir . '/' . $name;
            if (!file_exists($path)) {
                @file_put_contents($path, $body);
                @chmod($path, 0644);
            }
            if (!file_exists($path)) $allPresent = false;
        }
        // Only latch the sentinel once every guard is actually on disk. Writing it
        // unconditionally meant one failed write (a full disk, a permission blip)
        // permanently skipped the check, so the missing deny file was never
        // restored — the opposite of self-healing.
        if ($allPresent) {
            @file_put_contents($sentinel, "guards written " . gmdate('c') . "\n");
            @chmod($sentinel, 0600);
        }
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

if (!function_exists('resolve_folder')) {
    /**
     * Find the mailbox that best matches one of $keywords (e.g. trash / archive).
     *
     * The old approach returned the FIRST folder whose full name contained the
     * keyword anywhere, in whatever order the server listed them. That happily
     * picked a user folder called "Trashed receipts" over the real Trash, or
     * "Archive 2019/Sent" when looking for Sent — and then moved mail into it.
     *
     * PHP's IMAP extension does not expose SPECIAL-USE flags, so rank instead:
     * an exact leaf match beats a leaf that merely starts with the keyword, which
     * beats a substring anywhere; shallower folders win ties. Keyword order is
     * honoured, so callers can express a preference (trash before bin).
     */
    function resolve_folder($mbox, $ref, array $keywords) {
        $list = @imap_list($mbox, $ref, '*');
        if (!is_array($list)) return null;

        $best = null; $bestScore = -1;
        foreach ($list as $raw) {
            $name = mb_convert_encoding(str_replace($ref, '', $raw), 'UTF-8', 'UTF7-IMAP');
            if ($name === '') continue;
            $parts = preg_split('/[.\/]/', $name);
            $leaf  = strtolower((string) end($parts));
            $depth = max(0, count($parts) - 1);
            $low   = strtolower($name);

            foreach ($keywords as $i => $kw) {
                $kw = strtolower($kw);
                if     ($leaf === $kw)                 $tier = 3;
                elseif (strpos($leaf, $kw) === 0)      $tier = 2;
                elseif (strpos($low,  $kw) !== false)  $tier = 1;
                else continue;
                // tier dominates, then earlier keyword, then shallower nesting
                $score = $tier * 1000 - $i * 100 - min($depth, 20);
                if ($score > $bestScore) { $bestScore = $score; $best = $name; }
                break; // first matching keyword decides this folder's score
            }
        }
        return $best;
    }
}

if (!function_exists('data_janitor')) {
    /**
     * Sweep expired throwaway files out of data/. Runs at most once an hour across
     * the whole install (poll_gate), and touches a bounded number of files per run
     * so no single request stalls behind it.
     *
     * The login throttle writes one file per (email, IP) pair and never removes it
     * on failure, so repeated failed sign-ins — or a spray across many addresses —
     * grow the file COUNT without bound. Each file is tiny, so this shows up as
     * inode exhaustion on the kind of tight shared-hosting quota this app targets,
     * long before it shows up as disk usage.
     */
    function data_janitor() {
        if (!poll_gate('__system__', 'janitor', 3600)) return;
        $base = __DIR__ . '/../data';
        $now  = time();
        $budget = 2000; // hard cap on unlinks per run

        // Throttle records: the counting window is 15 minutes, so 2 hours is ample.
        foreach ((array) @glob($base . '/ratelimit/*.json') as $f) {
            if ($budget-- <= 0) break;
            $m = @filemtime($f);
            if ($m !== false && ($now - $m) > 7200) @unlink($f);
        }
        // Poll timestamps/caches for accounts nobody has used in a month.
        foreach ((array) @glob($base . '/poll/*.json') as $f) {
            if ($budget-- <= 0) break;
            $m = @filemtime($f);
            if ($m !== false && ($now - $m) > 2592000) @unlink($f);
        }
    }
}

if (!function_exists('poll_cache_get')) {
    /**
     * Tiny per-account cache for BACKGROUND POLL responses only.
     *
     * The status probe and the folder sweep each open their own IMAP connection,
     * once per open tab per cycle. Five tabs on one account meant five connections
     * a minute — at ~20-50MB apiece against a 2GB per-account ceiling — to fetch
     * the identical answer. Caching lets the tabs share one probe.
     *
     * Used ONLY when the client marks a request as a background poll (poll=1), so
     * every user-initiated load — switching folder, refreshing, or reading counts
     * right after deleting something — still goes straight to the server and can
     * never show a stale count. With a single tab the TTL is shorter than the poll
     * interval, so behaviour there is unchanged too; the saving appears exactly in
     * the multi-tab case that caused the problem.
     */
    function _poll_cache_file($email) {
        $dir = __DIR__ . '/../data/poll';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        return $dir . '/' . hash('sha256', strtolower(trim((string)$email))) . '-cache.json';
    }

    function poll_cache_get($email, $key, $ttl) {
        if (!$email || $ttl <= 0) return null;
        $d = @json_decode((string) @file_get_contents(_poll_cache_file($email)), true);
        if (!is_array($d) || !isset($d[$key]) || !is_array($d[$key])) return null;
        if ((time() - (int)($d[$key]['at'] ?? 0)) > $ttl) return null;
        return $d[$key]['v'] ?? null;
    }

    function poll_cache_put($email, $key, $value) {
        if (!$email) return;
        $f  = _poll_cache_file($email);
        $lk = store_lock($f, 300);
        $d  = @json_decode((string) @file_get_contents($f), true);
        if (!is_array($d)) $d = [];
        // Re-insert at the end so the array's own order IS recency; sorting by the
        // timestamp cannot distinguish entries written in the same second, and the
        // ties then evicted the newest rather than the oldest.
        unset($d[$key]);
        $d[$key] = ['at' => time(), 'v' => $value];
        if (count($d) > 24) $d = array_slice($d, -24, null, true); // one entry per folder viewed
        atomic_write_json($f, $d);
        store_unlock($lk);
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
