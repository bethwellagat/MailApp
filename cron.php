<?php
/**
 * cron.php — the scheduler's entry point.
 *
 * Runs the unattended jobs (scheduled sends, out-of-office replies, snooze
 * wake-ups, filters) for every account that opted in under Settings →
 * Background tasks. Three callers reach it:
 *
 *   the app itself   Every ordinary request ends with a tick (lib/cron.php)
 *                    that, when a run is due, calls this file in the
 *                    background with ?token&run=1 — and each run books a hop
 *                    (?token&hop=1): a background request that waits until
 *                    the next run is due and runs again. No cron job needed.
 *   a host cron      Optional. `php -q cron.php` from the CLI needs no token;
 *                    the URL form needs the token Settings shows.
 *   the probe        ?token&probe=1 answers without running anything; it is
 *                    how the app finds a way to reach itself.
 *
 * Nothing here starts a PHP session: each account's sealed credentials are
 * opened and mirrored into the request's own $_SESSION array, which is just
 * the place every job already looks.
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

require_once __DIR__ . '/lib/util.php';
if (!$isCli) json_fatal_guard();
require_once __DIR__ . '/lib/log.php';
require_once __DIR__ . '/lib/credstore.php';
require_once __DIR__ . '/lib/cron.php';

if (!$isCli) {
    $state = cron_state();
    if (!cron_token_ok((string)($_GET['token'] ?? ''), (string)$state['token'])) {
        // One answer for "no token", "wrong token" and "never set up": there is
        // nothing here for a stranger to learn by probing.
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    if (!empty($_GET['probe'])) {
        echo json_encode(['ok' => true, 'probe' => true, 'php' => PHP_VERSION, 'imap' => cron_imap_available()]), "\n";
        exit;
    }
}

if (!cron_imap_available()) {
    if (!$isCli) http_response_code(500);
    echo json_encode(['error' => 'PHP IMAP extension is not enabled']), "\n";
    exit($isCli ? 1 : 0);
}

ensure_data_guards();
require_once __DIR__ . '/lib/jobs.php';

if (!$isCli && !empty($_GET['hop'])) {
    $r = cron_hop('chain');
    echo json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit;
}

$via = 'url';
if ($isCli) {
    $via = 'cli';
} elseif (!empty($_GET['run'])) {
    $via = (string)($_GET['via'] ?? '');
    if (!in_array($via, ['tick', 'enable', 'chain', 'inline'], true)) $via = 'tick';
}
// A host cron may fire more often than needed; the app's own triggers
// already checked. Either way two runs cannot overlap (cron_run_all locks).
if ($via === 'url' && !cron_due(cron_state(), cron_now(), CRON_INTERVAL - 60)) {
    echo json_encode(['ok' => true, 'skipped' => 'not due yet', 'last_run' => cron_state()['last_run']]), "\n";
    exit;
}

$summary = cron_run_all(CRON_BUDGET_SECONDS, $via);
if (!in_array($via, ['cli', 'url'], true)) cron_book_next_hop();   // a run the app started begins the chain
echo json_encode($summary, JSON_INVALID_UTF8_SUBSTITUTE), "\n";
if ($isCli) exit(empty($summary['errors']) ? 0 : 1);
