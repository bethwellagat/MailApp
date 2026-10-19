<?php
/**
 * The scheduler — what runs the unattended jobs when no browser is open.
 *
 * Four things in this app are advertised as happening on their own and, until
 * now, did not: a scheduled send goes out, an out-of-office reply is sent, a
 * snoozed message comes back, a filter files new mail. Each ran only from the
 * browser's poll loop, so each stopped the moment the last tab closed. A
 * message scheduled for 8 am left when the first person opened their inbox.
 *
 * cron.php (repo root) is the entry point; the host's cron hits it every few
 * minutes by URL with a token, or runs it with the PHP CLI. It walks every
 * account that opted in (see lib/credstore.php), opens that account's
 * credentials, mirrors them into the flat $_SESSION keys every job already
 * reads, and runs the four jobs in turn. The browser poll keeps running them
 * too — for hosts with no cron, nothing changes.
 *
 * Everything that decides something is a pure function here, so the token
 * check, the cron lines shown in Settings and the "may this credential be
 * dropped" rule are all covered by tests that need no mail server.
 */

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/credstore.php';

const CRON_BUDGET_SECONDS = 50;      // leave headroom under a 60 s execution limit
const CRON_STALE_SECONDS  = 900;     // a 5-minute cron that has not run in 15 min is "not running"
const CRON_RUNS_KEPT      = 20;

function cron_file() { return app_data_root() . '/cron.json'; }

/** The persisted scheduler state: the token, when it last ran, what happened. */
function cron_state() {
    $f = cron_file();
    $d = is_file($f) ? @json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d)) $d = [];
    $d += ['token' => '', 'last_run' => '', 'runs' => []];
    if (!is_array($d['runs'])) $d['runs'] = [];
    return $d;
}

function cron_save_state(array $d) {
    $dir = dirname(cron_file());
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    return (bool)atomic_write_json(cron_file(), $d);
}

/** The URL token, created on first use. 48 hex chars from 24 random bytes. */
function cron_token() {
    $d = cron_state();
    if (is_string($d['token']) && strlen($d['token']) >= 32) return $d['token'];
    return cron_regenerate_token();
}

function cron_regenerate_token() {
    $d = cron_state();
    try { $d['token'] = bin2hex(random_bytes(24)); } catch (\Throwable $e) { return ''; }
    cron_save_state($d);
    return $d['token'];
}

/** Constant-time compare; anything not a non-empty string is a mismatch. */
function cron_token_ok($given, $expected) {
    if (!is_string($given) || !is_string($expected) || $given === '' || $expected === '') return false;
    return hash_equals($expected, $given);
}

/* ---------- what Settings shows ---------- */

function cron_url($baseUrl, $token) {
    return rtrim((string)$baseUrl, '/') . '/cron.php?token=' . rawurlencode((string)$token);
}

/** The line to paste into cPanel → Cron Jobs: every five minutes, quietly. */
function cron_cpanel_line($cronPath, $phpBin = 'php') {
    return '*/5 * * * * ' . $phpBin . ' -q ' . escapeshellarg((string)$cronPath) . ' >/dev/null 2>&1';
}

/** The same schedule as a curl line, for hosts whose cron cannot run PHP directly. */
function cron_curl_line($url) {
    return '*/5 * * * * curl -fsS ' . escapeshellarg((string)$url) . ' >/dev/null 2>&1';
}

/** True when the scheduler has not reported in for longer than CRON_STALE_SECONDS. */
function cron_is_stale($lastRunIso, $now = null, $maxAge = CRON_STALE_SECONDS) {
    $now = $now ?? time();
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

/* ---------- the run ---------- */

/** Remember a run; keep the last CRON_RUNS_KEPT. */
function cron_record_run(array $summary) {
    $d = cron_state();
    $summary['at'] = $summary['at'] ?? gmdate('c');
    $d['last_run'] = $summary['at'];
    $d['runs'][]   = $summary;
    if (count($d['runs']) > CRON_RUNS_KEPT) $d['runs'] = array_slice($d['runs'], -CRON_RUNS_KEPT);
    return cron_save_state($d);
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
 * Serialised by a lock so two overlapping crons cannot both send the same
 * queued message; bounded by a time budget so a slow mail server cannot push
 * a run past the host's execution limit; and each job is isolated, so one
 * account's broken IMAP login cannot stop the next account's scheduled send.
 */
function cron_run_all($budget = CRON_BUDGET_SECONDS) {
    $started = microtime(true);
    $summary = ['at' => gmdate('c'), 'accounts' => 0, 'jobs' => [], 'errors' => [], 'expired' => [], 'skipped' => 0, 'seconds' => 0];

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
    app_log('info', 'cron: run complete', ['accounts' => $summary['accounts'], 'jobs' => $summary['jobs'], 'errors' => count($summary['errors']), 'seconds' => $summary['seconds']]);
    @flock($lock, LOCK_UN);
    @fclose($lock);
    return $summary;
}
