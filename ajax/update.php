<?php
/**
 * Software-update endpoint. POST only, authenticated, CSRF-protected.
 *   action=check → compare deployed vs upstream commit
 *   action=apply → download + install the latest code (never touches data/)
 * If data/update.json sets "admin_email", only that user may use either action.
 */
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php'; accounts_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/updater.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}
csrf_require();
session_write_close();

$action = $_POST['action'] ?? '';
$cfg    = update_config();

// Optional admin gate.
if ($cfg && $cfg['admin_email'] !== '' && strtolower((string)$_SESSION['email']) !== $cfg['admin_email']) {
    http_response_code(403);
    echo json_encode(['error' => 'Only the administrator can manage software updates.']);
    exit;
}

if ($action === 'check') {
    echo json_encode(update_check()); // read-only: safe for any signed-in user
    exit;
}
if ($action === 'apply') {
    // Installing an update replaces the application for EVERYONE on this install,
    // so it is restricted to the account this browser session signed in with.
    // Extra mailboxes added to a session belong to other people and must not be
    // able to overwrite the app.
    //
    // Deliberately NOT deny-until-configured: updates must keep working with zero
    // per-site setup. On a multi-user install, set "admin_email" in
    // data/update.json to limit updating to one person (enforced above).
    $primary = (string)($_SESSION['primary_account'] ?? '');
    $acting  = (string)(account_effective_id() ?? '');
    if ($primary !== '' && $acting !== '' && $acting !== $primary) {
        http_response_code(403);
        echo json_encode(['error' => 'Only the account this session signed in with can install updates.']);
        exit;
    }
    echo json_encode(update_apply());
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
