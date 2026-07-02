<?php
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
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
require_once __DIR__ . '/../lib/prefs.php';
require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();
session_write_close(); // release the session lock early — avoids request serialization (see fetch.php)

mb_internal_encoding('UTF-8');

function imap_ref_o() {
    $ssl = !empty($_SESSION['imap_ssl']);
    $port = (int)($_SESSION['imap_port'] ?? 993);
    $host = $_SESSION['imap_host'];
    $flags = $ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls';
    return '{' . $host . ':' . $port . $flags . '}';
}
function open_box_o($folder = 'INBOX', $opts = 0) {
    // Reject c-client metacharacters / control chars so a folder value cannot
    // rewrite the {host:port} ref and redirect the IMAP connection.
    if (!is_string($folder) || $folder === '' || preg_match('/[{}\x00-\x1F\x7F]/', $folder)) return false;
    return @imap_open(imap_ref_o() . $folder, $_SESSION['email'], $_SESSION['password'], $opts, 1);
}
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

    $data = load_ooo($email);
    $wasEnabled = !empty($data['config']['enabled']);
    $data['config'] = $clean;
    // When transitioning OFF→ON, snap the high-water mark to the current
    // top of inbox so we don't auto-reply to every existing unread email.
    if ($clean['enabled'] && !$wasEnabled) {
        $mbox = open_box_o('INBOX', OP_HALFOPEN);
        if ($mbox) {
            $check = @imap_check($mbox);
            if ($check && $check->Nmsgs > 0) {
                $data['last_processed_uid'] = (int)@imap_uid($mbox, $check->Nmsgs);
            }
            @imap_close($mbox);
        }
    }
    if (!save_ooo($email, $data)) fail_o('Could not save', 500);
    ok_o(['ok' => true, 'config' => $clean]);
}

if ($action === 'process' && $method === 'POST') {
    $data = load_ooo($email);
    $cfg  = $data['config'];
    if (!ooo_is_active($cfg)) ok_o(['ok' => true, 'replied' => 0, 'inactive' => true]);

    // Serialize concurrent sweeps: without this, two polls can read the same
    // last_processed_uid and cooldown log and both fire auto-replies to the
    // same sender. A non-blocking lock lets the second sweep bow out cleanly.
    $lock = @fopen(_ooo_file($email) . '.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) @fclose($lock);
        ok_o(['ok' => true, 'replied' => 0, 'busy' => true]);
    }
    // Re-read under the lock so we act on the freshest persisted state (another
    // sweep may have just advanced last_processed_uid / replied before we won).
    $data = load_ooo($email);
    $cfg  = $data['config'];

    $mbox = open_box_o('INBOX');
    if (!$mbox) { @flock($lock, LOCK_UN); @fclose($lock); fail_o('Could not open Inbox'); }

    $check   = @imap_check($mbox);
    $highUid = $check && $check->Nmsgs > 0 ? (int)@imap_uid($mbox, $check->Nmsgs) : 0;
    $minUid  = (int)$data['last_processed_uid'];
    if ($minUid <= 0 && $highUid > 0) {
        $data['last_processed_uid'] = $highUid;
        save_ooo($email, $data);
        @imap_close($mbox);
        @flock($lock, LOCK_UN);
        @fclose($lock);
        ok_o(['ok' => true, 'replied' => 0, 'first_run' => true]);
    }

    // Find UIDs > minUid
    $candidateUids = [];
    if ($highUid > $minUid) {
        $found = @imap_search($mbox, 'UID ' . ($minUid + 1) . ':*', SE_UID);
        if (is_array($found)) $candidateUids = array_map('intval', $found);
    }

    $repliedLog = (array)$data['replied'];
    $cooldownMs = max(0, (int)$cfg['cooldown_days']) * 86400 * 1000;
    $nowMs      = time() * 1000;
    $myAddr     = strtolower($email);
    $sentCount  = 0;
    $errors     = [];
    $failedFloor = PHP_INT_MAX; // lowest UID whose reply failed — don't advance past it

    foreach ($candidateUids as $uid) {
        if ($uid <= $minUid) continue;
        $headersRaw = @imap_fetchheader($mbox, $uid, FT_UID);
        if (!$headersRaw) continue;

        // Parse From / Reply-To / Subject / Message-ID
        $info = @imap_headerinfo($mbox, @imap_msgno($mbox, $uid));
        if (!$info) continue;

        $fromAddr = '';
        if (!empty($info->reply_to[0])) {
            $r = $info->reply_to[0];
            $fromAddr = ($r->mailbox ?? '') . '@' . ($r->host ?? '');
        }
        if ((!$fromAddr || $fromAddr === '@') && !empty($info->from[0])) {
            $f = $info->from[0];
            $fromAddr = ($f->mailbox ?? '') . '@' . ($f->host ?? '');
        }
        $fromAddr = strtolower(trim($fromAddr, ' <>'));
        if (!$fromAddr || $fromAddr === '@') continue;
        if ($fromAddr === $myAddr) continue;

        if (ooo_should_skip($headersRaw, $fromAddr)) continue;

        // Cooldown: was this sender already auto-replied to recently?
        if (isset($repliedLog[$fromAddr])) {
            $lastTs = strtotime($repliedLog[$fromAddr]);
            if ($lastTs && ($nowMs - $lastTs * 1000) < $cooldownMs) continue;
        }

        $origSubject = '';
        if (preg_match('/^Subject:\s*(.+)$/im', $headersRaw, $m)) {
            $decoded = @imap_mime_header_decode($m[1]);
            if (is_array($decoded)) {
                foreach ($decoded as $p) $origSubject .= $p->text;
            } else {
                $origSubject = $m[1];
            }
            $origSubject = trim(preg_replace('/\s+/', ' ', $origSubject));
        }
        $origMsgId = '';
        if (preg_match('/^Message-ID:\s*(<[^>\r\n]+>)/im', $headersRaw, $m)) $origMsgId = trim($m[1]);

        $reply = ooo_build_reply_mime(
            $email,
            $_SESSION['display_name'] ?? '',
            $fromAddr,
            $origSubject,
            $cfg['body'],
            $origMsgId
        );

        $r = smtp_send($_SESSION['smtp_host'], $email, [$fromAddr], $reply['mime'], $email, $_SESSION['password']);
        if ($r['ok']) {
            // Persist the cooldown record immediately — if the sweep is killed
            // mid-loop (max_execution_time / dropped connection) we must not lose
            // the fact that this sender was already answered, or they'd get a
            // duplicate auto-reply on the next run.
            $repliedLog[$fromAddr] = gmdate('c');
            $data['replied'] = $repliedLog;
            save_ooo($email, $data);
            $sentCount++;
        } else {
            $errors[] = ['to' => $fromAddr, 'error' => $r['error']];
            $failedFloor = min($failedFloor, $uid); // retry this sender next cycle
        }
    }

    // Advance the high-water mark, but never past a UID whose reply failed, so a
    // failed sender is retried. The persisted cooldown log prevents the
    // re-scanned successes in that range from being answered twice.
    $finalUid = ($failedFloor === PHP_INT_MAX) ? $highUid : min($highUid, $failedFloor - 1);
    $data['replied'] = $repliedLog;
    if ($finalUid > 0) $data['last_processed_uid'] = max((int)$data['last_processed_uid'], $finalUid);
    save_ooo($email, $data);
    @imap_close($mbox);
    @flock($lock, LOCK_UN);
    @fclose($lock);
    ok_o(['ok' => true, 'replied' => $sentCount, 'errors' => $errors]);
}

fail_o('Unknown action: ' . $action, 400);
