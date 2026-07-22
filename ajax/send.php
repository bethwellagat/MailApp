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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

require_once __DIR__ . '/../lib/mailer.php';
require_once __DIR__ . '/../lib/outbox.php';
require_once __DIR__ . '/../lib/contacts.php';
require_once __DIR__ . '/../lib/csrf.php';
csrf_require();
session_write_close(); // release the session lock early — a slow SMTP send must not block the user's other requests (see fetch.php)

mb_internal_encoding('UTF-8');

// If the whole multipart request blew past post_max_size, PHP has ALREADY dropped
// $_POST and $_FILES — the body arrives empty and every check below would look
// like a missing field ("Recipient required"). Detect that here and return a
// precise error. CSRF was still validated above via the X-CSRF-Token header,
// which survives the truncation, so this is safe to report before touching state.
$postMax = ini_bytes(ini_get('post_max_size'));
$clen    = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($postMax > 0 && $clen > $postMax && empty($_POST) && empty($_FILES)) {
    http_response_code(413);
    echo json_encode(['error' =>
        'These attachments are too large for this server (about ' . round($clen / 1048576, 1) .
        ' MB total; this server accepts at most ' . ini_get('post_max_size') .
        ' per message). Attach fewer or smaller files, or ask your host to raise post_max_size.']);
    exit;
}

/** Human-readable message for a PHP $_FILES upload error code. */
function upload_err_message($code, $name, $size) {
    $name = basename((string)$name);
    $umax = ini_get('upload_max_filesize');
    $pmax = ini_get('post_max_size');
    $sz   = $size > 0 ? (round($size / 1048576, 1) . ' MB') : 'that file';
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:   // 1
            return '"' . $name . '" (' . $sz . ') is larger than this server\'s per-file upload limit of ' . $umax .
                   '. Attach a smaller file, or ask your host to raise upload_max_filesize.';
        case UPLOAD_ERR_FORM_SIZE:  // 2
            return '"' . $name . '" is too large to upload.';
        case UPLOAD_ERR_PARTIAL:    // 3
            return '"' . $name . '" was only partially uploaded — please try again.';
        case UPLOAD_ERR_NO_TMP_DIR: // 6
            return 'The server has no temporary folder for uploads (upload_tmp_dir). Ask your host to configure it.';
        case UPLOAD_ERR_CANT_WRITE: // 7
            return 'The server could not write "' . $name . '" to disk. Ask your host to check the upload temp folder.';
        case UPLOAD_ERR_EXTENSION:  // 8
            return '"' . $name . '" was blocked by a server-side upload extension.';
        default:
            return 'Upload error for "' . $name . '" (code ' . (int)$code . '). It may exceed this server\'s limits (' .
                   $umax . ' per file, ' . $pmax . ' per message).';
    }
}

$to          = trim($_POST['to']          ?? '');
$cc          = trim($_POST['cc']          ?? '');
$bcc         = trim($_POST['bcc']         ?? '');
$subject     = trim($_POST['subject']     ?? '');
$body        = (string)($_POST['body']    ?? '');
$inReplyTo   = trim((string)($_POST['in_reply_to'] ?? ''));
$references  = trim((string)($_POST['references']  ?? ''));

// Header-injection defense: recipients and subject must never carry CR/LF, or a
// crafted value could smuggle extra SMTP headers (e.g. a silent Bcc) into the
// outgoing message and the saved Sent copy. A pure-ASCII subject can otherwise
// reach the 'Subject:' line unencoded, so strip newlines from it too. (The
// threading headers below get the same treatment.)
$to      = preg_replace('/[\r\n]+/', ' ', $to);
$cc      = preg_replace('/[\r\n]+/', ' ', $cc);
$bcc     = preg_replace('/[\r\n]+/', ' ', $bcc);
$subject = preg_replace('/[\r\n]+/', ' ', $subject);

// Normalise: strip CR/LF, strip stray whitespace, keep angle brackets.
$inReplyTo  = preg_replace('/[\r\n]+/', ' ', $inReplyTo);
$references = preg_replace('/[\r\n]+/', ' ', $references);
if ($inReplyTo !== '' && $inReplyTo[0] !== '<') {
    $inReplyTo = '<' . trim($inReplyTo, '<> ') . '>';
}
// References is a space-separated list of <id> tokens; preserve as given but tidy.
if ($references !== '') {
    $references = trim(preg_replace('/\s+/', ' ', $references));
}

if ($to === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Recipient (To) is required.']);
    exit;
}
if ($subject === '' && trim(strip_tags($body)) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Cannot send an empty message.']);
    exit;
}

$rcpts = [];
foreach ([$to, $cc, $bcc] as $field) {
    if ($field === '') continue;
    foreach (preg_split('/[,;]/', $field) as $piece) {
        $piece = trim($piece);
        if ($piece === '') continue;
        if (preg_match('/<([^>]+)>/', $piece, $m) && filter_var($m[1], FILTER_VALIDATE_EMAIL)) {
            $rcpts[] = $m[1];
        } elseif (filter_var($piece, FILTER_VALIDATE_EMAIL)) {
            $rcpts[] = $piece;
        }
    }
}
$rcpts = array_values(array_unique($rcpts));
if (empty($rcpts)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid recipient email addresses found.']);
    exit;
}

// Passively harvest recipients into the user's address book for compose
// autocomplete. Runs for both queued and immediate sends; best-effort only.
$harvest = [];
foreach ([$to, $cc, $bcc] as $field) {
    foreach (contacts_parse_list($field) as $entry) $harvest[] = $entry;
}
if (!empty($harvest)) contacts_record($_SESSION['email'], $harvest);

$from_addr   = $_SESSION['email'];
$from_name   = $_SESSION['display_name'] ?? '';
$from_header = $from_name
    ? mime_header_encode($from_name) . ' <' . $from_addr . '>'
    : $from_addr;

$plain = html_to_text($body);

// Validate and read attachments from $_FILES.
$attachments = [];
$totalAttachBytes = 0;
$ATTACH_MAX_TOTAL = 26214400; // 25 MB
$ATTACH_MAX_FILE  = 26214400;
if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
    $count = count($_FILES['attachments']['name']);
    for ($i = 0; $i < $count; $i++) {
        $err = $_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $name = (string)($_FILES['attachments']['name'][$i] ?? '');
        if ($err !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => upload_err_message($err, $name, $_FILES['attachments']['size'][$i] ?? 0)]);
            exit;
        }
        $tmp = $_FILES['attachments']['tmp_name'][$i] ?? '';
        if (!is_uploaded_file($tmp)) continue;
        $size = (int)($_FILES['attachments']['size'][$i] ?? 0);
        if ($size > $ATTACH_MAX_FILE) {
            http_response_code(400);
            echo json_encode(['error' => '"' . basename($name) . '" exceeds the 25 MB per-file limit.']);
            exit;
        }
        $totalAttachBytes += $size;
        if ($totalAttachBytes > $ATTACH_MAX_TOTAL) {
            http_response_code(400);
            echo json_encode(['error' => 'Total attachments exceed 25 MB.']);
            exit;
        }
        $data = @file_get_contents($tmp);
        if ($data === false) continue;
        $type = (string)($_FILES['attachments']['type'][$i] ?? 'application/octet-stream');
        if ($type === '' || $type === 'application/x-php') $type = 'application/octet-stream';
        $attachments[] = [
            'name' => basename($name),
            'type' => $type,
            'data' => $data,
        ];
    }
}

$altBoundary   = 'alt_' . bin2hex(random_bytes(8));
$mixedBoundary = 'mix_' . bin2hex(random_bytes(8));

// Inner alternative part: text/plain + text/html
$altPart  = "--$altBoundary\r\n";
$altPart .= "Content-Type: text/plain; charset=UTF-8\r\n";
$altPart .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
$altPart .= quoted_printable_encode($plain) . "\r\n\r\n";
$altPart .= "--$altBoundary\r\n";
$altPart .= "Content-Type: text/html; charset=UTF-8\r\n";
$altPart .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
$altPart .= quoted_printable_encode(wrap_html($body)) . "\r\n\r\n";
$altPart .= "--$altBoundary--\r\n";

if (empty($attachments)) {
    // No attachments: top-level Content-Type is multipart/alternative.
    $body_mime    = $altPart;
    $topBoundary  = $altBoundary;
    $topMime      = 'multipart/alternative';
} else {
    // Has attachments: wrap the alternative as the first part of multipart/mixed,
    // then append each attachment as its own part.
    $body_mime  = "--$mixedBoundary\r\n";
    $body_mime .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";
    $body_mime .= $altPart . "\r\n";
    foreach ($attachments as $a) {
        $encName = mime_header_encode($a['name']);
        $body_mime .= "--$mixedBoundary\r\n";
        $body_mime .= "Content-Type: " . $a['type'] . "; name=\"" . $encName . "\"\r\n";
        $body_mime .= "Content-Disposition: attachment; filename=\"" . $encName . "\"\r\n";
        $body_mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body_mime .= chunk_split(base64_encode($a['data']), 76, "\r\n") . "\r\n";
    }
    $body_mime .= "--$mixedBoundary--\r\n";
    $topBoundary = $mixedBoundary;
    $topMime     = 'multipart/mixed';
}

$message_id = '<' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from_addr, '@'), 1) . '>';

$base_headers = [
    'From: ' . $from_header,
    'MIME-Version: 1.0',
    'Date: ' . date('r'),
    'Message-ID: ' . $message_id,
    'Content-Type: ' . $topMime . '; boundary="' . $topBoundary . '"',
];
if ($cc !== '') $base_headers[] = 'Cc: ' . $cc;

if ($inReplyTo !== '') {
    $base_headers[] = 'In-Reply-To: ' . $inReplyTo;
    // Build the proper References chain: existing chain + parent's Message-ID.
    $refs = $references;
    if ($refs === '' || strpos($refs, $inReplyTo) === false) {
        $refs = $refs === '' ? $inReplyTo : ($refs . ' ' . $inReplyTo);
    }
    $base_headers[] = 'References: ' . $refs;
} elseif ($references !== '') {
    // Forward-style: References without In-Reply-To (we don't currently emit these,
    // but allow it for clients that pass References through).
    $base_headers[] = 'References: ' . $references;
}

$smtp_headers = array_merge(
    ['To: ' . $to, 'Subject: ' . mime_header_encode($subject)],
    $base_headers
);
$full_message = implode("\r\n", $smtp_headers) . "\r\n\r\n" . $body_mime;

$smtp_host = $_SESSION['smtp_host'];

// Queue mode: if `queue_send_at` (UTC ISO 8601) is provided, save the built
// MIME to the user's outbox and return immediately — the outbox processor
// performs the one actual SMTP send when the time arrives. This drives both
// Undo Send (a 10s delay the user can cancel before it flushes) and Schedule
// Send (a future time). We must NOT also send here: doing so delivered the
// message twice and made Undo unable to recall anything.
$queueSendAt = trim((string)($_POST['queue_send_at'] ?? ''));
if ($queueSendAt !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $queueSendAt)) {
        http_response_code(400);
        echo json_encode(['error' => 'queue_send_at must be UTC ISO 8601 (e.g. 2026-05-06T09:00:00Z)']);
        exit;
    }
    $rec = [
        'id'           => outbox_uuid(),
        'send_at'      => $queueSendAt,
        'queued_at'    => gmdate('c'),
        'to'           => $to,
        'cc'           => $cc,
        'bcc'          => $bcc,
        'subject'      => $subject,
        'rcpts'        => $rcpts,
        'message'      => $full_message,
    ];
    if (!save_outbox_message($_SESSION['email'], $rec)) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not queue message']);
        exit;
    }
    echo json_encode(['ok' => true, 'queued' => true, 'id' => $rec['id'], 'send_at' => $rec['send_at']]);
    exit;
}

// Immediate send (no queue_send_at): send now over SMTP, falling back to PHP
// mail() if the SMTP attempt fails.
$result = smtp_send($smtp_host, $from_addr, $rcpts, $full_message, $_SESSION['email'], $_SESSION['password']);

if (!$result['ok']) {
    $mailHeaders = $base_headers;
    if ($bcc !== '') $mailHeaders[] = 'Bcc: ' . $bcc;
    // Some shared hosts put mail() in disable_functions; calling it then is a
    // fatal (not a catchable warning), so guard it and fall through to the clean
    // "Send failed" JSON error instead of a 500 with a stack trace.
    $sent = function_exists('mail')
        ? @mail($to, mime_header_encode($subject), $body_mime, implode("\r\n", $mailHeaders))
        : false;
    if (!$sent) {
        http_response_code(500);
        echo json_encode(['error' => 'Send failed: ' . $result['error']]);
        exit;
    }
}

// The Sent copy carries the Bcc header so the user can later see who was
// bcc'd; $full_message (what actually went over SMTP) must never include it.
// $bcc is CRLF-stripped above, so this cannot smuggle extra headers.
$saved = append_to_sent($bcc !== '' ? 'Bcc: ' . $bcc . "\r\n" . $full_message : $full_message);

echo json_encode(['ok' => true, 'saved_to_sent' => $saved]);
exit;
