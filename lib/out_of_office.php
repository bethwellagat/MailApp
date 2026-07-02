<?php
/**
 * Per-user vacation responder ("Out of Office").
 *
 * Storage: data/out_of_office/<sha256(email)>.json
 *   {
 *     "config": {
 *       "enabled":        false,
 *       "start_date":     "2026-05-05",   // YYYY-MM-DD (inclusive, server local)
 *       "end_date":       "2026-05-12",
 *       "subject":        "I'm out of the office",
 *       "body":           "<p>...HTML...</p>",
 *       "cooldown_days":  7
 *     },
 *     "last_processed_uid": 0,
 *     "replied": { "sender@example.com": "2026-05-05T10:00:00Z" }
 *   }
 */

function _ooo_dir() {
    $d = __DIR__ . '/../data/out_of_office';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
function _ooo_file($email) {
    return _ooo_dir() . '/' . hash('sha256', strtolower(trim($email))) . '.json';
}

function default_ooo() {
    return [
        'config' => [
            'enabled'       => false,
            'start_date'    => '',
            'end_date'      => '',
            'subject'       => "I'm out of the office",
            'body'          => "<p>Thanks for your email. I'm currently away and will respond when I'm back.</p>",
            'cooldown_days' => 7,
        ],
        'last_processed_uid' => 0,
        'replied' => (object)[],
    ];
}

function load_ooo($email) {
    $defaults = default_ooo();
    if (!$email) return $defaults;
    $file = _ooo_file($email);
    if (!is_file($file)) return $defaults;
    $raw = @file_get_contents($file);
    if ($raw === false) return $defaults;
    $data = @json_decode($raw, true);
    if (!is_array($data)) return $defaults;
    $data['config'] = array_merge($defaults['config'], $data['config'] ?? []);
    if (!isset($data['last_processed_uid'])) $data['last_processed_uid'] = 0;
    if (!isset($data['replied']) || !is_array($data['replied'])) $data['replied'] = [];
    return $data;
}

function save_ooo($email, $data) {
    if (!$email || !is_array($data)) return false;
    $file = _ooo_file($email);
    $tmp  = $file . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return @rename($tmp, $file);
}

function ooo_is_active($cfg, $now = null) {
    if (empty($cfg['enabled'])) return false;
    $today = $now ?? date('Y-m-d');
    $start = $cfg['start_date'] ?? '';
    $end   = $cfg['end_date']   ?? '';
    if ($start !== '' && $today < $start) return false;
    if ($end   !== '' && $today > $end)   return false;
    return true;
}

/**
 * Heuristic: is this incoming message from an automated sender we should NOT
 * reply to? Skip mailing lists, no-reply, mailer-daemon, DSN, anything with
 * Auto-Submitted/Precedence: bulk/list/junk headers.
 */
function ooo_should_skip($headersRaw, $fromAddr) {
    if (!$headersRaw) return true;
    $h = strtolower($headersRaw);
    if (strpos($h, "\nlist-id:")             !== false) return true;
    if (strpos($h, "\nlist-unsubscribe:")    !== false) return true;
    if (strpos($h, "\nauto-submitted:")      !== false && strpos($h, 'auto-submitted: no') === false) return true;
    if (strpos($h, "\nx-auto-response-suppress:") !== false) return true;
    if (preg_match('/^precedence:\s*(bulk|list|junk)/im', $headersRaw)) return true;
    if (!$fromAddr) return true;
    $low = strtolower($fromAddr);
    foreach (['noreply', 'no-reply', 'mailer-daemon', 'postmaster', 'do-not-reply', 'donotreply', 'bounce', 'notifications@'] as $needle) {
        if (strpos($low, $needle) !== false) return true;
    }
    return false;
}

function ooo_build_reply_mime($fromAddr, $fromName, $toAddr, $origSubject, $bodyHtml, $inReplyTo) {
    require_once __DIR__ . '/mailer.php';
    $altBoundary = 'alt_' . bin2hex(random_bytes(8));
    $plain = html_to_text($bodyHtml);

    // Header-injection defense: a crafted incoming Message-ID or From address could
    // carry CR/LF to smuggle extra headers (or a body) into this auto-reply. Strip
    // them before either value reaches a header line (the capture regex in
    // ajax/out_of_office.php is also tightened to exclude CR/LF).
    $inReplyTo = preg_replace('/[\r\n]+/', '', (string)$inReplyTo);
    $toAddr    = preg_replace('/[\r\n]+/', '', (string)$toAddr);
    $fromAddr  = preg_replace('/[\r\n]+/', '', (string)$fromAddr);

    $subject = trim($origSubject) === ''
        ? 'Auto-reply'
        : 'Re: ' . preg_replace('/^(re:|fwd?:|aw:)\s*/i', '', trim($origSubject));

    $alt  = "--$altBoundary\r\n";
    $alt .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $alt .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $alt .= quoted_printable_encode($plain) . "\r\n\r\n";
    $alt .= "--$altBoundary\r\n";
    $alt .= "Content-Type: text/html; charset=UTF-8\r\n";
    $alt .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $alt .= quoted_printable_encode(wrap_html($bodyHtml)) . "\r\n\r\n";
    $alt .= "--$altBoundary--\r\n";

    $messageId  = '<' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($fromAddr, '@'), 1) . '>';
    $fromHeader = $fromName ? mime_header_encode($fromName) . ' <' . $fromAddr . '>' : $fromAddr;

    $headers = [
        'From: ' . $fromHeader,
        'To: '   . $toAddr,
        'Subject: ' . mime_header_encode($subject),
        'MIME-Version: 1.0',
        'Date: ' . date('r'),
        'Message-ID: ' . $messageId,
        'Auto-Submitted: auto-replied',
        'X-Auto-Response-Suppress: All',  // signal to other auto-responders not to bounce-loop
        'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"',
    ];
    if ($inReplyTo) {
        $headers[] = 'In-Reply-To: ' . $inReplyTo;
        $headers[] = 'References: '  . $inReplyTo;
    }

    return [
        'subject' => $subject,
        'mime'    => implode("\r\n", $headers) . "\r\n\r\n" . $alt,
    ];
}
