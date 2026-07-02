<?php
/**
 * Draft autosave — save the in-progress compose to the user's IMAP Drafts folder
 * (so it survives a closed tab / expired session and syncs to their phone). Kept
 * fully self-contained so it never touches the send path:
 *   action=save   → build a draft MIME, imap_append to Drafts, replace the prior
 *                   autosave, return the new uid.
 *   action=delete → drop a draft uid (on discard, or after a successful send).
 */
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php'; accounts_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['email']) || empty($_SESSION['imap_host'])) {
    http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit;
}
if (!function_exists('imap_open')) {
    http_response_code(500); echo json_encode(['error' => 'PHP IMAP extension is not enabled']); exit;
}
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/mailer.php'; // mime_header_encode, html_to_text, wrap_html
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POST required']); exit; }
csrf_require();
session_write_close();

function _d_ref() {
    $ssl  = !empty($_SESSION['imap_ssl']);
    $port = (int)($_SESSION['imap_port'] ?? 993);
    return '{' . $_SESSION['imap_host'] . ':' . $port . ($ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls') . '}';
}
function _d_valid($name) { return is_string($name) && $name !== '' && !preg_match('/[{}\x00-\x1F\x7F]/', $name); }
function _d_open($folder = 'INBOX', $opts = 0) {
    if (!_d_valid($folder)) return false;
    return @imap_open(_d_ref() . $folder, $_SESSION['email'], $_SESSION['password'], $opts, 1);
}
function _d_find_drafts($mbox) {
    $list = @imap_list($mbox, _d_ref(), '*');
    if ($list) {
        foreach ($list as $raw) {
            $u = mb_convert_encoding(str_replace(_d_ref(), '', $raw), 'UTF-8', 'UTF7-IMAP');
            if (stripos($u, 'draft') !== false) return $u;
        }
    }
    $imapName = mb_convert_encoding('Drafts', 'UTF7-IMAP', 'UTF-8');
    if (@imap_createmailbox($mbox, _d_ref() . $imapName)) { @imap_subscribe($mbox, _d_ref() . $imapName); return 'Drafts'; }
    return false;
}
function _d_fail($m, $c = 500) { http_response_code($c); @imap_errors(); @imap_alerts(); echo json_encode(['error' => $m]); exit; }
function _d_ok($d) { @imap_errors(); @imap_alerts(); echo json_encode($d); exit; }

$action = $_POST['action'] ?? '';

if ($action === 'save') {
    // CRLF-strip recipients/subject (header-injection defence, as in send.php).
    $strip   = fn($s) => preg_replace('/[\r\n]+/', ' ', trim((string)$s));
    $to      = $strip($_POST['to']      ?? '');
    $cc      = $strip($_POST['cc']      ?? '');
    $bcc     = $strip($_POST['bcc']     ?? '');
    $subject = $strip($_POST['subject'] ?? '');
    $body    = (string)($_POST['body']  ?? '');
    $inReplyTo  = preg_replace('/[\r\n]+/', '', trim((string)($_POST['in_reply_to'] ?? '')));
    $references = $strip($_POST['references'] ?? '');
    $prevUid = (int)($_POST['prev_uid'] ?? 0);
    $prevFolder = (string)($_POST['prev_folder'] ?? '');

    // Nothing worth saving.
    if ($to === '' && $cc === '' && $bcc === '' && $subject === '' && trim(strip_tags($body)) === '') {
        _d_ok(['ok' => true, 'empty' => true]);
    }

    $probe = _d_open('INBOX', OP_HALFOPEN);
    if (!$probe) _d_fail('Could not connect to mail server');
    $drafts = _d_find_drafts($probe);
    @imap_close($probe);
    if (!$drafts) _d_fail('Could not find or create a Drafts folder');

    $box = _d_open($drafts);
    if (!$box) _d_fail('Could not open Drafts');

    $from_addr   = $_SESSION['email'];
    $from_name   = $_SESSION['display_name'] ?? '';
    $from_header = $from_name ? mime_header_encode($from_name) . ' <' . $from_addr . '>' : $from_addr;
    $plain = html_to_text($body);
    $alt   = 'alt_' . bin2hex(random_bytes(8));
    $mime  = "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($plain) . "\r\n\r\n";
    $mime .= "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode(wrap_html($body)) . "\r\n\r\n--$alt--\r\n";

    $mid = '<draft-' . bin2hex(random_bytes(10)) . '@' . substr(strrchr($from_addr, '@'), 1) . '>';
    $headers = ['From: ' . $from_header, 'To: ' . $to];
    if ($cc  !== '') $headers[] = 'Cc: '  . $cc;
    if ($bcc !== '') $headers[] = 'Bcc: ' . $bcc;
    $headers[] = 'Subject: ' . mime_header_encode($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'Message-ID: ' . $mid;
    if ($inReplyTo  !== '') $headers[] = 'In-Reply-To: ' . $inReplyTo;
    if ($references !== '') $headers[] = 'References: ' . $references;
    $headers[] = 'X-Mailer-Draft: 1';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $mime;

    if (!@imap_append($box, _d_ref() . $drafts, $message, "\\Draft \\Seen")) { @imap_close($box); _d_fail('Could not save draft'); }

    // imap_append doesn't return the new uid — locate it by our Message-ID.
    $newUid = 0;
    $check = @imap_check($box); $total = $check ? (int)$check->Nmsgs : 0;
    if ($total > 0) {
        $start = max(1, $total - 50 + 1);
        $rows  = @imap_fetch_overview($box, "$start:$total", 0);
        if (is_array($rows)) {
            foreach ($rows as $om) {
                if (!empty($om->message_id) && trim($om->message_id, '<> ') === trim($mid, '<> ')) { $newUid = (int)@imap_uid($box, $om->msgno); break; }
            }
        }
    }

    // Replace the previous autosave of this compose (same Drafts folder).
    if ($prevUid > 0 && (!$prevFolder || strcasecmp($prevFolder, $drafts) === 0)) {
        @imap_delete($box, (string)$prevUid, FT_UID);
        @imap_expunge($box);
    }
    @imap_close($box);
    _d_ok(['ok' => true, 'uid' => $newUid, 'folder' => $drafts]);
}

if ($action === 'delete') {
    $uid    = (int)($_POST['uid'] ?? 0);
    $folder = (string)($_POST['folder'] ?? '');
    if (!$uid || !_d_valid($folder)) _d_fail('Missing parameters', 400);
    $box = _d_open($folder);
    if (!$box) _d_fail('Could not open folder');
    @imap_delete($box, (string)$uid, FT_UID);
    @imap_expunge($box);
    @imap_close($box);
    _d_ok(['ok' => true]);
}

_d_fail('Unknown action', 400);
