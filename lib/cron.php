<?php
/**
 * The scheduler — what runs the unattended jobs when no browser is open.
 *
 * Four things in this app are advertised as happening on their own: a
 * scheduled send goes out, an out-of-office reply is sent, a snoozed message
 * comes back, a filter files new mail. Each used to run only from the
 * browser's poll loop, so each stopped the moment the last tab closed.
 *
 * This app is dropped onto a subdomain; nobody will add a cron job for it. So
 * it wakes itself:
 *
 *   tick   Every ordinary request — a page, a poll, the login page — ends with
 *          one cheap check (a file's age). If a run is due, the server calls
 *          its own cron.php in the background and the visitor never waits.
 *   hop    Each run books the next: a background request that sleeps until
 *          the next run is due (at most CRON_HOP_MAX seconds), runs, and books
 *          again. The chain carries on overnight after the last tab closes.
 *          A hop learns how long this host lets a background request live
 *          (it reports in every CRON_HOP_SLICE seconds; a hop that died short
 *          shortens the next) and gives up only when even the shortest hop
 *          dies — then tasks run with use, and Settings says so.
 *   cron   A host cron job hitting cron.php (CLI or URL) still works and, when
 *          it is seen to be running, supersedes the chain.
 *
 * cron.php (repo root) is the entry point for all three. It walks every
 * account that opted in (lib/credstore.php), opens that account's sealed
 * credentials, mirrors them into the flat $_SESSION keys every job already
 * reads, and runs the four jobs in turn.
 *
 * Everything that decides something is a pure function with an injectable
 * clock, sleep and transport, so the whole chain is covered by tests that need
 * neither a mail server nor a real five minutes.
 */

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/credstore.php';

const CRON_BUDGET_SECONDS = 50;      // leave headroom under a 60 s execution limit
const CRON_INTERVAL       = 300;     // a run every five minutes
const CRON_STALE_SECONDS  = 900;     // nothing for 15 min means "not running"
const CRON_RUNS_KEPT      = 20;
const CRON_SCHEDULE       = '*/5 * * * *';
const CRON_TICK_SECONDS   = 60;      // a request tries to wake the scheduler at most this often
const CRON_HOP_MAX        = 270;     // a hop sleeps at most this long: under common 300 s request limits
const CRON_HOP_MIN        = 45;      // shorter than this and the chain is not worth its requests
const CRON_HOP_SLICE      = 10;      // a sleeping hop reports in this often
const CRON_INLINE_HOPS    = 12;      // in-process chain length when the server cannot call itself
const CRON_PROBE_TTL      = 86400;   // re-check a failed wake method daily

/* ---------- clock, sleep and transport: injectable for tests ---------- */

function cron_now()     { return isset($GLOBALS['CRON_NOW']) ? (int)($GLOBALS['CRON_NOW'])() : time(); }
function cron_sleep($s) { if (isset($GLOBALS['CRON_SLEEP'])) { ($GLOBALS['CRON_SLEEP'])($s); return; } if ($s > 0) sleep((int)$s); }

/* ---------- state ---------- */

function cron_file() { return app_data_root() . '/cron.json'; }

/** The persisted scheduler state, with every field present. */
function cron_state() {
    $f = cron_file();
    $d = is_file($f) ? @json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d)) $d = [];
    $d += ['token' => '', 'last_run' => '', 'runs' => [], 'base_url' => '', 'trigger' => [], 'chain' => [],
           'hop_len' => CRON_HOP_MAX, 'hop_started' => 0, 'hop_alive' => 0, 'hop_done' => 0, 'hop_planned' => 0, 'hop_phase' => '',
           'last_trigger' => 0, 'last_trigger_what' => ''];
    if (!is_array($d['runs']))    $d['runs']    = [];
    if (!is_array($d['trigger'])) $d['trigger'] = [];
    if (!is_array($d['chain']))   $d['chain']   = [];
    $d['trigger'] += ['method' => '', 'checked' => 0, 'error' => '', 'misses' => 0];
    $d['chain']   += ['mode' => 'auto', 'deaths' => 0, 'retry_at' => 0, 'reason' => ''];
    return $d;
}

function cron_save_state(array $d) {
    $dir = dirname(cron_file());
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    return (bool)atomic_write_json(cron_file(), $d);
}

/**
 * Read-modify-write under a lock. A sleeping hop, a tick and a run all touch
 * this file from different processes; without the lock one would overwrite
 * what another had just recorded.
 */
function cron_state_update(callable $fn) {
    $dir = dirname(cron_file());
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    $h = @fopen(cron_file() . '.wlock', 'c');
    if ($h) @flock($h, LOCK_EX);
    $d  = $fn(cron_state());
    $ok = is_array($d) ? cron_save_state($d) : false;
    if ($h) { @flock($h, LOCK_UN); @fclose($h); }
    return $ok;
}

/** Patch top-level keys; 'trigger' and 'chain' are merged, not replaced. */
function cron_state_patch(array $patch) {
    return cron_state_update(function ($d) use ($patch) {
        foreach ($patch as $k => $v) {
            if (($k === 'trigger' || $k === 'chain') && is_array($v)) $d[$k] = array_merge($d[$k], $v);
            else $d[$k] = $v;
        }
        return $d;
    });
}

/** The URL token, created on first use. 48 hex chars from 24 random bytes. */
function cron_token() {
    $d = cron_state();
    if (is_string($d['token']) && strlen($d['token']) >= 32) return $d['token'];
    return cron_regenerate_token();
}

function cron_regenerate_token() {
    try { $t = bin2hex(random_bytes(24)); } catch (\Throwable $e) { return ''; }
    return cron_state_patch(['token' => $t]) ? $t : '';
}

/** Constant-time compare; anything not a non-empty string is a mismatch. */
function cron_token_ok($given, $expected) {
    if (!is_string($given) || !is_string($expected) || $given === '' || $expected === '') return false;
    return hash_equals($expected, $given);
}

/* ---------- the app's own address ---------- */

/** Scheme + host + app directory, from the parts of a request. Pure. */
function cron_base_url_from($https, $host, $scriptName) {
    $host = (string)$host;
    if (!preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host)) return '';
    $dir = rtrim(str_replace('\\', '/', dirname((string)$scriptName)), '/');
    if (basename($dir) === 'ajax') $dir = rtrim(dirname($dir), '/');
    if ($dir === '.' || $dir === '\\') $dir = '';
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function cron_request_base_url() {
    if (empty($_SERVER['HTTP_HOST'])) return '';
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || (($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '') === 'on');
    return cron_base_url_from($https, $_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME'] ?? '/');
}

/**
 * Remember the address the scheduler will call — only from a SIGNED-IN
 * request. The Host header is whatever the client sent; learning it from a
 * stranger's request would let them point the server's own calls, token and
 * all, at a host of their choosing. Once learned it is kept until the wake
 * method stops working.
 */
function cron_learn_base_url() {
    if (empty($_SESSION['email'])) return '';
    $u = cron_request_base_url();
    if ($u === '') return '';
    $st = cron_state();
    if ($st['base_url'] === '' || ($st['base_url'] !== $u && $st['trigger']['method'] === 'none')) {
        cron_state_patch(['base_url' => $u, 'trigger' => ['method' => '', 'checked' => 0, 'error' => '', 'misses' => 0]]);
    }
    return $u;
}

/* ---------- what Settings shows ---------- */

function cron_url($baseUrl, $token) {
    return rtrim((string)$baseUrl, '/') . '/cron.php?token=' . rawurlencode((string)$token);
}

/**
 * The command alone — what a hosting panel's Cron Jobs form (cPanel,
 * DirectAdmin, Plesk) takes in its command field, the schedule being its own
 * fields there. The *_line() forms below prepend the schedule for a plain
 * crontab. Optional: the app wakes itself without any of this.
 */
function cron_php_cmd($cronPath, $phpBin = 'php') {
    return $phpBin . ' -q ' . escapeshellarg((string)$cronPath) . ' >/dev/null 2>&1';
}

/** The same job through the web server's PHP, for hosts whose CLI php lacks the IMAP extension. */
function cron_curl_cmd($url) {
    return 'curl -fsS ' . escapeshellarg((string)$url) . ' >/dev/null 2>&1';
}

function cron_cpanel_line($cronPath, $phpBin = 'php') { return CRON_SCHEDULE . ' ' . cron_php_cmd($cronPath, $phpBin); }
function cron_curl_line($url)                          { return CRON_SCHEDULE . ' ' . cron_curl_cmd($url); }

/** True when the scheduler has not reported in for longer than CRON_STALE_SECONDS. */
function cron_is_stale($lastRunIso, $now = null, $maxAge = CRON_STALE_SECONDS) {
    $now = $now ?? cron_now();
    $ts  = $lastRunIso ? strtotime((string)$lastRunIso) : false;
    if ($ts === false) return true;
    return ($now - $ts) > $maxAge;
}

/* ---------- when a credential may be dropped ---------- */

/**
 * The opt-in promise is "deleted automatically when nothing is pending".
 * This is what "pending" means: a queued or scheduled send, an active
 * out-of-office, a snooze still waiting, or an enabled filter — any one of
 * which needs the mailbox opened while nobody is signed in.
 */
function cron_needs_credential(array $state) {
    return (int)($state['outbox'] ?? 0) > 0
        || !empty($state['ooo_active'])
        || (int)($state['snoozes'] ?? 0) > 0
        || (int)($state['rules_enabled'] ?? 0) > 0;
}

/** Read that state for one account from its stores. */
function cron_pending_state($email) {
    $s = ['outbox' => 0, 'ooo_active' => false, 'snoozes' => 0, 'rules_enabled' => 0];
    if (function_exists('list_outbox_messages')) {
        $s['outbox'] = count(array_filter((array)list_outbox_messages($email), fn($m) => empty($m['failed'])));
    }
    if (function_exists('load_ooo') && function_exists('ooo_is_active')) {
        $d = load_ooo($email);
        $s['ooo_active'] = ooo_is_active($d['config'] ?? []);
    }
    if (function_exists('load_snoozes')) {
        $d = load_snoozes($email);
        $s['snoozes'] = count((array)($d['snoozes'] ?? []));
    }
    if (function_exists('load_rules')) {
        $d = load_rules($email);
        $s['rules_enabled'] = count(array_filter((array)($d['rules'] ?? []), fn($r) => !empty($r['enabled'])));
    }
    return $s;
}

/* ---------- timing: pure ---------- */

/** A run is due once the interval has passed (a hop waking slightly early still counts). */
function cron_due(array $st, $now, $interval = CRON_INTERVAL) {
    $ts = $st['last_run'] ? strtotime((string)$st['last_run']) : false;
    if ($ts === false) return true;
    return ($now - $ts) >= ($interval - CRON_HOP_SLICE);
}

/** Seconds until the next run is due; 0 when it is. */
function cron_seconds_until_due(array $st, $now, $interval = CRON_INTERVAL) {
    $ts = $st['last_run'] ? strtotime((string)$st['last_run']) : false;
    if ($ts === false) return 0;
    return max(0, ($ts + $interval) - $now);
}

/** How long the next hop should sleep: until due, but never past the hop length this host allows. */
function cron_hop_plan(array $st, $now) {
    return (int)min(cron_seconds_until_due($st, $now), max(CRON_HOP_MIN, (int)$st['hop_len']));
}

/** A hop is alive when it reported in within the last few slices. */
function cron_chain_alive(array $st, $now) {
    return in_array($st['hop_phase'], ['sleep', 'run'], true) && ($now - (int)$st['hop_alive']) < CRON_HOP_SLICE * 3;
}

/**
 * Learn from the previous hop's fate. It is called only once the chain lock
 * is free, so the previous hop process is gone: if its phase is still 'sleep'
 * and it never recorded 'done', the host ended it. The next hop is cut to
 * four fifths of what that one survived; at the minimum, three such deaths
 * mark chaining unsupported here (re-tried daily).
 */
function cron_chain_learn(array $st, $now) {
    $ch = $st['chain'];
    if ($ch['mode'] === 'unsupported' && $now >= (int)$ch['retry_at']) {
        $ch = ['mode' => 'auto', 'deaths' => 0, 'retry_at' => 0, 'reason' => ''];
        $st['hop_len'] = CRON_HOP_MAX;
    }
    $started = (int)$st['hop_started'];
    $died    = $started > 0 && $st['hop_phase'] === 'sleep' && (int)$st['hop_done'] < $started;
    if ($died) {
        $survived = max(0, (int)$st['hop_alive'] - $started);
        if ($survived < (int)$st['hop_planned'] - CRON_HOP_SLICE) {
            $wasMin = (int)$st['hop_len'] <= CRON_HOP_MIN;
            $st['hop_len'] = max(CRON_HOP_MIN, (int)floor($survived * 0.8));
            $ch['deaths']  = (int)$ch['deaths'] + 1;
            $ch['reason']  = 'this host ends a background request after about ' . $survived . ' s';
            if ($wasMin && $ch['deaths'] >= 3) {
                $ch['mode']     = 'unsupported';
                $ch['retry_at'] = $now + CRON_PROBE_TTL;
            }
        }
        $st['hop_phase'] = 'died';   // counted once; a second look must not count it again
    }
    $st['chain'] = $ch;
    return $st;
}

/** Was the latest run made by a host cron (CLI or URL) recently enough to trust it? */
function cron_host_cron_active(array $st, $now) {
    $last = end($st['runs']);
    if (!is_array($last)) return false;
    $via = (string)($last['via'] ?? '');
    $ts  = strtotime((string)($last['at'] ?? '')) ?: 0;
    return in_array($via, ['cli', 'url'], true) && ($now - $ts) <= CRON_INTERVAL * 2;
}

/** Should a hop chain be kept alive right now? */
function cron_chain_wanted(array $st, $now) {
    if ($st['chain']['mode'] === 'unsupported' && $now < (int)$st['chain']['retry_at']) return false;
    if (in_array($st['trigger']['method'], ['sync', 'none'], true)) return false;
    if (cron_host_cron_active($st, $now)) return false;
    return true;
}

/* ---------- reaching ourselves ---------- */

/** The bytes of one small HTTP/1.0 request (1.0: no chunked answers to parse). Pure. */
function cron_http_request_bytes($url) {
    $p    = parse_url((string)$url) ?: [];
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $host = ($p['host'] ?? 'localhost') . (isset($p['port']) ? ':' . $p['port'] : '');
    return "GET $path HTTP/1.0\r\nHost: $host\r\nConnection: close\r\nUser-Agent: MailApp-scheduler\r\n\r\n";
}

/**
 * The ways to reach our own cron.php, best first. Loopback (127.0.0.1 with the
 * site name for SNI/Host) keeps the token on this machine; the public name is
 * the fallback for hosts whose web server does not answer on loopback.
 */
function cron_wake_variants($baseUrl) {
    $p = parse_url((string)$baseUrl);
    if (!$p || empty($p['host'])) return [];
    $tls  = (($p['scheme'] ?? 'http') === 'https');
    $port = (int)($p['port'] ?? ($tls ? 443 : 80));
    $host = $p['host'];
    $v = [['name' => 'local', 'connect' => '127.0.0.1', 'port' => $port, 'tls' => $tls, 'verify' => true, 'host' => $host]];
    if ($tls) $v[] = ['name' => 'local-noverify', 'connect' => '127.0.0.1', 'port' => $port, 'tls' => true, 'verify' => false, 'host' => $host];
    $v[] = ['name' => 'public', 'connect' => $host, 'port' => $port, 'tls' => $tls, 'verify' => true, 'host' => $host];
    return $v;
}

function cron_is_loopback_method($m) { return in_array($m, ['local', 'local-noverify', 'public'], true); }

/**
 * Send one request. Non-blocking: write it, give the server a moment to take
 * it, close — the visitor's request does not wait for a run. Blocking (the
 * probe): read the whole answer.
 */
function cron_http_send(array $variant, $url, $blocking) {
    if (isset($GLOBALS['CRON_TRANSPORT'])) return ($GLOBALS['CRON_TRANSPORT'])($variant, $url, $blocking);
    $ctx = stream_context_create(['ssl' => [
        'peer_name' => $variant['host'], 'SNI_enabled' => true,
        'verify_peer' => (bool)$variant['verify'], 'verify_peer_name' => (bool)$variant['verify'], 'allow_self_signed' => !$variant['verify'],
    ]]);
    $remote = ($variant['tls'] ? 'tls://' : 'tcp://') . $variant['connect'] . ':' . $variant['port'];
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['ok' => false, 'error' => 'connect ' . $variant['connect'] . ':' . $variant['port'] . ': ' . (trim((string)$errstr) ?: "error $errno")];
    if (@fwrite($fp, cron_http_request_bytes($url)) === false) { @fclose($fp); return ['ok' => false, 'error' => 'write failed']; }
    if (!$blocking) {
        @stream_set_timeout($fp, 0, 150000);
        @fread($fp, 1);
        @fclose($fp);
        return ['ok' => true];
    }
    @stream_set_timeout($fp, 6);
    $resp = (string)@stream_get_contents($fp);
    @fclose($fp);
    $status = preg_match('#^HTTP/\d\.\d (\d{3})#', $resp, $m) ? (int)$m[1] : 0;
    $i      = strpos($resp, "\r\n\r\n");
    return ['ok' => true, 'status' => $status, 'body' => $i === false ? '' : substr($resp, $i + 4)];
}

function cron_can_finish_request() { return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'); }
function cron_finish_request() {
    if (function_exists('fastcgi_finish_request'))   return (bool)@fastcgi_finish_request();
    if (function_exists('litespeed_finish_request')) return (bool)@litespeed_finish_request();
    return false;
}
/** The jobs need the IMAP extension; tests may assume it. */
function cron_imap_available() { return function_exists('imap_open') || !empty($GLOBALS['CRON_ASSUME_IMAP']); }
function cron_request_is_ajax() { return strpos(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/ajax/') !== false; }

/**
 * Find a way to reach cron.php, in order, and remember it. Blocking, a few
 * seconds at most; run when an account is switched on and again only when the
 * remembered way stops working.
 */
function cron_probe($baseUrl = null) {
    $st   = cron_state();
    $base = $baseUrl ?? $st['base_url'];
    $res  = ['method' => 'none', 'checked' => cron_now(), 'error' => '', 'misses' => 0];
    $errs = [];
    if ($base === '') {
        $errs[] = 'the app\'s own address is not known yet';
    } else {
        $url = cron_url($base, cron_token()) . '&probe=1';
        foreach (cron_wake_variants($base) as $v) {
            $r = cron_http_send($v, $url, true);
            if (!empty($r['ok']) && (int)($r['status'] ?? 0) === 200 && strpos((string)($r['body'] ?? ''), '"probe":true') !== false) {
                $res['method'] = $v['name'];
                $errs = [];
                break;
            }
            $errs[] = $v['name'] . ': ' . (isset($r['error']) ? $r['error'] : 'HTTP ' . (int)($r['status'] ?? 0));
        }
    }
    if ($res['method'] === 'none') {
        $res['method'] = cron_can_finish_request() ? 'inline' : 'sync';
        $res['error']  = implode('; ', $errs);
    }
    cron_state_patch(['trigger' => $res]);
    app_log('info', 'scheduler: wake method', ['method' => $res['method'], 'detail' => $res['error']]);
    return $res;
}

/**
 * Start a run or a hop in the background, by the remembered method. Returns
 * what was attempted; a loopback send that fails counts as a miss, and three
 * misses make the next trigger probe again.
 */
function cron_trigger($what, $via = 'tick') {
    $st = cron_state();
    $t  = $st['trigger'];
    $stale = $t['method'] === ''
          || (int)$t['misses'] >= 3
          || (in_array($t['method'], ['inline', 'sync', 'none'], true) && cron_now() - (int)$t['checked'] > CRON_PROBE_TTL);
    if ($stale) $t = cron_probe();
    $method = $t['method'];
    cron_state_patch(['last_trigger' => cron_now(), 'last_trigger_what' => $what]);

    if (cron_is_loopback_method($method)) {
        $variants = array_column(cron_wake_variants($st['base_url']), null, 'name');
        if (empty($variants[$method])) return ['ok' => false, 'method' => $method, 'error' => 'address changed'];
        $url = cron_url($st['base_url'], cron_token()) . ($what === 'hop' ? '&hop=1' : '&run=1') . '&via=' . rawurlencode($via);
        $r   = cron_http_send($variants[$method], $url, false);
        if (empty($r['ok'])) {
            cron_state_patch(['trigger' => ['misses' => (int)$t['misses'] + 1]]);
            return ['ok' => false, 'method' => $method, 'what' => $what, 'error' => (string)($r['error'] ?? '')];
        }
        return ['ok' => true, 'method' => $method, 'what' => $what];
    }
    if ($method === 'inline') {
        // The response has to be on its way before anything slow starts here.
        if (!cron_finish_request()) return ['ok' => false, 'method' => 'inline', 'error' => 'could not detach from the response'];
        return cron_inline($what, $via);
    }
    if ($method === 'sync') {
        // No way to detach: run inside a background poll's request only, briefly.
        if ($what !== 'run' || !cron_request_is_ajax()) return ['ok' => false, 'method' => 'sync', 'skipped' => true];
        cron_run_all(20, 'sync');
        return ['ok' => true, 'method' => 'sync', 'what' => 'run'];
    }
    return ['ok' => false, 'method' => $method];
}

/** In-process chain, after the response has been released: a run, then hops until one is not needed. */
function cron_inline($what, $via) {
    if ($what === 'run') cron_run_all(CRON_BUDGET_SECONDS, $via);
    for ($i = 0; $i < CRON_INLINE_HOPS; $i++) {
        $r = cron_hop('inline');
        if (($r['hop'] ?? '') !== 'done') break;
    }
    return ['ok' => true, 'method' => 'inline', 'what' => $what];
}

function cron_run_in_progress() {
    $h = @fopen(cron_file() . '.lock', 'c');
    if (!$h) return false;
    $free = @flock($h, LOCK_EX | LOCK_NB);
    if ($free) @flock($h, LOCK_UN);
    @fclose($h);
    return !$free;
}

/* ---------- the tick: what every request ends with ---------- */

/**
 * Called at the end of every ordinary request. Cheap by design: one stat on
 * cron.tick answers almost every call. Never throws, never prints.
 */
function cron_tick() {
    try {
        $now = cron_now();
        $tf  = app_data_root() . '/cron.tick';
        $m   = @filemtime($tf);
        if ($m !== false && ($now - $m) < CRON_TICK_SECONDS) return ['tick' => 'throttled'];
        if (!@touch($tf, $now)) return ['tick' => 'no-data'];
        clearstatcache(true, $tf);
        if (!cred_list()) return ['tick' => 'idle'];
        cron_learn_base_url();

        // Did the previous trigger produce anything? Three silent ones re-probe.
        $st = cron_state();
        $lt = (int)$st['last_trigger'];
        if ($lt > 0) {
            $seen = max((int)$st['hop_started'], (int)$st['hop_alive'], (int)(strtotime((string)$st['last_run']) ?: 0));
            if ($seen >= $lt) { if ((int)$st['trigger']['misses'] > 0) cron_state_patch(['trigger' => ['misses' => 0]]); }
            elseif (($now - $lt) >= CRON_TICK_SECONDS && $now - $lt < CRON_STALE_SECONDS) cron_state_patch(['trigger' => ['misses' => (int)$st['trigger']['misses'] + 1]]);
            $st = cron_state();
        }

        if (cron_chain_alive($st, $now)) return ['tick' => 'chain-alive'];
        if (cron_run_in_progress())      return ['tick' => 'running'];
        if (cron_due($st, $now))         return ['tick' => 'run'] + cron_trigger('run', 'tick');
        if (cron_chain_wanted($st, $now)) return ['tick' => 'hop'] + cron_trigger('hop', 'tick');
        return ['tick' => 'nothing'];
    } catch (\Throwable $e) {
        app_log('warn', 'scheduler: tick failed', ['error' => $e->getMessage()]);
        return ['tick' => 'error'];
    }
}

/* ---------- the hop ---------- */

/**
 * Sleep until the next run is due (at most this host's hop length), reporting
 * in every slice; run if due; book the next hop. One hop at a time per install
 * (chain lock). Stops on its own when no account is opted in any more.
 */
function cron_hop($via = 'chain') {
    @ignore_user_abort(true);
    @set_time_limit(0);
    if (!cron_imap_available()) return ['ok' => false, 'hop' => 'no-imap', 'error' => 'PHP IMAP extension is not enabled'];

    $lock = @fopen(cron_file() . '.chain.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) @fclose($lock); return ['ok' => true, 'hop' => 'busy']; }
    $release = function () use ($lock) { @flock($lock, LOCK_UN); @fclose($lock); };

    $now = cron_now();
    $st  = cron_chain_learn(cron_state(), $now);
    if ((int)$st['chain']['deaths'] > 0 && $st['chain']['reason'] !== '' && $st['hop_len'] !== cron_state()['hop_len']) {
        app_log('info', 'scheduler: hop shortened', ['hop_len' => $st['hop_len'], 'reason' => $st['chain']['reason']]);
    }
    if (!cred_list() || !cron_chain_wanted($st, $now)) {
        cron_state_patch(['chain' => $st['chain'], 'hop_len' => $st['hop_len'], 'hop_phase' => '']);
        $release();
        return ['ok' => true, 'hop' => 'stopped'];
    }
    $plan = cron_hop_plan($st, $now);
    cron_state_patch(['chain' => $st['chain'], 'hop_len' => $st['hop_len'], 'hop_started' => $now, 'hop_alive' => $now,
                      'hop_planned' => $plan, 'hop_phase' => 'sleep']);
    $slept = 0;
    while ($slept < $plan) {
        $n = min(CRON_HOP_SLICE, $plan - $slept);
        cron_sleep($n);
        $slept += $n;
        if (!cred_list()) {
            cron_state_patch(['hop_phase' => '', 'hop_done' => cron_now()]);
            $release();
            return ['ok' => true, 'hop' => 'stopped'];
        }
        cron_state_patch(['hop_alive' => cron_now()]);
    }
    cron_state_patch(['hop_phase' => 'run', 'hop_alive' => cron_now()]);
    $release();

    $ran = cron_due(cron_state(), cron_now()) ? cron_run_all(CRON_BUDGET_SECONDS, $via) : null;
    cron_state_patch(['hop_phase' => 'done', 'hop_done' => cron_now(), 'chain' => ['deaths' => 0]]);
    cron_book_next_hop();
    return ['ok' => true, 'hop' => 'done', 'ran' => $ran];
}

/** Book the next hop over the loopback; an in-process chain loops instead. */
function cron_book_next_hop() {
    $st = cron_state();
    if (!cred_list() || !cron_chain_wanted($st, cron_now())) return false;
    if (!cron_is_loopback_method($st['trigger']['method'])) return false;
    $r = cron_trigger('hop', 'chain');
    return !empty($r['ok']);
}

/* ---------- what the pane says: pure ---------- */

/**
 * One state word and one line of detail, from the scheduler's records.
 * states: off, starting, self, cron, running, stopped
 */
function cron_status_words(array $st, $now, $anyOn) {
    if (!$anyOn) return ['state' => 'off', 'detail' => 'Switch an account on above; nothing runs while you are signed out until you do.'];
    $t   = $st['trigger'];
    $why = '';
    if ($t['method'] === 'sync' || $t['method'] === 'none') {
        $why = 'This server could not call itself (' . ($t['error'] ?: 'no way to reach cron.php') . '), so tasks run only while someone is using the app. A host cron job would fix the timing.';
    } elseif ($t['method'] === 'inline') {
        $why = 'This server could not call itself (' . ($t['error'] ?: 'loopback refused') . '); it runs tasks in the background of each visit instead, so a quiet night can delay them. A host cron job would fix the timing.';
    } elseif ($st['chain']['mode'] === 'unsupported') {
        $why = ucfirst($st['chain']['reason'] ?: 'this host ends background requests too soon') . ', so the app cannot stay awake between visits; tasks run whenever someone uses it. A host cron job would fix the timing.';
    }
    if ($st['last_run'] === '') {
        if ((int)$st['last_trigger'] > 0 && ($now - (int)$st['last_trigger']) < CRON_STALE_SECONDS) {
            return ['state' => 'starting', 'detail' => $why ?: 'The first run is on its way.'];
        }
        return ['state' => 'stopped', 'detail' => $why ?: 'Nothing has run yet. Use the app once while signed in; the scheduler starts from any visit.'];
    }
    if (cron_host_cron_active($st, $now)) return ['state' => 'cron', 'detail' => 'A host cron job is driving the scheduler; the app leaves the timing to it.'];
    if (cron_chain_alive($st, $now)) {
        return ['state' => 'self', 'detail' => 'The app keeps itself awake on the server (a background request waits up to ' . max(CRON_HOP_MIN, (int)$st['hop_len']) . ' s between runs). No cron job is needed.'];
    }
    if (!cron_is_stale($st['last_run'], $now)) return ['state' => 'running', 'detail' => $why ?: 'Between hops; the next run is booked.'];
    return ['state' => 'stopped', 'detail' => $why ?: 'The last run is older than 15 minutes. The scheduler restarts from the next visit; if this keeps happening, add the host cron job below.'];
}

/* ---------- the run ---------- */

/** Remember a run; keep the last CRON_RUNS_KEPT. */
function cron_record_run(array $summary) {
    $summary['at'] = $summary['at'] ?? gmdate('c');
    return cron_state_update(function ($d) use ($summary) {
        $d['last_run'] = $summary['at'];
        $d['runs'][]   = $summary;
        if (count($d['runs']) > CRON_RUNS_KEPT) $d['runs'] = array_slice($d['runs'], -CRON_RUNS_KEPT);
        return $d;
    });
}

/** Put one account's credentials where every job reads them. */
function cron_mirror_account(array $acct) {
    foreach (['email', 'password', 'imap_host', 'imap_port', 'imap_ssl', 'smtp_host', 'display_name'] as $k) {
        $_SESSION[$k] = $acct[$k] ?? null;
    }
    $_SESSION['accounts']        = [$acct['id'] => $acct];
    $_SESSION['active_account']  = $acct['id'];
    $_SESSION['primary_account'] = $acct['id'];
}

/**
 * Run every job for every opted-in account. Returns the summary it recorded.
 *
 * Serialised by a lock so two overlapping runs cannot both send the same
 * queued message; bounded by a time budget so a slow mail server cannot push
 * a run past the host's execution limit; and each job is isolated, so one
 * account's broken IMAP login cannot stop the next account's scheduled send.
 */
function cron_run_all($budget = CRON_BUDGET_SECONDS, $via = 'url') {
    // When this runs inside a visitor's request (inline or sync), their session
    // must be closed first: the mirror below writes $_SESSION, and nothing of
    // another account's credentials may ever be saved into a visitor's session.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $started = microtime(true);
    $summary = ['at' => gmdate('c'), 'via' => (string)$via, 'accounts' => 0, 'jobs' => [], 'errors' => [], 'expired' => [], 'skipped' => 0, 'seconds' => 0];

    $lock = @fopen(cron_file() . '.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) @fclose($lock);
        app_log('info', 'cron: another run is in progress; skipped');
        $summary['skipped'] = 1;
        return $summary;
    }

    $jobs = [
        'outbox' => 'job_outbox_process',
        'rules'  => 'job_rules_run_new',
        'snooze' => 'job_snooze_wake',
        'ooo'    => 'job_ooo_process',
    ];

    foreach (cred_list() as $entry) {
        if ((microtime(true) - $started) > $budget) { $summary['skipped']++; continue; }
        $rec = cred_get($entry['id']);
        if (!$rec) {
            $summary['errors'][] = ['account' => $entry['email'], 'error' => 'credential could not be opened'];
            app_log('error', 'cron: sealed credential could not be opened', ['account' => $entry['email']]);
            continue;
        }
        $acct  = $rec['account'];
        $email = (string)$acct['email'];
        cron_mirror_account($acct);
        $summary['accounts']++;

        foreach ($jobs as $name => $fn) {
            if ((microtime(true) - $started) > $budget) { $summary['skipped']++; break; }
            if (!function_exists($fn)) continue;
            try {
                $r = $fn($email, ['cron' => true]);
                $summary['jobs'][$name] = ($summary['jobs'][$name] ?? 0) + (int)($r['count'] ?? $r['sent'] ?? $r['replied'] ?? 0);
                if (!empty($r['error'])) {
                    $summary['errors'][] = ['account' => $email, 'job' => $name, 'error' => (string)$r['error']];
                    app_log('warn', "cron: $name failed", ['account' => $email, 'error' => $r['error']]);
                } elseif (!empty($r['errors'])) {
                    foreach ((array)$r['errors'] as $e) {
                        $summary['errors'][] = ['account' => $email, 'job' => $name, 'error' => (string)($e['error'] ?? json_encode($e))];
                    }
                    app_log('warn', "cron: $name reported errors", ['account' => $email, 'errors' => $r['errors']]);
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = ['account' => $email, 'job' => $name, 'error' => $e->getMessage()];
                app_log('error', "cron: $name threw", ['account' => $email, 'error' => $e->getMessage()]);
            }
        }

        // The opt-in promise: when nothing is pending any more, stop keeping it.
        if (!cron_needs_credential(cron_pending_state($email))) {
            if (cred_disable($acct['id'])) {
                $summary['expired'][] = $email;
                app_log('info', 'cron: nothing pending; sealed credential removed', ['account' => $email]);
            }
        }
    }

    $summary['seconds'] = round(microtime(true) - $started, 2);
    cron_record_run($summary);
    app_log('info', 'cron: run complete', ['via' => $via, 'accounts' => $summary['accounts'], 'jobs' => $summary['jobs'], 'errors' => count($summary['errors']), 'seconds' => $summary['seconds']]);
    @flock($lock, LOCK_UN);
    @fclose($lock);
    return $summary;
}
