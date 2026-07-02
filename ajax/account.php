<?php
/**
 * Multi-account management endpoint.
 *
 *   GET  ?action=list            -> { accounts:[...], active:id }
 *   POST action=add              -> validate creds via imap_open (exactly like
 *                                   login), then store in-session; returns list
 *   POST action=remove  &id=...  -> drop a non-primary account
 *   POST action=switch  &id=...  -> set the persisted active account
 *
 * Every signed-in account's credentials live ONLY in $_SESSION (see
 * lib/accounts.php) — never on disk, in cookies, or in logs. The caller must
 * already be authenticated (the primary/login account); this endpoint only
 * layers additional accounts onto that session.
 */
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['email']) || empty($_SESSION['imap_host'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

mb_internal_encoding('UTF-8');

function acct_fail($msg, $code = 400) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function acct_state($extra = []) {
    echo json_encode($extra + [
        'accounts' => account_list(),
        'active'   => account_active_id(),
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ---------- list (read-only) ---------- */
if ($action === 'list' && $method === 'GET') {
    acct_state();
}

if ($method !== 'POST') {
    acct_fail('POST required', 405);
}

/* ---------- add ---------- */
if ($action === 'add') {
    if (!function_exists('imap_open')) {
        acct_fail('PHP IMAP extension is not enabled on this server.', 500);
    }

    $email        = trim($_POST['email'] ?? '');
    $password     = (string)($_POST['password'] ?? '');
    $imap_host    = trim($_POST['imap_host'] ?? '');
    $imap_port    = (int)($_POST['imap_port'] ?? 993);
    $imap_ssl     = isset($_POST['imap_ssl']) && $_POST['imap_ssl'] !== '0' && $_POST['imap_ssl'] !== '';
    $smtp_host    = trim($_POST['smtp_host'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');

    if (!$imap_port) {
        $imap_port = $imap_ssl ? 993 : 143;
    }
    // Same host/name inference as the login screen.
    if ($imap_host === '' && strpos($email, '@') !== false) {
        $domain = substr($email, strpos($email, '@') + 1);
        $imap_host = 'mail.' . $domain;
    }
    if ($smtp_host === '') {
        $smtp_host = preg_replace('/^imap\./i', 'mail.', $imap_host);
    }
    if ($display_name === '' && strpos($email, '@') !== false) {
        $display_name = substr($email, 0, strpos($email, '@'));
    }

    if ($email === '' || $password === '') {
        acct_fail('Please enter the email address and password.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        acct_fail('That email address does not look valid.');
    }

    $flags   = $imap_ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls';
    $mailbox = '{' . $imap_host . ':' . $imap_port . $flags . '}INBOX';

    $mbox = @imap_open($mailbox, $email, $password, OP_HALFOPEN, 1);
    if ($mbox === false) {
        $msgs = imap_errors() ?: [];
        $last = $msgs ? end($msgs) : 'Could not connect to mail server.';
        $clean = $last;
        if (stripos($last, 'authentication') !== false || stripos($last, 'invalid') !== false || stripos($last, 'AUTH') !== false) {
            $clean = 'Incorrect email or password.';
        } elseif (stripos($last, 'unable to') !== false || stripos($last, 'connection') !== false || stripos($last, 'refused') !== false) {
            $clean = 'Could not reach IMAP server at ' . $imap_host . ':' . $imap_port . '.';
        }
        acct_fail($clean, 502);
    }

    @imap_close($mbox);
    imap_errors();
    imap_alerts();

    $id = account_add([
        'email'        => $email,
        'password'     => $password,
        'imap_host'    => $imap_host,
        'imap_port'    => $imap_port,
        'imap_ssl'     => $imap_ssl,
        'smtp_host'    => $smtp_host,
        'display_name' => $display_name,
    ]);
    if ($id === null) {
        acct_fail('Could not add the account.', 500);
    }

    acct_state(['added' => $id]);
}

/* ---------- remove ---------- */
if ($action === 'remove') {
    $id = trim($_POST['id'] ?? '');
    if ($id === '' || account_get($id) === null) {
        acct_fail('Account not found.', 404);
    }
    if (($_SESSION['primary_account'] ?? null) === $id) {
        acct_fail('The primary account cannot be removed. Sign out to end the session.', 409);
    }
    account_remove($id);
    acct_state(['removed' => $id]);
}

/* ---------- switch (persist active) ---------- */
if ($action === 'switch') {
    $id = trim($_POST['id'] ?? '');
    if (!account_set_active($id)) {
        acct_fail('Account not found.', 404);
    }
    acct_state(['active' => $id]);
}

acct_fail('Unknown action.', 400);
