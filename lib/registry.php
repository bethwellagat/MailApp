<?php
/**
 * The installation registry — one place that knows where this app runs, and
 * one button that updates every copy.
 *
 * The app is dropped onto a subdomain and forgotten; nothing anywhere recorded
 * which domains were running it, or which were six weeks stale. This adds an
 * OPT-IN check-in: an install with data/registry.json pointing at a registry
 * posts a few facts about itself every REGISTRY_INTERVAL, and the registry's
 * reply says whether a fleet update has been asked for.
 *
 * Updates are PULLED, never pushed. The registry cannot reach into a shared
 * host and holds no credential for one: it only answers "an update was
 * requested at <time>", and an install that has opted in applies it with the
 * updater it already has, from the GitHub repo IT is configured for. The worst
 * a forged reply could do is make an install update itself to its own repo's
 * head — and the reply is signed anyway.
 *
 * What is sent: host, a random install id, version, build, PHP version,
 * whether IMAP is present, the scheduler's state, how many people use it.
 * Never an address, never a message, never a credential. An install with no
 * data/registry.json sends nothing at all, so a fresh drop-in still phones
 * nowhere.
 */

require_once __DIR__ . '/log.php';     // app_data_root()
require_once __DIR__ . '/util.php';    // atomic_write_json(), poll_gate()

const REGISTRY_INTERVAL   = 900;    // an install checks in at most this often
const REGISTRY_STALE      = 7200;   // not seen for this long → the registry calls it quiet
const REGISTRY_WINDOW     = 300;    // a signature older than this is refused (replay)
const REGISTRY_TIMEOUT    = 6;      // seconds; a check-in never holds anything up for long
const REGISTRY_KEEP       = 500;    // records kept; a fleet is not bigger than this

/* ---------- signing: both directions, pure ---------- */

/** The signature for a body at a moment. Pure. */
function registry_sign($body, $secret, $ts) {
    return hash_hmac('sha256', (int)$ts . '.' . (string)$body, (string)$secret);
}

/**
 * Is this signature right, and recent? Constant-time, and a body signed more
 * than REGISTRY_WINDOW ago is refused so a captured request cannot be replayed
 * tomorrow. Pure.
 */
function registry_sig_ok($body, $secret, $ts, $sig, $now = null, $window = REGISTRY_WINDOW) {
    if (!is_string($sig) || $sig === '' || !is_string($secret) || $secret === '') return false;
    $ts  = (int)$ts;
    $now = $now ?? time();
    if ($ts <= 0 || abs($now - $ts) > $window) return false;
    return hash_equals(registry_sign($body, $secret, $ts), $sig);
}

function registry_new_secret() { return bin2hex(random_bytes(24)); }

/* ---------- the client half: an install that reports in ---------- */

function registry_client_file() { return app_data_root() . '/registry.json'; }

function registry_client() {
    $f = registry_client_file();
    $c = is_file($f) ? @json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($c)) $c = [];
    return $c + ['url' => '', 'secret' => '', 'install_id' => '', 'auto_update' => true,
                 'last_checkin' => 0, 'last_error' => '', 'applied_at' => 0, 'last_result' => ''];
}

function registry_client_save(array $c) {
    $f = registry_client_file();
    $ok = (bool)atomic_write_json($f, $c);
    if ($ok) @chmod($f, 0600);      // it holds the shared secret
    return $ok;
}

function registry_configured(?array $c = null) {
    $c = $c ?? registry_client();
    return $c['url'] !== '' && $c['secret'] !== '';
}

/** This install's own id: random, kept in data/ so it survives every update. */
function registry_install_id() {
    $c = registry_client();
    if ($c['install_id'] === '') {
        $c['install_id'] = bin2hex(random_bytes(8));
        registry_client_save($c);
    }
    return $c['install_id'];
}

/** Due for a check-in? Pure. */
function registry_due(array $c, $now = null, $interval = REGISTRY_INTERVAL) {
    return ((int)($now ?? time()) - (int)$c['last_checkin']) >= $interval;
}

/** The facts about this install. No address, no message, no credential. */
function registry_payload($now = null) {
    $version = function_exists('update_app_version') ? update_app_version() : ['version' => '', 'date' => ''];
    $users = count((array)@glob(app_data_root() . '/prefs/*.json'));
    $sched = 'unknown';
    if (function_exists('cron_state') && function_exists('cron_status_words')) {
        $st = cron_state();
        $on = function_exists('cred_list') ? count(cred_list()) > 0 : false;
        $sched = cron_status_words($st, $now ?? time(), $on)['state'];
    }
    return [
        'install_id' => registry_install_id(),
        'host'       => (string)($_SERVER['HTTP_HOST'] ?? php_uname('n')),
        'version'    => (string)($version['version'] ?? ''),
        'build'      => function_exists('update_current_version') ? substr((string)update_current_version(), 0, 7) : '',
        'php'        => PHP_VERSION,
        'imap'       => function_exists('imap_open'),
        'scheduler'  => $sched,
        'users'      => $users,
        'applied_at' => (int)registry_client()['applied_at'],
        'result'     => (string)registry_client()['last_result'],
        'at'         => (int)($now ?? time()),
    ];
}

/** An HTTPS POST that never holds a request up for long. Returns [body, code, error]. */
function registry_post($url, $body, array $headers, $timeout = REGISTRY_TIMEOUT) {
    if (isset($GLOBALS['REGISTRY_TRANSPORT'])) return ($GLOBALS['REGISTRY_TRANSPORT'])($url, $body, $headers, $timeout);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3,
            // These hosts rewrite /registry.php to /registry, and a plain
            // redirect would turn this POST into a GET and lose the payload.
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_POSTREDIR => (defined('CURL_REDIR_POST_ALL') ? CURL_REDIR_POST_ALL : 7),
            CURLOPT_USERAGENT => 'WebMail-Registry',
        ]);
        $out  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [(string)$out, $code, $err];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
                                             'timeout' => $timeout, 'ignore_errors' => true]]);
    $out  = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ((array)($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\d\.\d (\d{3})#', $h, $m)) $code = (int)$m[1];
    return [(string)$out, $code, $out === false ? 'request failed' : ''];
}

/**
 * Applying a remote update rewrites this install's files, so it only happens
 * where that cannot leave a visitor staring at a half-sent page: a background
 * request (the scheduler, or the CLI), or a server that can finish the
 * response first.
 */
function registry_may_apply($background = false, $sapi = null) {
    if ($background || ($sapi ?? PHP_SAPI) === 'cli') return true;
    return function_exists('cron_can_finish_request') ? cron_can_finish_request() : false;
}

/**
 * Report in, and act on the reply. Never throws: a registry that is down must
 * not become the visitor's problem. Returns what happened, for the tests and
 * the log.
 */
function registry_checkin($background = false, $now = null) {
    $now = $now ?? time();
    $c   = registry_client();
    if (!registry_configured($c)) return ['skipped' => 'not configured'];
    try {
        $payload = registry_payload($now);
        $body    = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig     = registry_sign($body, $c['secret'], $now);
        [$out, $code, $err] = registry_post($c['url'], $body, [
            'Content-Type: application/json', 'X-Registry-Time: ' . $now, 'X-Registry-Sig: ' . $sig,
        ]);
        $c = registry_client();                 // re-read: a long POST may have raced a save
        $c['last_checkin'] = $now;
        if ($err !== '' || $code !== 200) {
            $c['last_error'] = $err !== '' ? $err : ('HTTP ' . $code);
            registry_client_save($c);
            return ['error' => $c['last_error']];
        }
        $reply = @json_decode($out, true);
        // The reply is signed too: nobody but the registry may ask an install
        // to run its updater.
        $rsig = $reply['sig'] ?? ''; $rts = (int)($reply['ts'] ?? 0);
        unset($reply['sig']);
        $canon = json_encode($reply, JSON_UNESCAPED_SLASHES);
        if (!is_array($reply) || !registry_sig_ok($canon, $c['secret'], $rts, $rsig, $now)) {
            $c['last_error'] = 'the reply was not signed by the registry';
            registry_client_save($c);
            app_log('warn', 'registry: unsigned or stale reply ignored', ['url' => $c['url']]);
            return ['error' => $c['last_error']];
        }
        $c['last_error'] = '';
        $wanted = (int)($reply['update_at'] ?? 0);
        $act    = $wanted > 0 && $wanted > (int)$c['applied_at'];
        if ($act && empty($c['auto_update'])) {
            registry_client_save($c);
            return ['ok' => true, 'update_offered' => true, 'applied' => false, 'why' => 'remote updates are switched off here'];
        }
        if (!$act) { registry_client_save($c); return ['ok' => true, 'applied' => false]; }
        if (!registry_may_apply($background)) {
            registry_client_save($c);
            return ['ok' => true, 'update_offered' => true, 'applied' => false, 'why' => 'waiting for a background run'];
        }
        require_once __DIR__ . '/updater.php';
        $r = update_apply();
        $c = registry_client();
        $c['last_checkin'] = $now;
        $c['applied_at']   = $wanted;           // acted on this request, success or not: it is not retried in a loop
        $c['last_result']  = !empty($r['error']) ? ('failed: ' . $r['error'])
                           : ('updated to ' . substr((string)($r['version'] ?? ''), 0, 7) . ' · ' . (int)($r['files'] ?? 0) . ' files');
        registry_client_save($c);
        app_log(!empty($r['error']) ? 'error' : 'info', 'registry: fleet update', ['result' => $c['last_result']]);
        return ['ok' => true, 'applied' => true, 'result' => $c['last_result']];
    } catch (\Throwable $e) {
        app_log('warn', 'registry: check-in failed', ['error' => $e->getMessage()]);
        return ['error' => $e->getMessage()];
    }
}

/** Called from the end of a request; cheap, and at most once every interval install-wide. */
function registry_tick($background = false) {
    if (!registry_configured()) return ['skipped' => 'not configured'];
    if (!poll_gate('__system__', 'registry', REGISTRY_INTERVAL)) return ['skipped' => 'throttled'];
    return registry_checkin($background);
}

/* ---------- the server half: the install that keeps the list ---------- */

function registry_server_file() { return app_data_root() . '/registry-server.json'; }
function registry_dir()         { return app_data_root() . '/registry'; }

/** The registry's own config, or null when this install is not a registry. */
function registry_server() {
    $f = registry_server_file();
    $s = is_file($f) ? @json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($s) || empty($s['secret'])) return null;
    return $s + ['secret' => '', 'update_at' => 0, 'updated_by' => ''];
}

function registry_server_save(array $s) {
    $ok = (bool)atomic_write_json(registry_server_file(), $s);
    if ($ok) @chmod(registry_server_file(), 0600);
    return $ok;
}

/** Turn this install into the registry (or return the existing config). */
function registry_server_enable($by = '') {
    $s = registry_server();
    if ($s) return $s;
    $s = ['secret' => registry_new_secret(), 'update_at' => 0, 'updated_by' => (string)$by];
    return registry_server_save($s) ? $s : null;
}

function registry_server_disable() { return is_file(registry_server_file()) ? @unlink(registry_server_file()) : true; }

function registry_record_path($id) { return registry_dir() . '/' . preg_replace('/[^a-f0-9]/', '', (string)$id) . '.json'; }

/** Remember what an install said about itself. Returns the stored record. */
function registry_store(array $p, $now = null) {
    $now = $now ?? time();
    $dir = registry_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return null;
    // An install id is what registry_install_id() makes and nothing else: a
    // value that has to be scrubbed into shape was not written by this app.
    $id = (string)($p['install_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{8,32}$/', $id)) return null;
    $old = @json_decode((string)@file_get_contents(registry_record_path($id)), true);
    $rec = [
        'install_id' => $id,
        'host'       => mb_substr(preg_replace('/[^A-Za-z0-9.:_-]/', '', (string)($p['host'] ?? '')), 0, 120),
        'version'    => mb_substr(preg_replace('/[^0-9A-Za-z.\-]/', '', (string)($p['version'] ?? '')), 0, 20),
        'build'      => mb_substr(preg_replace('/[^0-9a-f]/', '', (string)($p['build'] ?? '')), 0, 12),
        'php'        => mb_substr(preg_replace('/[^0-9A-Za-z.\-]/', '', (string)($p['php'] ?? '')), 0, 20),
        'imap'       => !empty($p['imap']),
        'scheduler'  => mb_substr(preg_replace('/[^a-z]/', '', (string)($p['scheduler'] ?? '')), 0, 12),
        'users'      => max(0, min(100000, (int)($p['users'] ?? 0))),
        'applied_at' => max(0, (int)($p['applied_at'] ?? 0)),
        'result'     => mb_substr((string)($p['result'] ?? ''), 0, 160),
        'first_seen' => (int)($old['first_seen'] ?? $now),
        'last_seen'  => $now,
        'update_at'  => (int)($old['update_at'] ?? 0),   // a request aimed at this one install
    ];
    if (!atomic_write_json(registry_record_path($id), $rec)) return null;
    registry_prune($now);
    return $rec;
}

/** Keep the newest REGISTRY_KEEP records; a fleet does not outgrow that. */
function registry_prune($now = null) {
    $files = (array)@glob(registry_dir() . '/*.json');
    if (count($files) <= REGISTRY_KEEP) return 0;
    usort($files, fn($a, $b) => (int)@filemtime($b) - (int)@filemtime($a));
    $n = 0;
    foreach (array_slice($files, REGISTRY_KEEP) as $f) if (@unlink($f)) $n++;
    return $n;
}

/** One record, read back. */
function registry_record($id) {
    $r = @json_decode((string)@file_get_contents(registry_record_path($id)), true);
    return is_array($r) ? $r : null;
}

/**
 * What the registry knows, newest check-in first, with the two judgements the
 * page needs: quiet (not seen lately) and behind (an older version than the
 * newest in the fleet). Pure, given the records. Tested.
 */
function registry_summarise(array $records, $now = null, $newest = '') {
    $now = $now ?? time();
    if ($newest === '') {
        foreach ($records as $r) if (version_compare((string)$r['version'], $newest, '>')) $newest = (string)$r['version'];
    }
    $out = [];
    foreach ($records as $r) {
        $r['quiet']  = ($now - (int)$r['last_seen']) > REGISTRY_STALE;
        $r['behind'] = $newest !== '' && (string)$r['version'] !== '' && version_compare((string)$r['version'], $newest, '<');
        $out[] = $r;
    }
    usort($out, fn($a, $b) => (int)$b['last_seen'] - (int)$a['last_seen']);
    return ['newest' => $newest, 'installs' => $out];
}

function registry_all($now = null) {
    $records = [];
    foreach ((array)@glob(registry_dir() . '/*.json') as $f) {
        $r = @json_decode((string)@file_get_contents($f), true);
        if (is_array($r) && !empty($r['install_id'])) $records[] = $r;
    }
    return registry_summarise($records, $now);
}

/** Ask every install (or one) to update. Returns the moment that was recorded. */
function registry_request_update($id = '', $by = '', $now = null) {
    $now = $now ?? time();
    if ($id === '') {
        $s = registry_server();
        if (!$s) return 0;
        $s['update_at']  = $now;
        $s['updated_by'] = (string)$by;
        return registry_server_save($s) ? $now : 0;
    }
    $rec = registry_record($id);
    if (!$rec) return 0;
    $rec['update_at'] = $now;
    return atomic_write_json(registry_record_path($rec['install_id']), $rec) ? $now : 0;
}

/**
 * What to tell an install that has just reported in: the later of the
 * fleet-wide request and any request aimed at it alone. Pure.
 */
function registry_reply_for(array $server, ?array $rec = null, $now = null) {
    $at = max((int)($server['update_at'] ?? 0), (int)($rec['update_at'] ?? 0));
    return ['ok' => true, 'update_at' => $at, 'ts' => (int)($now ?? time())];
}
