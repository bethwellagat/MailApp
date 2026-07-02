<?php
/**
 * Per-user outbox for delayed / scheduled sends.
 *
 * One file per queued message under data/outbox/<sha256(email)>/<uuid>.json.
 * Each file holds the fully-built MIME message + recipients + send_at. The
 * processor (ajax/outbox.php?action=process) sweeps due files and sends them
 * via lib/mailer.php's smtp_send() using the user's *current session*
 * credentials — so messages can only flush while the user is signed in and
 * polling. (cPanel shared hosting has no reliable cron from PHP.)
 */

require_once __DIR__ . '/util.php';

function _outbox_dir($email) {
    $base = __DIR__ . '/../data/outbox';
    if (!is_dir($base)) @mkdir($base, 0700, true);
    $dir = $base . '/' . hash('sha256', strtolower(trim((string)$email)));
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function outbox_uuid() { return gen_uuid(); }

function save_outbox_message($email, $rec) {
    if (!$email || !is_array($rec) || empty($rec['id'])) return false;
    $dir  = _outbox_dir($email);
    $file = $dir . '/' . $rec['id'] . '.json';
    $tmp  = $file . '.tmp';
    $json = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return @rename($tmp, $file);
}

function load_outbox_message($email, $id) {
    if (!$email || !$id) return null;
    $file = _outbox_dir($email) . '/' . basename($id) . '.json';
    if (!is_file($file)) return null;
    $raw  = @file_get_contents($file);
    if ($raw === false) return null;
    $rec  = @json_decode($raw, true);
    return is_array($rec) ? $rec : null;
}

/**
 * The message to append to the Sent folder after a queued send succeeds.
 * Prepends the Bcc header (send.php strips CR/LF from it before queueing, so
 * this cannot smuggle extra headers) so the user can later see who was bcc'd —
 * the transmitted $rec['message'] must never carry it. Records queued before
 * Bcc support lack the key entirely.
 */
function outbox_sent_copy($rec) {
    $bcc = trim((string)($rec['bcc'] ?? ''));
    return $bcc !== '' ? 'Bcc: ' . $bcc . "\r\n" . $rec['message'] : $rec['message'];
}

function list_outbox_messages($email) {
    if (!$email) return [];
    $dir = _outbox_dir($email);
    $out = [];
    foreach (glob($dir . '/*.json') as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $rec = @json_decode($raw, true);
        if (!is_array($rec) || empty($rec['id'])) continue;
        // Strip the heavy MIME body from listings — UI just needs metadata
        unset($rec['message']);
        $out[] = $rec;
    }
    usort($out, function($a, $b) { return strcmp($a['send_at'] ?? '', $b['send_at'] ?? ''); });
    return $out;
}

function delete_outbox_message($email, $id) {
    if (!$email || !$id) return false;
    $file = _outbox_dir($email) . '/' . basename($id) . '.json';
    if (!is_file($file)) return false;
    return @unlink($file);
}
