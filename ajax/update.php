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
    echo json_encode(update_check());
    exit;
}
if ($action === 'apply') {
    echo json_encode(update_apply());
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
