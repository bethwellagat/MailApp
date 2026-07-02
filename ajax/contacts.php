<?php
/**
 * Compose autocomplete source. GET ?q=<text>&limit=<n> returns the user's
 * harvested address book entries ranked against the query.
 * Read-only and per-user (scoped to the session email), so no CSRF token is
 * required — consistent with the GET branch of ajax/prefs.php.
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'GET required']);
    exit;
}
session_write_close(); // release the session lock early — avoids request serialization (see fetch.php)

$q     = (string)($_GET['q'] ?? '');
$limit = (int)($_GET['limit'] ?? 8);

echo json_encode([
    'ok'       => true,
    'contacts' => contacts_search($_SESSION['email'], $q, $limit),
]);
