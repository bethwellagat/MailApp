<?php
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
require_once __DIR__ . '/../lib/imap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['email']) || empty($_SESSION['imap_host']) || empty($_SESSION['smtp_host'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
if (!function_exists('imap_open')) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP IMAP extension is not enabled']);
    exit;
}

require_once __DIR__ . '/../lib/out_of_office.php';
require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/jobs.php';
require_once __DIR__ . '/../lib/prefs.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/util.php'; // poll_gate()
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();
@ini_set('memory_limit', '128M'); // cap this background endpoint; it never handles large attachments
session_write_close(); // release the session lock early — avoids request serialization (see fetch.php)

mb_internal_encoding('UTF-8');

function imap_ref_o() { return imap_session_ref(); } // shared: lib/imap.php
function open_box_o($folder = 'INBOX', $opts = 0) { return imap_open_box($folder, $opts); } // shared: lib/imap.php
function fail_o($msg, $code = 500) { http_response_code($code); echo json_encode(['error' => $msg]); exit; }
function ok_o($d) { echo json_encode($d); exit; }
function input_json_o() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$email  = $_SESSION['email'];

if ($action === 'get' && $method === 'GET') {
    $data = load_ooo($email);
    // Don't leak the full replied log
    $data['replied'] = (object)['count' => count((array)$data['replied'])];
    ok_o($data);
}

if ($action === 'save' && $method === 'POST') {
    $body = input_json_o();
    $cfg  = is_array($body['config'] ?? null) ? $body['config'] : [];
    $clean = [
        'enabled'       => !empty($cfg['enabled']),
        'start_date'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($cfg['start_date'] ?? '')) ? $cfg['start_date'] : '',
        'end_date'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($cfg['end_date'] ?? '')) ? $cfg['end_date'] : '',
        'subject'       => mb_substr(trim((string)($cfg['subject'] ?? '')), 0, 200),
        'body'          => sanitize_signature_html((string)($cfg['body'] ?? '')),
        'cooldown_days' => max(0, min(365, (int)($cfg['cooldown_days'] ?? 7))),
    ];

    if ($clean['enabled']) {
        if ($clean['subject'] === '' && trim(strip_tags($clean['body'])) === '') {
            fail_o('Provide a subject or message body before turning on auto-reply.', 400);
        }
        if ($clean['start_date'] && $clean['end_date'] && $clean['start_date'] > $clean['end_date']) {
            fail_o('End date must be on or after start date.', 400);
        }
    }

    // When transitioning OFF→ON, snap the high-water mark to the current top of
    // inbox so we don't auto-reply to every existing unread email. Do this network
    // round-trip BEFORE locking — a lock must never be held across the wire.
    $wasEnabled = !empty(load_ooo($email)['config']['enabled']);
    $snapUid = null;
    if ($clean['enabled'] && !$wasEnabled) {
        $mbox = open_box_o('INBOX', OP_HALFOPEN);
        if ($mbox) {
            $check = @imap_check($mbox);
            if ($check && $check->Nmsgs > 0) {
                $snapUid = (int)@imap_uid($mbox, $check->Nmsgs);
            }
            @imap_close($mbox);
        }
    }

    // Write against the freshest state, setting only what the user owns (config)
    // plus the deliberate snap. A background sweep may have advanced the watermark
    // or logged a cooldown while we were on the network above — re-reading here
    // keeps those rather than reverting them to our pre-IMAP snapshot.
    $lk   = store_lock(_ooo_file($email));
    $data = load_ooo($email);
    $data['config'] = $clean;
    if ($snapUid !== null) $data['last_processed_uid'] = $snapUid;
    $saved = save_ooo($email, $data);
    store_unlock($lk);
    if (!$saved) fail_o('Could not save', 500);
    ok_o(['ok' => true, 'config' => $clean]);
}

if ($action === 'process' && $method === 'POST') {
    // The sweep itself lives in lib/jobs.php so the scheduler can run it with
    // no browser open; this endpoint is the signed-in way to trigger it.
    $r = job_ooo_process($email);
    if (!empty($r['error'])) fail_o($r['error']);
    ok_o($r);
}

fail_o('Unknown action: ' . $action, 400);
