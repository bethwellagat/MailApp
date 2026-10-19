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
    if ($action === 'all') _c_ok(['contacts' => contacts_all($owner), 'groups' => contacts_groups($owner)]);
    if ($action === 'groups') _c_ok(['groups' => contacts_groups($owner)]);
    if ($action === 'duplicates') _c_ok(['duplicates' => contacts_duplicates($owner)]);
    if ($action === 'export') {
        $csv  = (($_GET['format'] ?? 'vcf') === 'csv');
        $body = $csv ? contacts_export_csv($owner) : contacts_export_vcard($owner);
        header('Content-Type: ' . ($csv ? 'text/csv' : 'text/vcard') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="contacts-' . date('Y-m-d') . ($csv ? '.csv' : '.vcf') . '"');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }
    // Default: ranked autocomplete for compose.
    _c_ok(['contacts' => contacts_search($owner, (string)($_GET['q'] ?? ''), (int)($_GET['limit'] ?? 8))]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'save') {
        [$ok, $err] = contacts_upsert(
            $owner,
            (string)($_POST['email'] ?? ''),
            (string)($_POST['name'] ?? ''),
            (string)($_POST['original_email'] ?? ''),
            ['phone' => $_POST['phone'] ?? '', 'company' => $_POST['company'] ?? '', 'role' => $_POST['role'] ?? '',
             'notes' => $_POST['notes'] ?? '', 'groups' => $_POST['groups'] ?? '']
        );
        if (!$ok) _c_fail($err !== '' ? $err : 'Could not save the contact.', 400);
        _c_ok(['contacts' => contacts_all($owner)]);
    }
    if ($action === 'delete') {
        if (!contacts_delete($owner, (string)($_POST['email'] ?? ''))) _c_fail('Contact not found.', 404);
        _c_ok(['contacts' => contacts_all($owner), 'groups' => contacts_groups($owner)]);
    }
    if ($action === 'import') {
        $text = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ((int)$_FILES['file']['size'] > 4 * 1048576) _c_fail('That file is larger than 4 MB.', 400);
            $text = (string)file_get_contents($_FILES['file']['tmp_name']);
        } else {
            $text = (string)($_POST['text'] ?? '');
        }
        if (trim($text) === '') _c_fail('Choose a .vcf or .csv file to import.', 400);
        $entries = contacts_parse_any($text);
        if (!$entries) _c_fail('No contacts with an email address were found in that file. vCard (.vcf) and CSV with an Email column are supported.', 400);
        $r = contacts_import($owner, $entries);
        _c_ok(['result' => $r, 'contacts' => contacts_all($owner), 'groups' => contacts_groups($owner)]);
    }
    if ($action === 'merge') {
        $others = isset($_POST['others']) && is_array($_POST['others']) ? $_POST['others'] : preg_split('/[,\s]+/', (string)($_POST['others'] ?? ''));
        [$ok, $err] = contacts_merge($owner, (string)($_POST['keep'] ?? ''), array_filter((array)$others));
        if (!$ok) _c_fail($err !== '' ? $err : 'Could not merge.', 400);
        _c_ok(['contacts' => contacts_all($owner), 'groups' => contacts_groups($owner), 'duplicates' => contacts_duplicates($owner)]);
    }
    _c_fail('Unknown action', 400);
}

http_response_code(405);
echo json_encode(['error' => 'GET or POST required']);
