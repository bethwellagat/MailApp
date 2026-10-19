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

require_once __DIR__ . '/../lib/snooze.php';
require_once __DIR__ . '/../lib/jobs.php';
require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();
@ini_set('memory_limit', '128M'); // cap this background endpoint; it never handles large attachments
session_write_close(); // release the session lock early — avoids request serialization (see fetch.php)

mb_internal_encoding('UTF-8');

function imap_ref_snz() { return imap_session_ref(); } // shared: lib/imap.php
function open_box_snz($folder = 'INBOX', $opts = 0) { return imap_open_box($folder, $opts); } // shared: lib/imap.php

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

    // Capture message-ids before moving.
    //
    // A wake record is keyed ONLY by Message-ID — that is the sole handle the wake
    // sweep has for finding the message again in Later (find_uids_by_message_id).
    // So a message whose Message-ID we can't read must NOT be moved: it would land
    // in the hidden Later folder with no wake record and could never be brought
    // back — silently lost from the visible mailbox. Move exactly the set we can
    // track, and tell the caller about any we had to leave behind.
    $msgIds    = fetch_message_ids($mbox, $uids);
    $trackable = array_keys($msgIds);
    $skipped   = count($uids) - count($trackable);

    if (!$trackable) {
        @imap_close($mbox);
        fail_snz(count($uids) === 1
            ? 'This message has no Message-ID, so it can’t be scheduled to come back. It was left where it is.'
            : 'These messages have no Message-ID, so they can’t be scheduled to come back. They were left where they are.',
            422);
    }

    // Move to Later (only the trackable ones)
    $set = implode(',', $trackable);
    $moved = @imap_mail_move($mbox, $set, $later, CP_UID);
    expunge_only($mbox, $trackable);
    @imap_close($mbox);
    if (!$moved) fail_snz('Failed to move messages to Later');

    // Persist snooze records under an exclusive lock so a concurrent add/cancel/
    // wake can't clobber the file and lose these records (see lib/snooze.php).
    $lock = snooze_lock($email);
    $data = load_snoozes($email);
    // Iterate the SAME set we moved, so the moved set and the recorded set can
    // never diverge (that divergence is exactly what stranded messages before).
    foreach ($trackable as $uid) {
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
                foreach ((find_uids_by_message_id($back, $mid) ?: []) as $u) $restore[] = $u;
            }
            if ($restore) {
                @imap_mail_move($back, implode(',', $restore), $from, CP_UID);
                expunge_only($back, $restore);
            }
            @imap_close($back);
        }
        fail_snz('Could not save the snooze — no messages were moved. Please try again.', 500);
    }

    ok_snz([
        'ok'           => true,
        'count'        => count($trackable), // what was actually snoozed, not what was asked for
        'skipped'      => $skipped,          // left in place (no Message-ID to track them by)
        'wake_at'      => $wakeAt,
        'later_folder' => $later,
    ]);
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
            expunge_only($mbox, $foundUids);
        }
        @imap_close($mbox);
    }

    $data['snoozes'] = $rest;
    save_snoozes($email, $data);
    snooze_unlock($lock);
    ok_snz(['ok' => true]);
}

if ($action === 'wake' && $method === 'POST') {
    // The sweep itself lives in lib/jobs.php so the scheduler can run it with
    // no browser open; this endpoint is the signed-in way to trigger it.
    $r = job_snooze_wake($email);
    if (!empty($r['error'])) fail_snz($r['error']);
    ok_snz($r);
}

fail_snz('Unknown action: ' . $action, 400);
