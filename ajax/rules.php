<?php
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
require_once __DIR__ . '/../lib/imap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['email']) || empty($_SESSION['imap_host'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
if (!function_exists('imap_open')) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP IMAP extension is not enabled']);
    exit;
}

require_once __DIR__ . '/../lib/rules.php';
require_once __DIR__ . '/../lib/jobs.php';
require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();
@ini_set('memory_limit', '128M'); // cap this background endpoint; it never handles large attachments
session_write_close(); // release the session lock early — avoids request serialization (see fetch.php)

mb_internal_encoding('UTF-8');

function imap_ref_r() { return imap_session_ref(); } // shared: lib/imap.php
function open_box_r($folder = 'INBOX', $opts = 0) { return imap_open_box($folder, $opts); } // shared: lib/imap.php
function fail_r($msg, $code = 500) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function ok_r($data) { echo json_encode($data); exit; }
function input_json_r() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$email  = $_SESSION['email'];



/* ============================================================ */

if ($action === 'list' && $method === 'GET') {
    $data = load_rules($email);
    ok_r(['rules' => array_values($data['rules'])]);
}

if ($action === 'add' && $method === 'POST') {
    $body = input_json_r();
    $clean = sanitize_rule($body);
    if (!$clean) fail_r('A rule needs at least one match criterion and one action.', 400);
    if ($qe = rule_query_errors($clean['match']['query'])) fail_r('Search query: ' . implode('; ', $qe), 400);
    $clean['id'] = rule_uuid();
    // Serialize the load→modify→save so a concurrent save (another tab, or the
    // background sweep) can't lose this rule. Released before the slow IMAP work
    // below so "apply to existing" never holds it across the network.
    $lk   = store_lock(_rules_file($email));
    $data = load_rules($email);
    $data['rules'][] = $clean;
    $saved = save_rules($email, $data);
    store_unlock($lk);
    if (!$saved) fail_r('Could not save rule', 500);

    // If "apply to existing" is requested, run this rule on Inbox now.
    $appliedCount = 0;
    if (!empty($body['apply_existing'])) {
        $mbox = open_box_r('INBOX');
        if ($mbox) {
            $uids = rule_find_matches($mbox, $clean, 0, 500);
            if ($uids) {
                $res = apply_rule_actions($mbox, 'INBOX', $uids, $clean['actions']);
                $appliedCount = $res['matched'] ?? 0;
            }
            @imap_close($mbox);
        }
    }
    ok_r(['rule' => $clean, 'applied' => $appliedCount]);
}

if ($action === 'update' && $method === 'POST') {
    $body = input_json_r();
    $id = $body['id'] ?? '';
    if (!$id) fail_r('id required', 400);
    $clean = sanitize_rule($body);
    if (!$clean) fail_r('A rule needs at least one match criterion and one action.', 400);
    if ($qe = rule_query_errors($clean['match']['query'])) fail_r('Search query: ' . implode('; ', $qe), 400);
    $clean['id'] = $id;
    $lk   = store_lock(_rules_file($email));
    $data = load_rules($email);
    $found = false;
    foreach ($data['rules'] as $i => $r) {
        if (($r['id'] ?? '') === $id) {
            $clean['created_at'] = $r['created_at'] ?? $clean['created_at'];
            $data['rules'][$i] = $clean;
            $found = true;
            break;
        }
    }
    if (!$found) { store_unlock($lk); fail_r('Rule not found', 404); }
    $saved = save_rules($email, $data);
    store_unlock($lk);
    if (!$saved) fail_r('Could not save', 500);
    ok_r(['rule' => $clean]);
}

if ($action === 'toggle' && $method === 'POST') {
    $body = input_json_r();
    $id = $body['id'] ?? '';
    $enabled = !empty($body['enabled']);
    if (!$id) fail_r('id required', 400);
    $lk   = store_lock(_rules_file($email));
    $data = load_rules($email);
    $found = false;
    foreach ($data['rules'] as $i => $r) {
        if (($r['id'] ?? '') === $id) {
            $data['rules'][$i]['enabled'] = $enabled;
            $found = true; break;
        }
    }
    if (!$found) { store_unlock($lk); fail_r('Rule not found', 404); }
    $saved = save_rules($email, $data);
    store_unlock($lk);
    if (!$saved) fail_r('Could not save', 500);
    ok_r(['ok' => true]);
}

if ($action === 'delete' && $method === 'POST') {
    $body = input_json_r();
    $id = $body['id'] ?? '';
    if (!$id) fail_r('id required', 400);
    $lk   = store_lock(_rules_file($email));
    $data = load_rules($email);
    $before = count($data['rules']);
    $data['rules'] = array_values(array_filter($data['rules'], fn($r) => ($r['id'] ?? '') !== $id));
    if (count($data['rules']) === $before) { store_unlock($lk); fail_r('Rule not found', 404); }
    $saved = save_rules($email, $data);
    store_unlock($lk);
    if (!$saved) fail_r('Could not save', 500);
    ok_r(['ok' => true]);
}

if ($action === 'preview' && $method === 'POST') {
    // Return up to 10 sample matches for the supplied criteria, as a UX hint
    // before saving the rule. Does not modify state.
    $body = input_json_r();
    $clean = sanitize_rule(['match' => $body['match'] ?? [], 'actions' => ['mark_read' => true]]);
    if (!$clean) {
        // Sanitize requires both a match and an action; ours has the dummy mark_read.
        // If sanitize still rejected, the criteria are empty.
        ok_r(['matches' => [], 'count' => 0]);
    }
    $mbox = open_box_r('INBOX');
    if (!$mbox) fail_r('Could not open Inbox');
    $uids = rule_find_matches($mbox, $clean, 0, 200);
    $samples = [];
    foreach (array_slice(array_reverse($uids), 0, 10) as $uid) {
        $h = @imap_headerinfo($mbox, @imap_msgno($mbox, $uid));
        if (!$h) continue;
        $from = $h->from[0] ?? null;
        $samples[] = [
            'uid'     => $uid,
            'subject' => @imap_utf8($h->subject ?? ''),
            'from'    => $from ? trim(@imap_utf8(($from->personal ?? '') . ' <' . ($from->mailbox ?? '') . '@' . ($from->host ?? '') . '>')) : '',
            'date'    => $h->date ?? '',
        ];
    }
    @imap_close($mbox);
    ok_r(['matches' => $samples, 'count' => count($uids)]);
}

if ($action === 'run_new' && $method === 'POST') {
    // The sweep itself lives in lib/jobs.php so the scheduler can run it with
    // no browser open; this endpoint is the signed-in way to trigger it.
    $r = job_rules_run_new($email);
    if (!empty($r['error'])) fail_r($r['error']);
    ok_r($r);
}

if ($action === 'run_one' && $method === 'POST') {
    // Apply a specific rule to existing inbox.
    $body = input_json_r();
    $id = $body['id'] ?? '';
    if (!$id) fail_r('id required', 400);
    $data = load_rules($email);
    $rule = null;
    foreach ($data['rules'] as $r) {
        if (($r['id'] ?? '') === $id) { $rule = $r; break; }
    }
    if (!$rule) fail_r('Rule not found', 404);
    $mbox = open_box_r('INBOX');
    if (!$mbox) fail_r('Could not open Inbox');
    $uids = rule_find_matches($mbox, $rule, 0, 500);
    $res = ['matched' => 0];
    if ($uids) $res = apply_rule_actions($mbox, 'INBOX', $uids, $rule['actions']);
    @imap_close($mbox);
    ok_r(['ok' => true, 'count' => $res['matched']]);
}

fail_r('Unknown action: ' . $action, 400);
