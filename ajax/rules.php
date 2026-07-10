<?php
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
if (!function_exists('imap_open')) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP IMAP extension is not enabled']);
    exit;
}

require_once __DIR__ . '/../lib/rules.php';
require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();
@ini_set('memory_limit', '128M'); // cap this background endpoint; it never handles large attachments
session_write_close(); // release the session lock early — avoids request serialization (see fetch.php)

mb_internal_encoding('UTF-8');

function imap_ref_r() {
    $ssl   = !empty($_SESSION['imap_ssl']);
    $port  = (int)($_SESSION['imap_port'] ?? 993);
    $host  = $_SESSION['imap_host'];
    $flags = $ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls';
    return '{' . $host . ':' . $port . $flags . '}';
}
function open_box_r($folder = 'INBOX', $opts = 0) {
    return @imap_open(imap_ref_r() . $folder, $_SESSION['email'], $_SESSION['password'], $opts, 1);
}
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

/**
 * Find UIDs in $folder that match $rule's criteria. Optional $minUid restricts
 * to UID > $minUid. has_attachment is post-filtered against bodystructure.
 * Returns array of UIDs (ints).
 */
function rule_find_matches($mbox, $rule, $minUid = 0, $cap = 1000) {
    $criteria = rule_to_imap_search($rule['match']);
    $found = @imap_search($mbox, $criteria, SE_UID);
    if (!is_array($found)) return [];
    $found = array_map('intval', $found);
    if ($minUid > 0) $found = array_values(array_filter($found, fn($u) => $u > $minUid));

    if ($rule['match']['has_attachment']) {
        $filtered = [];
        $i = 0;
        foreach ($found as $uid) {
            if (count($filtered) >= $cap) break;
            $struct = @imap_fetchstructure($mbox, $uid, FT_UID);
            if ($struct && rule_structure_has_attachment($struct)) {
                $filtered[] = $uid;
            }
            if (++$i > 5000) break; // safety
        }
        $found = $filtered;
    }

    if (count($found) > $cap) $found = array_slice($found, -$cap);
    return $found;
}

function apply_rule_actions($mbox, $folder, $uids, $actions) {
    if (empty($uids)) return ['matched' => 0];
    $set = implode(',', $uids);

    if (!empty($actions['mark_read'])) {
        @imap_setflag_full($mbox, $set, '\\Seen', ST_UID);
    }
    if (!empty($actions['star'])) {
        @imap_setflag_full($mbox, $set, '\\Flagged', ST_UID);
    }
    if (!empty($actions['delete'])) {
        // Move to Trash (locate any folder containing 'trash')
        $trash = null;
        $boxes = @imap_list($mbox, imap_ref_r(), '*');
        if ($boxes) {
            foreach ($boxes as $raw) {
                $name = mb_convert_encoding(str_replace(imap_ref_r(), '', $raw), 'UTF-8', 'UTF7-IMAP');
                if (stripos($name, 'trash') !== false || stripos($name, 'deleted') !== false) {
                    $trash = $name; break;
                }
            }
        }
        if ($trash) {
            @imap_mail_move($mbox, $set, $trash, CP_UID);
            @imap_expunge($mbox);
        }
        return ['matched' => count($uids), 'deleted' => true];
    }
    if (!empty($actions['move_to']) && !preg_match('/[{}\x00-\x1F\x7F]/', $actions['move_to'])) {
        @imap_mail_move($mbox, $set, $actions['move_to'], CP_UID);
        @imap_expunge($mbox);
        return ['matched' => count($uids), 'moved_to' => $actions['move_to']];
    }
    if (!empty($actions['skip_inbox'])) {
        // "Archive" — move to any folder containing "archive"
        $archive = null;
        $boxes = @imap_list($mbox, imap_ref_r(), '*');
        if ($boxes) {
            foreach ($boxes as $raw) {
                $name = mb_convert_encoding(str_replace(imap_ref_r(), '', $raw), 'UTF-8', 'UTF7-IMAP');
                if (stripos($name, 'archive') !== false) { $archive = $name; break; }
            }
        }
        if ($archive) {
            @imap_mail_move($mbox, $set, $archive, CP_UID);
            @imap_expunge($mbox);
            return ['matched' => count($uids), 'archived' => true];
        }
    }
    return ['matched' => count($uids)];
}

/* ============================================================ */

if ($action === 'list' && $method === 'GET') {
    $data = load_rules($email);
    ok_r(['rules' => array_values($data['rules'])]);
}

if ($action === 'add' && $method === 'POST') {
    $body = input_json_r();
    $clean = sanitize_rule($body);
    if (!$clean) fail_r('A rule needs at least one match criterion and one action.', 400);
    $clean['id'] = rule_uuid();
    $data = load_rules($email);
    $data['rules'][] = $clean;
    if (!save_rules($email, $data)) fail_r('Could not save rule', 500);

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
    $clean['id'] = $id;
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
    if (!$found) fail_r('Rule not found', 404);
    if (!save_rules($email, $data)) fail_r('Could not save', 500);
    ok_r(['rule' => $clean]);
}

if ($action === 'toggle' && $method === 'POST') {
    $body = input_json_r();
    $id = $body['id'] ?? '';
    $enabled = !empty($body['enabled']);
    if (!$id) fail_r('id required', 400);
    $data = load_rules($email);
    $found = false;
    foreach ($data['rules'] as $i => $r) {
        if (($r['id'] ?? '') === $id) {
            $data['rules'][$i]['enabled'] = $enabled;
            $found = true; break;
        }
    }
    if (!$found) fail_r('Rule not found', 404);
    if (!save_rules($email, $data)) fail_r('Could not save', 500);
    ok_r(['ok' => true]);
}

if ($action === 'delete' && $method === 'POST') {
    $body = input_json_r();
    $id = $body['id'] ?? '';
    if (!$id) fail_r('id required', 400);
    $data = load_rules($email);
    $before = count($data['rules']);
    $data['rules'] = array_values(array_filter($data['rules'], fn($r) => ($r['id'] ?? '') !== $id));
    if (count($data['rules']) === $before) fail_r('Rule not found', 404);
    if (!save_rules($email, $data)) fail_r('Could not save', 500);
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
    // Apply enabled rules to messages with UID > last_processed_uid in INBOX.
    $data = load_rules($email);
    if (empty($data['rules'])) ok_r(['ok' => true, 'count' => 0]);
    // Cross-tab throttle: open INBOX and sweep at most once every ~2 min total,
    // not once per tab per poll (the in-app cron). See poll_gate().
    if (!poll_gate($email, 'rules', 120)) ok_r(['ok' => true, 'count' => 0, 'throttled' => true]);

    // Serialize concurrent sweeps (two polls / two tabs): without this, both can
    // read the same last_processed_uid and apply every rule to the same new
    // messages twice. A non-blocking lock lets the loser bow out cleanly.
    $lock = @fopen(_rules_file($email) . '.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) @fclose($lock);
        ok_r(['ok' => true, 'count' => 0, 'busy' => true]);
    }
    // Re-read under the lock so we act on the freshest persisted watermark.
    $data = load_rules($email);

    $mbox = open_box_r('INBOX');
    if (!$mbox) { @flock($lock, LOCK_UN); @fclose($lock); fail_r('Could not open Inbox'); }

    $check     = @imap_check($mbox);
    $highUid   = 0;
    if ($check && $check->Nmsgs > 0) {
        $highUid = (int)@imap_uid($mbox, $check->Nmsgs);
    }

    $minUid = (int)$data['last_processed_uid'];
    if ($minUid <= 0 && $highUid > 0) {
        // First-ever run on this account: don't sweep the whole inbox; just
        // mark current high-water and bail. Rules will start firing from the
        // next message that arrives.
        $data['last_processed_uid'] = $highUid;
        save_rules($email, $data);
        @imap_close($mbox);
        ok_r(['ok' => true, 'count' => 0, 'first_run' => true]);
    }

    $totalApplied = 0;
    foreach ($data['rules'] as $rule) {
        if (empty($rule['enabled'])) continue;
        $uids = rule_find_matches($mbox, $rule, $minUid, 500);
        if (!$uids) continue;
        $res = apply_rule_actions($mbox, 'INBOX', $uids, $rule['actions']);
        $totalApplied += $res['matched'] ?? 0;

        // If the rule moved/deleted messages, the inbox UID set has changed.
        // Re-checking imap_check after each rule keeps highUid correct.
        $check = @imap_check($mbox);
        if ($check && $check->Nmsgs > 0) {
            $highUid = max($highUid, (int)@imap_uid($mbox, $check->Nmsgs));
        }
    }

    $data['last_processed_uid'] = $highUid;
    save_rules($email, $data);
    @imap_close($mbox);
    ok_r(['ok' => true, 'count' => $totalApplied]);
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
