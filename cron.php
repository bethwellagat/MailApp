<?php
/**
 * cron.php — the scheduler's entry point.
 *
 * Runs the unattended jobs (scheduled sends, out-of-office replies, snooze
 * wake-ups, filters) for every account that opted in under Settings →
 * Background tasks. Until this existed those jobs ran only from an open
 * browser tab; a message scheduled for 8 am left when the first person opened
 * their inbox.
 *
 * Two ways to call it, both every five minutes (Settings → Background tasks
 * prints the exact lines; they are not written here because a cron schedule
 * begins with the two characters that end a PHP comment):
 *
 *   php -q /home/<user>/public_html/cron.php
 *   curl -fsS "https://your.domain/cron.php?token=…"
 *
 * The URL form needs the token Settings shows; the CLI form needs none,
 * because reaching the CLI already means being the hosting account. Nothing
 * here starts a PHP session: each account's sealed credentials are opened
 * and mirrored into the request's own $_SESSION array, which is just the
 * place every job already looks.
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
}

if (!function_exists('imap_open')) {
    if (!$isCli) http_response_code(500);
    echo json_encode(['error' => 'PHP IMAP extension is not enabled']), "\n";
    exit($isCli ? 1 : 0);
}

ensure_data_guards();
require_once __DIR__ . '/lib/jobs.php';

$summary = cron_run_all();
echo json_encode($summary, JSON_INVALID_UTF8_SUBSTITUTE), "\n";
if ($isCli) exit(empty($summary['errors']) ? 0 : 1);
