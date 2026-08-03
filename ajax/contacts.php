<?php
/**
 * The user's address book.
 *   GET  ?q=<text>&limit=<n>  ranked entries for compose autocomplete
 *   GET  ?action=all          the whole book, for the Settings manager
 *   POST action=save          create or update one contact (CSRF required)
 *   POST action=delete        remove one contact (CSRF required)
 * Reads are per-user and change nothing, so they need no CSRF token —
 * consistent with the GET branch of ajax/prefs.php. Writes do.
 */
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../lib/contacts.php';

if (empty($_SESSION['email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Reads stay open (per-user, no state change). Writes require CSRF like every
// other mutating endpoint.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../lib/csrf.php';
    csrf_require();
}
session_write_close(); // release the session lock early — avoids request serialization (see fetch.php)

$owner  = $_SESSION['email'];
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

function _c_ok($d)          { echo json_encode(['ok' => true] + $d); exit; }
function _c_fail($m, $c = 400) { http_response_code($c); echo json_encode(['error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // The full book, for the Settings manager.
    if ($action === 'all') _c_ok(['contacts' => contacts_all($owner)]);
    // Default: ranked autocomplete for compose.
    _c_ok(['contacts' => contacts_search($owner, (string)($_GET['q'] ?? ''), (int)($_GET['limit'] ?? 8))]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'save') {
        [$ok, $err] = contacts_upsert(
            $owner,
            (string)($_POST['email'] ?? ''),
            (string)($_POST['name'] ?? ''),
            (string)($_POST['original_email'] ?? '')
        );
        if (!$ok) _c_fail($err !== '' ? $err : 'Could not save the contact.', 400);
        _c_ok(['contacts' => contacts_all($owner)]);
    }
    if ($action === 'delete') {
        if (!contacts_delete($owner, (string)($_POST['email'] ?? ''))) _c_fail('Contact not found.', 404);
        _c_ok(['contacts' => contacts_all($owner)]);
    }
    _c_fail('Unknown action', 400);
}

http_response_code(405);
echo json_encode(['error' => 'GET or POST required']);
