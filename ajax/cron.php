<?php
/**
 * Background tasks — the signed-in side of the scheduler.
 *
 *   status            what Settings → Background tasks shows: which accounts
 *                     opted in, when the scheduler last ran, the lines to
 *                     paste into cPanel, and the Diagnostics facts
 *   enable / disable  seal or withdraw ONE account's credentials for
 *                     unattended jobs — only an account in this session, so
 *                     nobody can opt in a mailbox they are not signed into
 *   regenerate_token  a new URL token (admin)
 *   log               the last lines of data/logs/app.log (admin)
 *
 * The admin gate is the same one the workspace branding uses: if
 * data/update.json names an admin_email, only that address; otherwise any
 * signed-in user, so a single-user install needs no setup.
 */

require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../lib/util.php';
json_fatal_guard();

if (empty($_SESSION['email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

require_once __DIR__ . '/../lib/log.php';
require_once __DIR__ . '/../lib/credstore.php';
require_once __DIR__ . '/../lib/cron.php';

$action  = (string)($_GET['action'] ?? $_POST['action'] ?? 'status');
$me      = (string)$_SESSION['email'];
$isAdmin = app_is_admin($me);

function cron_ok($d)  { echo json_encode($d, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function cron_err($m, $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }

/** The app's root URL for this request — cron.php lives beside index.php. */
function cron_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host  = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir   = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/ajax/cron.php')))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function cron_status_payload($isAdmin) {
    $accounts = [];
    foreach (account_list() as $a) {
        $accounts[] = ['id' => (string)$a['id'], 'email' => (string)$a['email'], 'enabled' => cred_enabled($a['id'])];
    }
    $state = cron_state();
    $now   = cron_now();
    $anyOn = (bool)array_filter($accounts, fn($a) => $a['enabled']);
    // The token is shown to whoever may set the cron up — the same people who
    // may edit the workspace. Anyone else sees that the scheduler exists and
    // whether it is running, which is all they need to opt their account in.
    $token = $isAdmin ? cron_token() : '';
    $url   = $token !== '' ? cron_url($state['base_url'] !== '' ? $state['base_url'] : cron_base_url(), $token, $state['trigger']['path']) : '';
    $path  = realpath(__DIR__ . '/../cron.php') ?: (dirname(__DIR__) . '/cron.php');
    return [
        'ok'        => true,
        'is_admin'  => $isAdmin,
        'admin_email' => app_admin_email(),
        'backend'   => cred_backend(),
        'accounts'  => $accounts,
        'last_run'  => (string)$state['last_run'],
        'stale'     => cron_is_stale($state['last_run'], $now),
        'status'    => cron_status_words($state, $now, $anyOn),
        'next_due'  => cron_seconds_until_due($state, $now),
        'trigger'   => ['method' => (string)$state['trigger']['method'], 'error' => (string)$state['trigger']['error'], 'checked' => (int)$state['trigger']['checked']],
        'chain'     => ['mode' => (string)$state['chain']['mode'], 'reason' => (string)$state['chain']['reason'], 'alive' => cron_chain_alive($state, $now), 'phase' => (string)$state['hop_phase']],
        'hop_len'   => (int)$state['hop_len'],
        'base_url'  => $isAdmin ? (string)$state['base_url'] : '',
        'finish_request' => cron_can_finish_request(),
        'runs'      => array_slice((array)$state['runs'], -5),
        'token'     => $token,
        'url'       => $url,
        'schedule'  => CRON_SCHEDULE,
        'php_cmd'   => $isAdmin ? cron_php_cmd($path) : '',
        'curl_cmd'  => $url !== '' ? cron_curl_cmd($url) : '',
        'cpanel'    => $isAdmin ? cron_cpanel_line($path) : '',
        'curl'      => $url !== '' ? cron_curl_line($url) : '',
        'log'       => app_log_status(),
        'php'       => PHP_VERSION,
        'imap'      => function_exists('imap_open'),
        'sodium'    => function_exists('sodium_crypto_secretbox'),
        'openssl'   => function_exists('openssl_encrypt'),
    ];
}

if ($action === 'status') cron_ok(cron_status_payload($isAdmin));

if ($action === 'enable' || $action === 'disable') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cron_err('POST required', 405);
    $id   = (string)($_POST['acct'] ?? account_effective_id());
    $acct = account_get($id);     // only an account signed in to this session
    if (!$acct) cron_err('That account is not signed in here.', 404);

    if ($action === 'enable') {
        if (cred_backend() === null) cron_err('This server\'s PHP has neither libsodium nor OpenSSL, so credentials cannot be sealed.', 500);
        if (!cred_enable($acct))     cron_err('Could not store the credential — is the data folder writable by the web server?', 500);
        app_log('info', 'background tasks enabled', ['account' => $acct['email'], 'by' => $me]);
        // Start now rather than at the next visit: learn our address from this
        // signed-in request, find a way to reach cron.php, and kick the first
        // run or hop over the loopback. Methods that must wait for the
        // response to go (inline, sync) are left to the tick at shutdown.
        cron_learn_base_url();
        $st = cron_state();
        // Switching on is the moment somebody is watching: unless a loopback is
        // already confirmed, look for one again now rather than in a day.
        if (!cron_is_loopback_method($st['trigger']['method']) || (cron_now() - (int)$st['trigger']['checked']) > 3600) cron_probe();
        $st = cron_state();
        $kicked = false;
        if (cron_is_loopback_method($st['trigger']['method']) && !cron_chain_alive($st, cron_now()) && !cron_run_in_progress()) {
            $kicked = !empty(cron_trigger(cron_due($st, cron_now()) ? 'run' : 'hop', 'enable')['ok']);
        }
        // Kicked: this request's own shutdown tick may rest. Not kicked (inline,
        // sync): let it fire now rather than up to a minute from now.
        if ($kicked) @touch(app_data_root() . '/cron.tick', cron_now());
        else         @unlink(app_data_root() . '/cron.tick');
    } else {
        if (!cred_disable($id))      cron_err('Could not remove the stored credential.', 500);
        app_log('info', 'background tasks disabled', ['account' => $acct['email'], 'by' => $me]);
    }
    cron_ok(cron_status_payload($isAdmin));
}

if ($action === 'regenerate_token') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cron_err('POST required', 405);
    if (!$isAdmin) cron_err('Only ' . app_admin_email() . ' can change the scheduler for this installation.', 403);
    if (cron_regenerate_token() === '') cron_err('Could not write the new token — is the data folder writable?', 500);
    app_log('info', 'scheduler token regenerated', ['by' => $me]);
    cron_ok(cron_status_payload($isAdmin));
}

if ($action === 'reprobe') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cron_err('POST required', 405);
    if (!$isAdmin) cron_err('Only ' . app_admin_email() . ' can re-check the scheduler for this installation.', 403);
    cron_learn_base_url();
    $r = cron_probe();
    app_log('info', 'scheduler: wake method re-checked', ['by' => $me, 'method' => $r['method']]);
    cron_ok(cron_status_payload($isAdmin));
}

if ($action === 'log') {
    if (!$isAdmin) cron_err('Only ' . app_admin_email() . ' can read the log for this installation.', 403);
    $n = max(20, min(500, (int)($_GET['n'] ?? 200)));
    cron_ok(['ok' => true, 'lines' => app_log_tail($n), 'status' => app_log_status()]);
}

cron_err('Unknown action', 400);
