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

require_once __DIR__ . '/../lib/snooze.php';
require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();
session_write_close(); // release the session lock early — avoids request serialization (see fetch.php)

mb_internal_encoding('UTF-8');

function imap_ref_snz() {
    $ssl   = !empty($_SESSION['imap_ssl']);
    $port  = (int)($_SESSION['imap_port'] ?? 993);
    $host  = $_SESSION['imap_host'];
    $flags = $ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls';
    return '{' . $host . ':' . $port . $flags . '}';
}
function open_box_snz($folder = 'INBOX', $opts = 0) {
    // Reject c-client metacharacters / control chars so a folder value cannot
    // rewrite the {host:port} ref and redirect the IMAP connection.
    if (!is_string($folder) || $folder === '' || preg_match('/[{}\x00-\x1F\x7F]/', $folder)) return false;
    return @imap_open(imap_ref_snz() . $folder, $_SESSION['email'], $_SESSION['password'], $opts, 1);
}

function fail_snz($msg, $code = 500) {
    http_response_code($code);
    @imap_errors();
    @imap_alerts();
    echo json_encode(['error' => $msg]);
    exit;
}
function ok_snz($data) {
    @imap_errors();
    @imap_alerts();
    echo json_encode($data);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$email  = $_SESSION['email'];

function input_json_snz() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** Fetch Message-ID for each src uid. Returns map src_uid => message-id. */
function fetch_message_ids($mbox, $uids) {
    $out = [];
    foreach ($uids as $uid) {
        $headers = @imap_fetchheader($mbox, (int)$uid, FT_UID);
        if (!$headers) continue;
        if (preg_match('/^Message-ID:\s*(.+)$/im', $headers, $m)) {
            $id = trim($m[1]);
            // Strip surrounding < >
            if (preg_match('/<([^>]+)>/', $id, $m2)) $id = $m2[1];
            $out[(int)$uid] = $id;
        }
    }
    return $out;
}

/**
 * Find UIDs in a mailbox whose Message-ID equals $messageId.
 * NOTE: imap_search('HEADER Message-ID ...') is silently broken via c-client on
 * some Dovecot builds — it returns no hits even for IDs that exist (see the same
 * note in ajax/fetch.php's thread lookup). Snooze wake relied on it, so snoozed
 * mail could fail to resurface / come back already-read. Scan the folder overview
 * and match message_id client-side instead — reliable across servers.
 */
function find_uids_by_message_id($mbox, $messageId) {
    if (!$messageId) return [];
    $check = @imap_check($mbox);
    $total = $check ? (int)$check->Nmsgs : 0;
    if ($total === 0) return [];
    $MAX_SCAN = 5000;
    $start = max(1, $total - $MAX_SCAN + 1);
    $rows  = @imap_fetch_overview($mbox, $start . ':' . $total, 0);
    if (!is_array($rows)) return [];
    $want = strtolower(trim($messageId, '<> '));
    $hits = [];
    foreach ($rows as $om) {
        if (empty($om->message_id)) continue;
        if (strtolower(trim($om->message_id, '<> ')) === $want) {
            $u = (int)@imap_uid($mbox, $om->msgno);
            if ($u > 0) $hits[] = $u;
        }
    }
    return $hits;
}

/* ============================================================
   Routes
   ============================================================ */

if ($action === 'add' && $method === 'POST') {
    $body = input_json_snz();
    $uids    = isset($body['uids']) && is_array($body['uids']) ? array_values(array_filter(array_map('intval', $body['uids']))) : [];
    $from    = trim((string)($body['folder']  ?? 'INBOX'));
    $wakeAt  = trim((string)($body['wake_at'] ?? ''));
    if (!$uids || !$wakeAt) fail_snz('uids and wake_at are required', 400);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $wakeAt)) fail_snz('wake_at must be UTC ISO 8601 (e.g. 2026-05-06T09:00:00Z)', 400);

    $mbox = open_box_snz($from);
    if (!$mbox) fail_snz('Could not open source folder');
    $ref  = imap_ref_snz();

    $later = ensure_later_folder($mbox, $ref);
    if (!$later) {
        @imap_close($mbox);
        fail_snz('Could not create or find a "Later" folder', 500);
    }

    // Capture message-ids before moving
    $msgIds = fetch_message_ids($mbox, $uids);

    // Move to Later
    $set = implode(',', $uids);
    $moved = @imap_mail_move($mbox, $set, $later, CP_UID);
    @imap_expunge($mbox);
    @imap_close($mbox);
    if (!$moved) fail_snz('Failed to move messages to Later');

    // Persist snooze records under an exclusive lock so a concurrent add/cancel/
    // wake can't clobber the file and lose these records (see lib/snooze.php).
    $lock = snooze_lock($email);
    $data = load_snoozes($email);
    foreach ($uids as $uid) {
        if (!isset($msgIds[$uid])) continue;
        $data['snoozes'][] = [
            'id'              => snooze_uuid(),
            'message_id'      => $msgIds[$uid],
            'original_folder' => $from,
            'later_folder'    => $later,
            'wake_at'         => $wakeAt,
            'snoozed_at'      => gmdate('c'),
        ];
    }
    $saved = save_snoozes($email, $data);
    snooze_unlock($lock);
    if (!$saved) {
        // The messages are already in Later but we couldn't persist the wake
        // records — they'd be stranded there forever. Best-effort: move them
        // back to the original folder so the user simply sees the snooze failed.
        $back = open_box_snz($later);
        if ($back) {
            $restore = [];
            foreach ($msgIds as $mid) {
                foreach (find_uids_by_message_id($back, $mid) as $u) $restore[] = $u;
            }
            if ($restore) {
                @imap_mail_move($back, implode(',', $restore), $from, CP_UID);
                @imap_expunge($back);
            }
            @imap_close($back);
        }
        fail_snz('Could not save the snooze — no messages were moved. Please try again.', 500);
    }

    ok_snz(['ok' => true, 'count' => count($uids), 'wake_at' => $wakeAt, 'later_folder' => $later]);
}

if ($action === 'list' && $method === 'GET') {
    $data = load_snoozes($email);
    ok_snz([
        'snoozes' => array_values(array_filter($data['snoozes'], fn($s) => isset($s['wake_at']))),
    ]);
}

if ($action === 'cancel' && $method === 'POST') {
    $body = input_json_snz();
    $id = $body['id'] ?? '';
    if (!$id) fail_snz('id required', 400);
    // Locked load→modify→save so a concurrent add/wake can't lose this change.
    $lock = snooze_lock($email);
    $data = load_snoozes($email);
    $hit = null;
    $rest = [];
    foreach ($data['snoozes'] as $s) {
        if (($s['id'] ?? '') === $id) { $hit = $s; continue; }
        $rest[] = $s;
    }
    if (!$hit) { snooze_unlock($lock); fail_snz('Snooze record not found', 404); }

    // Try to move the message back early
    $mbox = open_box_snz($hit['later_folder']);
    if ($mbox) {
        $foundUids = find_uids_by_message_id($mbox, $hit['message_id']);
        if ($foundUids) {
            $set = implode(',', $foundUids);
            @imap_mail_move($mbox, $set, $hit['original_folder'], CP_UID);
            @imap_expunge($mbox);
        }
        @imap_close($mbox);
    }

    $data['snoozes'] = $rest;
    save_snoozes($email, $data);
    snooze_unlock($lock);
    ok_snz(['ok' => true]);
}

if ($action === 'wake' && $method === 'POST') {
    // Process due snoozes and move them back to their original folder.
    // A due entry is dropped from the list only when it is resolved: either
    // moved back successfully, or no longer present in the Later folder. Entries
    // whose move fails (or whose Later folder can't be opened) are RETAINED so
    // the next poll retries them, rather than stranding the message in Later.
    $count = 0;
    // Take a consistent snapshot under the lock, then release it for the slow
    // IMAP work — we must not hold the lock across multiple network round-trips
    // or an interactive add/cancel would block. We reconcile against a fresh
    // read at the end, so concurrent changes are preserved.
    $lock = snooze_lock($email);
    $data = load_snoozes($email);
    snooze_unlock($lock);
    $now  = gmdate('c');
    $byMove = []; // due now — attempt to move back
    foreach ($data['snoozes'] as $s) {
        if (empty($s['wake_at']) || $s['wake_at'] > $now) continue; // not yet due
        $byMove[] = $s;
    }
    if (empty($byMove)) { ok_snz(['ok' => true, 'count' => 0]); }

    // Group by later_folder so we open it once
    $byLater = [];
    foreach ($byMove as $s) {
        $key = $s['later_folder'];
        if (!isset($byLater[$key])) $byLater[$key] = [];
        $byLater[$key][] = $s;
    }
    $resolved = []; // record-id => true for due entries that are done (moved back,
                    // or already gone from Later). Entries whose move fails / whose
                    // Later folder won't open are simply left out → retried next poll.
    $movedBy  = []; // dest_folder => [message-id, ...] successfully moved
    foreach ($byLater as $laterFolder => $items) {
        $mbox = open_box_snz($laterFolder);
        if (!$mbox) continue; // couldn't open Later — leave these for the next poll
        foreach ($items as $s) {
            $foundUids = find_uids_by_message_id($mbox, $s['message_id']);
            if (!$foundUids) { $resolved[$s['id'] ?? ''] = true; continue; } // gone → resolved
            $set = implode(',', $foundUids);
            $moved = @imap_mail_move($mbox, $set, $s['original_folder'], CP_UID);
            if ($moved) {
                $count++;
                $resolved[$s['id'] ?? ''] = true;
                $movedBy[$s['original_folder']][] = $s['message_id'];
            }
            // move failed → not resolved; retained by the reconcile below
        }
        @imap_expunge($mbox);
        @imap_close($mbox);
    }

    // Mark successfully-moved messages unread in their destination folders
    foreach ($movedBy as $dest => $ids) {
        $mbox = open_box_snz($dest);
        if (!$mbox) continue;
        foreach ($ids as $mid) {
            $uids = find_uids_by_message_id($mbox, $mid);
            if ($uids) @imap_clearflag_full($mbox, implode(',', $uids), '\\Seen', ST_UID);
        }
        @imap_close($mbox);
    }

    // Reconcile under the lock against the LATEST list: drop only the entries we
    // resolved, keeping everything else — including records another request may
    // have added/removed while we were doing IMAP. This is what prevents the
    // lost-update that a whole-list overwrite from our stale snapshot would cause.
    if ($resolved) {
        $lock = snooze_lock($email);
        $data = load_snoozes($email);
        $data['snoozes'] = array_values(array_filter(
            $data['snoozes'],
            fn($s) => empty($s['id']) || !isset($resolved[$s['id']])
        ));
        save_snoozes($email, $data);
        snooze_unlock($lock);
    }
    ok_snz(['ok' => true, 'count' => $count]);
}

fail_snz('Unknown action: ' . $action, 400);
