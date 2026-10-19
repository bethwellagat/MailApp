<?php
/**
 * Labels — the registry, and the keyword on the server.
 *
 *   GET  ?action=list                         the account's labels
 *   GET  ?action=flags&folder=…&uids=1,2,3    which labels each UID carries
 *   POST action=save    (JSON)                {id?, name, color}
 *   POST action=delete  id=…
 *   POST action=apply   id=…&set=1|0&folder=…&uids[]=… (or all=1)
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
require_once __DIR__ . '/../lib/labels.php';
require_once __DIR__ . '/../lib/imap.php';

$email  = (string)$_SESSION['email'];
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');
session_write_close();

function lbl_ok($d)  { echo json_encode($d, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function lbl_err($m, $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }

if ($action === 'list') lbl_ok(['ok' => true, 'labels' => labels_list($email)]);

if ($action === 'flags') {
    $folder = trim((string)($_GET['folder'] ?? 'INBOX'));
    $uids   = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['uids'] ?? ''))), fn($u) => $u > 0));
    $labels = labels_list($email);
    if (empty($uids) || empty($labels)) lbl_ok(['ok' => true, 'flags' => (object)[]]);
    if (count($uids) > 200) $uids = array_slice($uids, 0, 200);
    if (!function_exists('imap_open')) lbl_err('PHP IMAP extension is not enabled', 500);
    if (!imap_valid_mailbox($folder)) lbl_err('Invalid folder', 400);
    $mbox = imap_open_box($folder);
    if (!$mbox) lbl_err('Could not open folder "' . $folder . '"');
    $flags = labels_for_uids($mbox, $labels, $uids);
    @imap_close($mbox);
    lbl_ok(['ok' => true, 'flags' => (object)$flags]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') lbl_err('POST required', 405);

if ($action === 'save') {
    $raw = (string)file_get_contents('php://input');
    $in  = json_decode($raw, true);
    if (!is_array($in)) $in = $_POST;
    $r = label_save($email, $in);
    if (!empty($r['error'])) lbl_err($r['error']);
    lbl_ok(['ok' => true, 'label' => $r, 'labels' => labels_list($email)]);
}

if ($action === 'delete') {
    if (!label_delete($email, (string)($_POST['id'] ?? ''))) lbl_err('That label no longer exists.', 404);
    lbl_ok(['ok' => true, 'labels' => labels_list($email)]);
}

if ($action === 'apply') {
    $label = label_get($email, (string)($_POST['id'] ?? ''));
    if (!$label) lbl_err('That label no longer exists.', 404);
    $folder = trim((string)($_POST['folder'] ?? 'INBOX'));
    if (!imap_valid_mailbox($folder)) lbl_err('Invalid folder', 400);
    $on = !empty($_POST['set']);
    $uids = [];
    if (isset($_POST['uids']) && is_array($_POST['uids'])) foreach ($_POST['uids'] as $u) { if ((int)$u > 0) $uids[] = (int)$u; }
    if (!function_exists('imap_open')) lbl_err('PHP IMAP extension is not enabled', 500);
    $mbox = imap_open_box($folder);
    if (!$mbox) lbl_err('Could not open folder "' . $folder . '"');
    $remaining = 0;
    if (!empty($_POST['all'])) {
        $all = @imap_search($mbox, 'ALL', SE_UID);
        [$uids, $remaining] = bulk_uid_window(is_array($all) ? $all : [], 5000);
    }
    $n = label_apply($mbox, array_values(array_unique($uids)), $label, $on);
    @imap_close($mbox);
    lbl_ok(['ok' => true, 'count' => $n, 'remaining' => $remaining, 'label' => $label, 'set' => $on]);
}

lbl_err('Unknown action', 400);
