<?php
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['email']) || empty($_SESSION['smtp_host'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../lib/outbox.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();
session_write_close(); // release the session lock early — avoids request serialization (see fetch.php)

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$email  = $_SESSION['email'];

function input_json_ob() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function fail_ob($msg, $code = 500) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function ok_ob($data) { echo json_encode($data); exit; }

if ($action === 'list' && $method === 'GET') {
    ok_ob(['messages' => list_outbox_messages($email)]);
}

if ($action === 'cancel' && $method === 'POST') {
    $body = input_json_ob();
    $id = $body['id'] ?? '';
    if (!$id) fail_ob('id required', 400);
    if (!delete_outbox_message($email, $id)) fail_ob('Not found', 404);
    ok_ob(['ok' => true]);
}

if ($action === 'retry' && $method === 'POST') {
    // Re-queue a failed / stuck message: clear the failure flags and make it due
    // immediately so the next process sweep sends it. Used by the Outbox folder's
    // "Retry" button. (The caller typically follows this with action=process.)
    $body = input_json_ob();
    $id = $body['id'] ?? '';
    if (!$id) fail_ob('id required', 400);
    $rec = load_outbox_message($email, $id);
    if (!$rec) fail_ob('Not found', 404);
    unset($rec['failed'], $rec['next_attempt_at'], $rec['last_error']);
    $rec['attempts'] = 0;
    $rec['send_at']  = gmdate('c'); // due now
    if (!save_outbox_message($email, $rec)) fail_ob('Could not re-queue message', 500);
    ok_ob(['ok' => true]);
}

if ($action === 'process' && $method === 'POST') {
    // Guard against concurrent sweeps double-sending the same queued message
    // (e.g. the 60s poll firing while an Undo-commit also triggers a process,
    // or two open tabs). A non-blocking exclusive lock means a second sweep
    // simply reports sent=0 rather than racing on the same files.
    $lock = @fopen(_outbox_dir($email) . '/.process.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) @fclose($lock);
        ok_ob(['ok' => true, 'sent' => 0, 'errors' => []]);
    }

    $now    = gmdate('c');
    $nowTs  = time();
    $MAX_ATTEMPTS = 8;
    $messages = list_outbox_messages($email);
    $sent = 0;
    $errors = [];
    foreach ($messages as $meta) {
        if (empty($meta['send_at']) || $meta['send_at'] > $now) continue;
        // Bounded retry: stop hammering a permanently-rejected message, and
        // honour exponential backoff between attempts. Without this a 5xx /
        // bad-credentials message retries every minute forever, silently.
        if (!empty($meta['failed'])) continue;
        if (!empty($meta['next_attempt_at']) && strtotime($meta['next_attempt_at']) > $nowTs) continue;
        $rec = load_outbox_message($email, $meta['id']);
        if (!$rec || empty($rec['rcpts']) || empty($rec['message'])) {
            delete_outbox_message($email, $meta['id']);
            continue;
        }
        $r = smtp_send($_SESSION['smtp_host'], $email, $rec['rcpts'], $rec['message'], $email, $_SESSION['password']);
        if ($r['ok']) {
            append_to_sent(outbox_sent_copy($rec));
            delete_outbox_message($email, $rec['id']);
            $sent++;
        } else {
            // Record the attempt, schedule a backoff, and give up after the cap
            // (the file stays so the user can see it failed and cancel/retry).
            $rec['attempts']   = (int)($rec['attempts'] ?? 0) + 1;
            $rec['last_error'] = $r['error'];
            if ($rec['attempts'] >= $MAX_ATTEMPTS) {
                $rec['failed'] = true;
            } else {
                $delayMin = min(60, 1 << $rec['attempts']); // 2,4,8,… capped at 60 min
                $rec['next_attempt_at'] = gmdate('c', $nowTs + $delayMin * 60);
            }
            save_outbox_message($email, $rec);
            $errors[] = ['id' => $rec['id'], 'error' => $r['error'], 'attempts' => $rec['attempts'], 'failed' => !empty($rec['failed'])];
        }
    }

    @flock($lock, LOCK_UN);
    @fclose($lock);
    ok_ob(['ok' => true, 'sent' => $sent, 'errors' => $errors]);
}

if ($action === 'send_now' && $method === 'POST') {
    // Trigger an immediate send of a queued message (used by the Undo countdown
    // when it expires — bypasses send_at for the requested ID).
    $body = input_json_ob();
    $id = $body['id'] ?? '';
    if (!$id) fail_ob('id required', 400);
    // Hold the same lock the sweep uses so a concurrent process() can't also
    // send this message (the Undo-expiry double-send window). The lock is
    // released automatically when this request ends.
    $lock = @fopen(_outbox_dir($email) . '/.process.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $rec = load_outbox_message($email, $id);
    if (!$rec) fail_ob('Not found', 404);
    if (empty($rec['rcpts']) || empty($rec['message'])) {
        delete_outbox_message($email, $id);
        fail_ob('Queued message is malformed', 500);
    }
    $r = smtp_send($_SESSION['smtp_host'], $email, $rec['rcpts'], $rec['message'], $email, $_SESSION['password']);
    if (!$r['ok']) fail_ob('Send failed: ' . $r['error'], 500);
    append_to_sent(outbox_sent_copy($rec));
    delete_outbox_message($email, $id);
    ok_ob(['ok' => true]);
}

fail_ob('Unknown action: ' . $action, 400);
