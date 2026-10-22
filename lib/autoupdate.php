<?php
/**
 * Automatic updates: an install follows the repository on its own.
 *
 * The fleet is not meant to be herded. When a release is pushed, every copy
 * should be running it shortly afterwards without anyone opening a settings
 * page — so each install asks GitHub for the head of its branch every
 * UPDATE_AUTO_INTERVAL and, when that is not what it is running, applies it
 * with the updater it already has.
 *
 * This needs no registry and no key: an install that reports to nothing still
 * keeps itself current. The registry's "Update all" stays, for when waiting a
 * quarter of an hour is a quarter of an hour too long.
 *
 * Where it runs matters. Rewriting the app's own files mid-request would leave
 * a visitor on a half-sent page, so this only ever happens in a background
 * request (the scheduler, the CLI) or on a server that can finish the response
 * first — the same rule the fleet update follows.
 *
 * A release that fails to apply is not retried every quarter of an hour: the
 * failing commit is remembered and left alone for UPDATE_AUTO_RETRY, while a
 * newer commit is always tried at once. An install that should not follow the
 * repository at all sets "auto": false in data/update.json.
 */

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/updater.php';

const UPDATE_AUTO_INTERVAL = 900;    // ask GitHub at most this often
const UPDATE_AUTO_RETRY    = 3600;   // leave a failing release alone for this long

function auto_state_file() { return app_data_root() . '/autoupdate.json'; }

function auto_state() {
    $f = auto_state_file();
    $d = is_file($f) ? @json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d)) $d = [];
    return $d + ['last_check' => 0, 'last_sha' => '', 'last_result' => '', 'failed_sha' => '', 'failed_at' => 0, 'applied_at' => 0];
}

function auto_state_save(array $d) { return (bool)atomic_write_json(auto_state_file(), $d); }

/** Is this install meant to follow the repository? Default yes. */
function auto_enabled() {
    $f = app_data_root() . '/update.json';
    $c = is_file($f) ? @json_decode((string)@file_get_contents($f), true) : null;
    return !is_array($c) || !array_key_exists('auto', $c) || !empty($c['auto']);
}

function auto_set_enabled($on) {
    $f = app_data_root() . '/update.json';
    $c = is_file($f) ? @json_decode((string)@file_get_contents($f), true) : [];
    if (!is_array($c)) $c = [];
    $c['auto'] = (bool)$on;
    $ok = (bool)atomic_write_json($f, $c);
    if ($ok) @chmod($f, 0600);   // it may also hold the GitHub token
    return $ok;
}

/**
 * Should this install apply what the repository is holding? Pure, so every
 * branch is decided in a test rather than against GitHub.
 */
function auto_decide($deployed, $head, array $state, $now, $retry = UPDATE_AUTO_RETRY) {
    $head     = (string)$head;
    $deployed = (string)$deployed;
    if ($head === '')                return ['apply' => false, 'why' => 'the repository could not be reached'];
    if ($head === $deployed)         return ['apply' => false, 'why' => 'already running it'];
    if ($deployed === '')            return ['apply' => true,  'why' => 'this install has never recorded a build'];
    if ((string)$state['failed_sha'] === $head && ($now - (int)$state['failed_at']) < $retry) {
        return ['apply' => false, 'why' => 'that release failed here; waiting before trying it again'];
    }
    return ['apply' => true, 'why' => 'a newer release is available'];
}

/* The two seams. Tests drive these instead of GitHub and the real updater —
   a test that can rewrite the working tree is a test nobody can safely run. */
function auto_check()  { return isset($GLOBALS['AUTOUPDATE_CHECK']) ? ($GLOBALS['AUTOUPDATE_CHECK'])() : update_check(); }
function auto_apply()  { return isset($GLOBALS['AUTOUPDATE_APPLY']) ? ($GLOBALS['AUTOUPDATE_APPLY'])() : update_apply(); }
function auto_may_apply($background = false, $sapi = null) {
    if ($background || ($sapi ?? PHP_SAPI) === 'cli') return true;
    return function_exists('cron_can_finish_request') ? cron_can_finish_request() : false;
}

/**
 * One turn of the loop. Cheap when nothing is due — a single timestamp read.
 * Never throws: an install that cannot update itself must still serve mail.
 */
function auto_tick($background = false, $now = null) {
    $now = $now ?? time();
    try {
        if (!auto_enabled())                     return ['skipped' => 'switched off here'];
        if (!auto_may_apply($background))        return ['skipped' => 'not a safe moment to rewrite files'];
        $state = auto_state();
        if (($now - (int)$state['last_check']) < UPDATE_AUTO_INTERVAL) return ['skipped' => 'checked recently'];
        $state['last_check'] = $now;
        auto_state_save($state);

        $check = auto_check();
        if (!empty($check['error'])) {
            $state['last_result'] = 'could not check: ' . $check['error'];
            auto_state_save($state);
            return ['error' => $check['error']];
        }
        $head     = (string)($check['latest'] ?? '');   // the check names the head commit 'latest'
        $deployed = (string)($check['current'] ?? '');
        $d = auto_decide($deployed, $head, $state, $now);
        if (!$d['apply']) { $state['last_sha'] = $head; auto_state_save($state); return ['checked' => true] + $d; }

        $r = auto_apply();
        $state = auto_state();
        $state['last_check'] = $now;
        $state['last_sha']   = $head;
        if (!empty($r['error'])) {
            $state['failed_sha'] = $head;
            $state['failed_at']  = $now;
            $state['last_result'] = 'failed: ' . $r['error'];
            auto_state_save($state);
            app_log('error', 'auto-update failed', ['release' => substr($head, 0, 7), 'error' => $r['error']]);
            return ['applied' => false, 'error' => $r['error']];
        }
        $state['failed_sha'] = '';
        $state['failed_at']  = 0;
        $state['applied_at'] = $now;
        $state['last_result'] = 'updated to ' . substr($head, 0, 7) . ' · ' . (int)($r['files'] ?? 0) . ' files';
        auto_state_save($state);
        app_log('info', 'auto-update applied', ['release' => substr($head, 0, 7), 'files' => (int)($r['files'] ?? 0)]);
        return ['applied' => true, 'release' => $head, 'files' => (int)($r['files'] ?? 0)];
    } catch (\Throwable $e) {
        app_log('warn', 'auto-update: tick failed', ['error' => $e->getMessage()]);
        return ['error' => $e->getMessage()];
    }
}
