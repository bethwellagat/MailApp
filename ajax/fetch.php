<?php
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
$action = $_GET['action'] ?? '';
if ($action === 'attachment') {
    header('Cache-Control: no-store');
} else {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
}

if (empty($_SESSION['email']) || empty($_SESSION['imap_host'])) {
    http_response_code(401);
    if ($action === 'attachment') { header('Content-Type: text/plain'); echo 'Not authenticated'; }
    else echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if (!function_exists('imap_open')) {
    http_response_code(500);
    if ($action === 'attachment') { header('Content-Type: text/plain'); echo 'PHP IMAP extension is not enabled'; }
    else echo json_encode(['error' => 'PHP IMAP extension is not enabled']);
    exit;
}

require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

// Release the session lock now. PHP's file session handler holds an EXCLUSIVE
// lock for the whole request, so without this every AJAX call in a session
// serializes — a slow read (e.g. attach_flags probing structures, or a big
// thread fetch) would block the message list, polling, and clicks behind it,
// freezing the UI. We've already read the credentials we need (accounts_boot
// mirrored them into $_SESSION in memory, which stays readable after close);
// nothing below this point writes the session. Endpoints that DO mutate the
// session (login, account add/switch) must not do this.
session_write_close();

mb_internal_encoding('UTF-8');

/* ---------- IMAP helpers ---------- */

function imap_ref() {
    $ssl   = !empty($_SESSION['imap_ssl']);
    $port  = (int)($_SESSION['imap_port'] ?? 993);
    $host  = $_SESSION['imap_host'];
    $flags = $ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls';
    return '{' . $host . ':' . $port . $flags . '}';
}

function valid_mailbox_name($name) {
    // A folder/mailbox name must not carry c-client connection metacharacters
    // ('{' '}') or control characters: a '}' could rewrite the {host:port}
    // portion of the connection ref and redirect the IMAP session to an
    // attacker-chosen server, and control bytes could smuggle protocol data.
    // Legitimate folder names (letters, digits, spaces, the hierarchy
    // delimiter) never contain these.
    return is_string($name) && $name !== '' && !preg_match('/[{}\x00-\x1F\x7F]/', $name);
}

function open_box($folder = 'INBOX', $opts = 0) {
    if (!valid_mailbox_name($folder)) return false;
    return @imap_open(imap_ref() . $folder, $_SESSION['email'], $_SESSION['password'], $opts, 1);
}

function fail($msg, $code = 500) {
    http_response_code($code);
    @imap_errors();
    @imap_alerts();
    echo json_encode(['error' => $msg]);
    exit;
}

function ok($data) {
    @imap_errors();
    @imap_alerts();
    echo json_encode($data);
    exit;
}

function decode_header($raw) {
    if (!$raw) return '';
    $parts = @imap_mime_header_decode($raw);
    if (!$parts) return $raw;
    $out = '';
    foreach ($parts as $p) {
        $charset = ($p->charset === 'default' || !$p->charset) ? 'UTF-8' : $p->charset;
        $text = $p->text;
        if (strtoupper($charset) !== 'UTF-8') {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
            if ($converted !== false) $text = $converted;
        }
        $out .= $text;
    }
    return $out;
}

function parse_addr($raw) {
    if (!$raw) return ['name' => '', 'email' => ''];
    $list = @imap_rfc822_parse_adrlist($raw, 'localhost');
    if (!$list) return ['name' => '', 'email' => $raw];
    $first = $list[0];
    $email = '';
    if (!empty($first->mailbox) && !empty($first->host) && $first->host !== 'localhost') {
        $email = $first->mailbox . '@' . $first->host;
    }
    $name = isset($first->personal) ? decode_header($first->personal) : '';
    if (!$name) $name = $email ?: $raw;
    return ['name' => $name, 'email' => $email];
}

/* ---------- Sender-trust / phishing heuristics ---------- */

/** First occurrence of a header from a raw header block, with folding unwrapped. */
function _hdr_value($rawHeaders, $name) {
    if (!$rawHeaders) return '';
    $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $rawHeaders);
    if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.*)$/im', $unfolded, $m)) {
        return trim($m[1]);
    }
    return '';
}

/** Registrable ("organization") domain, with basic multi-label ccTLD handling. */
function _registrable_domain($d) {
    $d = strtolower(trim((string)$d, '. '));
    if ($d === '') return '';
    $p = explode('.', $d);
    $n = count($p);
    if ($n < 2) return $d;
    $tld = $p[$n - 1];
    $sld = $p[$n - 2];
    if (strlen($tld) === 2 && $n >= 3 &&
        in_array($sld, ['co', 'ac', 'or', 'go', 'ne', 'com', 'org', 'gov', 'net', 'edu'], true)) {
        return $p[$n - 3] . '.' . $sld . '.' . $tld;
    }
    return $sld . '.' . $tld;
}

/** True when two hostnames share an organization domain or one is a subdomain of the other. */
function _domain_related($a, $b) {
    $a = strtolower(trim((string)$a, '. '));
    $b = strtolower(trim((string)$b, '. '));
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    if (_registrable_domain($a) === _registrable_domain($b)) return true;
    if (substr($a, -strlen('.' . $b)) === '.' . $b) return true;
    if (substr($b, -strlen('.' . $a)) === '.' . $a) return true;
    return false;
}

/** True when $from looks like a deceptive near-copy of $self (typosquat / different TLD). */
function _domain_lookalike($from, $self) {
    $rf = _registrable_domain($from);
    $rs = _registrable_domain($self);
    if ($rf === '' || $rs === '' || $rf === $rs) return false;
    $baseF = explode('.', $rf)[0];
    $baseS = explode('.', $rs)[0];
    if (strlen($baseS) < 4) return false;          // own brand too short to judge safely
    if ($baseF === $baseS) return true;            // same brand, different TLD (acme.com vs acme.co)
    $dist = levenshtein($baseF, $baseS);
    return $dist > 0 && $dist <= 2 && abs(strlen($baseF) - strlen($baseS)) <= 2;
}

/**
 * Conservative phishing assessment for one message.
 * Returns ['level' => 'none'|'warn'|'danger', 'reasons' => string[]].
 * Designed to avoid false alarms: the user's own mail and same-domain mail
 * are never flagged, and weak signals only "warn".
 */
function assess_sender_trust($rawHeaders, $fromName, $fromAddr, $selfEmail) {
    $reasons = [];
    $level   = 'none';

    $fromAddr = strtolower(trim((string)$fromAddr));
    $fromDom  = strpos($fromAddr, '@') !== false ? substr(strrchr($fromAddr, '@'), 1) : '';
    $self     = strtolower(trim((string)$selfEmail));
    $selfDom  = strpos($self, '@') !== false ? substr(strrchr($self, '@'), 1) : '';

    if ($fromAddr !== '' && $fromAddr === $self) return ['level' => 'none', 'reasons' => []];

    // Impersonation-of-self checks only make sense for an organization domain.
    // When the logged-in account is itself a shared freemail domain, "from your
    // own domain" carries no meaning, so suppress those checks to avoid noise.
    $freemail = ['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com',
                 'yahoo.com', 'ymail.com', 'icloud.com', 'me.com', 'aol.com', 'proton.me', 'protonmail.com'];
    $selfIsOrg = $selfDom !== '' && !in_array(_registrable_domain($selfDom), $freemail, true);

    $danger = function ($r) use (&$reasons, &$level) { $reasons[] = $r; $level = 'danger'; };
    $warn   = function ($r) use (&$reasons, &$level) { $reasons[] = $r; if ($level !== 'danger') $level = 'warn'; };

    // 1) Display name embeds an email address from a different domain than the real sender.
    if (preg_match('/[\w.+\-]+@([\w.\-]+\.[a-z]{2,})/i', (string)$fromName, $m)) {
        $nameDom = strtolower($m[1]);
        if ($fromDom !== '' && !_domain_related($nameDom, $fromDom)) {
            $danger('Sender name shows "' . $m[0] . '" but the message was sent from ' . $fromAddr . '.');
        }
    }

    // 2) Display name carries your organization's name yet comes from outside your domain.
    if ($selfIsOrg && $fromDom !== '' && !_domain_related($fromDom, $selfDom)) {
        $selfBase  = explode('.', _registrable_domain($selfDom))[0];
        $nameAlnum = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$fromName));
        if (strlen($selfBase) >= 4 && strpos($nameAlnum, $selfBase) !== false) {
            $danger('This message uses your organization name but was sent from an outside address (' . $fromAddr . ').');
        }
    }

    // 3) Sender domain is a deceptive look-alike of your own domain.
    if ($selfIsOrg && $fromDom !== '' && _domain_lookalike($fromDom, $selfDom)) {
        $danger('Sender domain "' . $fromDom . '" closely resembles your own domain "' . $selfDom . '".');
    }

    // 4) Authentication results, when the receiving server recorded them.
    $ar   = strtolower(_hdr_value($rawHeaders, 'Authentication-Results'));
    $rspf = strtolower(_hdr_value($rawHeaders, 'Received-SPF'));
    if (strpos($ar, 'dmarc=fail') !== false) {
        $danger('The message failed DMARC authentication and may be forged.');
    } elseif (strpos($ar, 'spf=fail') !== false || preg_match('/^\s*fail/', $rspf)) {
        $warn('The message failed SPF (sender-address) authentication.');
    } elseif (strpos($ar, 'dkim=fail') !== false) {
        $warn('The message failed its DKIM signature check.');
    }

    // 5) Replies would silently be redirected to a different domain.
    $replyTo = _hdr_value($rawHeaders, 'Reply-To');
    if ($replyTo !== '' && $fromDom !== '' && preg_match('/@([\w.\-]+\.[a-z]{2,})/i', $replyTo, $rm)) {
        $rDom = strtolower($rm[1]);
        if (!_domain_related($rDom, $fromDom)) {
            $warn('Replies to this message would go to ' . $rDom . ', not ' . $fromDom . '.');
        }
    }

    if (count($reasons) > 3) $reasons = array_slice($reasons, 0, 3);
    return ['level' => $level, 'reasons' => $reasons];
}

/** Parse List-Unsubscribe into ['http'=>?url, 'mailto'=>?addr] or null. */
function parse_list_unsubscribe($rawHeaders) {
    $val = _hdr_value($rawHeaders, 'List-Unsubscribe');
    if ($val === '') return null;
    $http = null; $mailto = null;
    if (preg_match_all('/<([^>]+)>/', $val, $mm)) {
        foreach ($mm[1] as $link) {
            $link = trim($link);
            if ($mailto === null && stripos($link, 'mailto:') === 0)      $mailto = $link;
            elseif ($http === null && preg_match('#^https?://#i', $link))  $http   = $link;
        }
    }
    if ($http === null && $mailto === null) return null;
    return ['http' => $http, 'mailto' => $mailto];
}

function decode_part_body($mbox, $uid, $section, $encoding, $charset) {
    $section = $section === '' ? '1' : $section;
    $data = @imap_fetchbody($mbox, $uid, $section, FT_UID);
    if ($data === false) return '';
    if ($encoding === 3) {
        $data = base64_decode($data);
    } elseif ($encoding === 4) {
        $data = quoted_printable_decode($data);
    }
    if ($charset && strtoupper($charset) !== 'UTF-8') {
        $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
        if ($converted !== false) $data = $converted;
    } else {
        if (!mb_check_encoding($data, 'UTF-8')) {
            // No declared charset and the bytes aren't UTF-8. 'auto' detection
            // guesses wrong on Western text and corrupts it to '?'. Windows-1252
            // (a superset of Latin-1) is the overwhelmingly common case and is
            // lossless across that byte range, so fall back to it.
            $converted = @mb_convert_encoding($data, 'UTF-8', 'Windows-1252');
            if ($converted !== false) $data = $converted;
        }
    }
    return $data;
}

function part_charset($part) {
    if (!empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) === 'charset') return $p->value;
        }
    }
    if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $p) {
            if (strtolower($p->attribute) === 'charset') return $p->value;
        }
    }
    return '';
}

function find_part($structure, $type, $subtype, $section = '') {
    if (empty($structure->parts)) return null;
    foreach ($structure->parts as $i => $part) {
        $sec = $section === '' ? (string)($i + 1) : $section . '.' . ($i + 1);
        $partType = ['TEXT','MULTIPART','MESSAGE','APPLICATION','AUDIO','IMAGE','VIDEO','OTHER'];
        $tName = $partType[$part->type] ?? 'OTHER';
        if ($tName === $type && strtoupper($part->subtype) === strtoupper($subtype)) {
            return ['part' => $part, 'section' => $sec];
        }
        if (!empty($part->parts)) {
            $found = find_part($part, $type, $subtype, $sec);
            if ($found) return $found;
        }
    }
    return null;
}

/* ---------- Attachment helpers ---------- */

function part_filename($part) {
    if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $p) {
            if (strtolower($p->attribute) === 'filename') return decode_header($p->value);
        }
    }
    if (!empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) === 'name') return decode_header($p->value);
        }
    }
    return '';
}

function part_mime_type($part) {
    static $types = ['text','multipart','message','application','audio','image','video','other'];
    $t = $types[$part->type] ?? 'application';
    return $t . '/' . strtolower($part->subtype ?? 'octet-stream');
}

/**
 * Walk the structure tree, return any leaf parts that have a filename — these
 * are the user-visible attachments. Captures both DISPOSITION:attachment and
 * inline parts (some clients mark images as inline but still attach them).
 */
/**
 * Cheap "does this message have a real attachment?" probe.
 * Walks the structure tree once, returns true on the first part with a
 * filename that's not a pure inline-cid resource (so signature logos
 * embedded inline don't count).
 */
function structure_has_attachment($structure) {
    if (empty($structure->parts)) {
        $name = part_filename($structure);
        if ($name === '') return false;
        $disp = isset($structure->disposition) ? strtolower($structure->disposition) : '';
        $cid  = isset($structure->id) ? trim($structure->id, '<>') : '';
        return !($disp === 'inline' && $cid !== '');
    }
    foreach ($structure->parts as $p) {
        $name = part_filename($p);
        if ($name !== '') {
            $disp = isset($p->disposition) ? strtolower($p->disposition) : '';
            $cid  = isset($p->id) ? trim($p->id, '<>') : '';
            if (!($disp === 'inline' && $cid !== '')) return true;
        }
        if (!empty($p->parts) && structure_has_attachment($p)) return true;
    }
    return false;
}

/** True when an attachment list has any non-inline ("real") attachment. Mirrors
 *  the frontend's visible-attachment filter — inline cid images don't count. */
function has_visible_attachments($atts) {
    foreach ((array)$atts as $a) {
        if (!empty($a['inline']) && !empty($a['content_id'])) continue;
        return true;
    }
    return false;
}

function enumerate_attachments($structure, $section = '') {
    $out = [];
    if (empty($structure->parts)) {
        $name = part_filename($structure);
        if ($name !== '') {
            $sec = $section === '' ? '1' : $section;
            $out[] = attachment_meta($structure, $sec, $name);
        }
        return $out;
    }
    foreach ($structure->parts as $i => $part) {
        $sec = $section === '' ? (string)($i + 1) : $section . '.' . ($i + 1);
        $name = part_filename($part);
        if ($name !== '') {
            $out[] = attachment_meta($part, $sec, $name);
        }
        if (!empty($part->parts)) {
            $out = array_merge($out, enumerate_attachments($part, $sec));
        }
    }
    return $out;
}

function attachment_meta($part, $section, $name) {
    $disp = isset($part->disposition) ? strtolower($part->disposition) : '';
    return [
        'name'       => $name,
        'type'       => part_mime_type($part),
        'size'       => isset($part->bytes) ? (int)$part->bytes : 0,
        'section'    => $section,
        'inline'     => $disp === 'inline',
        'content_id' => isset($part->id) ? trim($part->id, '<>') : '',
    ];
}

/**
 * Inline images in HTML mail come through as <img src="cid:abc123">.
 * Rewrite them to point at our attachment endpoint (with preview=1, which
 * sends Content-Disposition: inline) so browsers can actually load them.
 */
function rewrite_cid_images($html, $attachments, $folder, $uid, $acct = '') {
    if ($html === '' || empty($attachments)) return $html;
    $cidMap = [];
    foreach ($attachments as $a) {
        $cid = isset($a['content_id']) ? trim((string)$a['content_id'], '<> ') : '';
        if ($cid !== '') $cidMap[strtolower($cid)] = $a['section'];
    }
    if (empty($cidMap)) return $html;

    $folderEnc = rawurlencode($folder);
    $uidStr    = (int)$uid;
    // Pin the inline-image URLs to the account this body belongs to, so they
    // still resolve when the user is viewing the unified inbox (where the
    // persisted active account may differ from this message's account).
    $acctQS = ($acct !== '' && $acct !== null) ? '&acct=' . rawurlencode($acct) : '';
    return preg_replace_callback(
        '/(<img\b[^>]*\bsrc\s*=\s*["\'])cid:([^"\'>\s]+)(["\'])/i',
        function ($m) use ($cidMap, $folderEnc, $uidStr, $acctQS) {
            $cid = strtolower(trim($m[2], '<> '));
            if (!isset($cidMap[$cid])) return $m[0];
            $url = 'ajax/fetch.php?action=attachment'
                 . '&folder=' . $folderEnc
                 . '&uid=' . $uidStr
                 . '&section=' . rawurlencode($cidMap[$cid])
                 . '&preview=1'
                 . $acctQS;
            return $m[1] . $url . $m[3];
        },
        $html
    );
}

function locate_part($structure, $section) {
    $parts = explode('.', $section);
    $current = $structure;
    foreach ($parts as $p) {
        $idx = (int)$p - 1;
        if ($idx < 0) return null;
        if (empty($current->parts) || !isset($current->parts[$idx])) {
            // Single-part message — only "1" maps to the root
            if ($section === '1' && $current === $structure) return $structure;
            return null;
        }
        $current = $current->parts[$idx];
    }
    return $current;
}

/**
 * If the message carries a calendar invitation (a text/calendar part), parse it
 * for iTip handling and return a compact descriptor the client renders RSVP
 * controls for. Returns null when there's no calendar part or it isn't an
 * actionable meeting REQUEST / CANCEL / PUBLISH.
 */
function detect_invite($mbox, $uid, $structure) {
    $cal = find_part($structure, 'TEXT', 'CALENDAR');
    if (!$cal) return null;
    require_once __DIR__ . '/../lib/ics_parser.php';

    $part = $cal['part'];
    $raw  = decode_part_body($mbox, $uid, $cal['section'], $part->encoding ?? 0, part_charset($part));
    if ($raw === '') return null;

    $itip   = ics_parse_itip($raw);
    if (empty($itip['events'])) return null;
    $method = $itip['method'];
    // Some senders put the method only on the MIME Content-Type (method=REQUEST).
    if ($method === '' && !empty($part->parameters)) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) === 'method') { $method = strtoupper($p->value); break; }
        }
    }
    // Only surface actionable meeting objects; REPLY/COUNTER are organizer-side.
    if ($method === '') {
        $method = 'REQUEST'; // event present, no method given — treat as a request
    } elseif (!in_array($method, ['REQUEST', 'CANCEL', 'PUBLISH'], true)) {
        return null;
    }

    $ev = $itip['events'][0];
    $me = strtolower(trim($_SESSION['email'] ?? ''));
    $myPartstat = '';
    foreach ($ev['attendees'] as $a) {
        if ($a['email'] === $me) { $myPartstat = $a['partstat']; break; }
    }

    return [
        'method'         => $method,
        'uid'            => $ev['uid'],
        'sequence'       => $ev['sequence'],
        'summary'        => $ev['summary'],
        'description'    => mb_substr((string)$ev['description'], 0, 2000),
        'location'       => $ev['location'],
        'start'          => $ev['start'],
        'end'            => $ev['end'],
        'all_day'        => $ev['all_day'],
        'status'         => $ev['status'],
        'organizer'      => ['email' => $ev['organizer']['email'], 'name' => $ev['organizer']['name']],
        'attendee_count' => count($ev['attendees']),
        'my_partstat'    => $myPartstat,
        'section'        => $cal['section'],
        'recurring'      => !empty($ev['rrule']),
    ];
}

/**
 * Find where the "previous message" / quoted content starts in a rendered HTML
 * body. Returns the byte offset, or false if no quote boundary is detected.
 *
 * Detection heuristics, ordered by reliability:
 *  - <blockquote> tag (Apple Mail, Gmail, this app's own quoted block)
 *  - <hr> immediately preceding "From: … Sent: … Subject:" (Outlook reply quote)
 *  - <div id="appendonsend" / id="divRplyFwdMsg" (Outlook on the web)
 *  - <div class="gmail_quote">
 *  - "On <date>, <name> wrote:" line wrapped in a tag
 */
function find_quote_cutoff($html) {
    if ($html === '' || $html === null) return false;
    $candidates = [];

    if (preg_match('/<blockquote\b/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = $m[0][1];
    }
    if (preg_match('/<div\s+id\s*=\s*["\']appendonsend["\']/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = $m[0][1];
    }
    if (preg_match('/<div\s+id\s*=\s*["\']divRplyFwdMsg["\']/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = $m[0][1];
    }
    if (preg_match('/<div\s+class\s*=\s*["\'][^"\']*\bgmail_quote\b/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = $m[0][1];
    }
    if (preg_match('/<hr\b[^>]*>(?=[\s\S]{0,1500}?\bFrom:[\s\S]{0,500}?\bSent:[\s\S]{0,500}?\bSubject:)/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $candidates[] = $m[0][1];
    }

    // Outlook reply quote without an <hr> separator — find the "From:" that's
    // followed shortly by Sent: + Subject:, then walk back to the containing
    // element so the trim covers the whole quote block, not just text.
    if (preg_match_all('/\bFrom:/', $html, $froms, PREG_OFFSET_CAPTURE)) {
        foreach ($froms[0] as $fmatch) {
            $pos    = $fmatch[1];
            $window = substr($html, $pos, 1500);
            if (stripos($window, 'Sent:') !== false && stripos($window, 'Subject:') !== false) {
                $tagStart = strrpos(substr($html, 0, $pos), '<');
                if ($tagStart !== false && substr($html, $tagStart + 1, 1) !== '/') {
                    $candidates[] = $tagStart;
                }
                break;
            }
        }
    }

    // "On <date>, <name> wrote:" — anchored on a date-like token (Today /
    // Yesterday / digit / weekday / month) so phrases like "on holiday … wrote"
    // don't false-match. Walk back to the start of the containing element.
    $datePrefix = '(?:Today|Yesterday|\d|(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*[,\s])';
    if (preg_match('/\bOn\s+' . $datePrefix . '[^<]{1,250}?\swrote\s*:/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $onPos    = $m[0][1];
        $tagStart = strrpos(substr($html, 0, $onPos), '<');
        $candidates[] = ($tagStart !== false && substr($html, $tagStart + 1, 1) !== '/')
            ? $tagStart
            : $onPos;
    }

    if (empty($candidates)) return false;
    return min($candidates);
}

/**
 * Split rendered body into (visible main, collapsed quote). Only trims when
 * the "main" portion has substantive content, so a fully-quoted forward isn't
 * left blank.
 */
function trim_quoted($html) {
    $cutoff = find_quote_cutoff($html);
    if ($cutoff === false) return ['main' => $html, 'quote' => ''];

    $main  = substr($html, 0, $cutoff);
    $quote = substr($html, $cutoff);
    if (mb_strlen(trim(strip_tags($main))) < 30) {
        return ['main' => $html, 'quote' => ''];
    }
    return ['main' => $main, 'quote' => $quote];
}

/**
 * Wrap the rendered body so the quoted/forwarded portion collapses behind
 * a button. Keeps the main reply visible by default, hides the rest.
 */
function wrap_collapsible_quote($body) {
    $split = trim_quoted($body);
    if ($split['quote'] === '') return $body;
    return $split['main']
         . '<div class="email-quote-trim">'
         . '<button class="email-quote-toggle" type="button" aria-expanded="false"'
         .   ' aria-label="Show trimmed content" title="Show trimmed content">'
         .   '<span class="email-quote-dot"></span><span class="email-quote-dot"></span><span class="email-quote-dot"></span>'
         . '</button>'
         . '<div class="email-quote-content" hidden>' . $split['quote'] . '</div>'
         . '</div>';
}

function extract_body($mbox, $uid, $structure) {
    if (empty($structure->parts)) {
        $charset = part_charset($structure);
        $data    = decode_part_body($mbox, $uid, '1', $structure->encoding, $charset);
        $isHtml  = (strtoupper($structure->subtype) === 'HTML');
        $body    = $isHtml ? sanitize_html($data) : plain_to_html($data);
        return ['body' => wrap_collapsible_quote($body), 'is_html' => $isHtml];
    }

    $html  = find_part($structure, 'TEXT', 'HTML');
    $plain = find_part($structure, 'TEXT', 'PLAIN');

    if ($html) {
        $charset = part_charset($html['part']);
        $data    = decode_part_body($mbox, $uid, $html['section'], $html['part']->encoding, $charset);
        return ['body' => wrap_collapsible_quote(sanitize_html($data)), 'is_html' => true];
    }
    if ($plain) {
        $charset = part_charset($plain['part']);
        $data    = decode_part_body($mbox, $uid, $plain['section'], $plain['part']->encoding, $charset);
        return ['body' => wrap_collapsible_quote(plain_to_html($data)), 'is_html' => false];
    }
    return ['body' => '<p style="color:#9ca3af">(message body could not be displayed)</p>', 'is_html' => true];
}

function plain_to_html($text) {
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $linked  = preg_replace(
        '#(https?://[^\s<>"\']+)#i',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $escaped
    );
    return '<div style="white-space:pre-wrap;font-family:inherit;">' . $linked . '</div>';
}

/**
 * Sanitize inbound (attacker-controlled) HTML email for display — the highest-risk
 * surface in the app. Strips scriptable/embedding elements, inline event handlers,
 * dangerous URL schemes (javascript:/vbscript: and any non-raster data: URI), and
 * CSS-based vectors, while preserving the layout, inline styles, links, and raster
 * images legitimate mail relies on. Mirrors the rigor of sanitize_signature_html()
 * in lib/prefs.php; kept separate so the two contexts can be tuned independently.
 */
function sanitize_html($html) {
    if ($html === '' || $html === null) return '';

    // 1+2) Remove scriptable/embedding elements (with their content) and the
    //       standalone tags that inject script/resources or hijack URLs
    //       (<base> can rewrite every relative link). Run to a FIXED POINT so a
    //       tag reassembled by removing an inner one (e.g. <scr<script>ipt>) is
    //       caught on a later pass. Bounded to avoid pathological inputs.
    $withContent = ['script','style','iframe','object','embed','applet',
                    'svg','math','template','noscript','frame','frameset','title'];
    $pass = 0;
    do {
        $before = $html;
        foreach ($withContent as $tag) {
            $html = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', '', $html);
            $html = preg_replace('#</?' . $tag . '\b[^>]*>#i', '', $html); // stray open/close
        }
        $html = preg_replace('#<\s*/?\s*(link|meta|base|param|form|input|button)\b[^>]*>#i', '', $html);
    } while ($html !== $before && ++$pass < 30);
    // Unwrap document-structure tags but keep their inner content.
    $html = preg_replace('#</?(html|head|body)\b[^>]*>#i', '', $html);

    // 3) Strip inline event handlers (onclick=, onerror=, …) in any quoting
    //    style. Anchor on whitespace OR '/', so a slash-separated handler in a
    //    compact tag (e.g. <img src=x/onerror=…>) cannot slip past the filter.
    $html = preg_replace('#[\s/]on[a-z0-9_-]+\s*=\s*"[^"]*"#i', '', $html);
    $html = preg_replace("#[\s/]on[a-z0-9_-]+\s*=\s*'[^']*'#i", '', $html);
    $html = preg_replace('#[\s/]on[a-z0-9_-]+\s*=\s*[^\s>]+#i', '', $html);

    // 4) Neutralize dangerous URL schemes on attributes that navigate or load.
    $urlAttrs = 'href|src|xlink:href|action|formaction|background|poster|cite|longdesc|dynsrc|lowsrc';
    // javascript:/vbscript: (quoted then unquoted)
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)("|\')\s*(?:javascript|vbscript)\s*:[^"\']*\2#i', '$1$2#$2', $html);
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)(?:javascript|vbscript)\s*:[^\s>]*#i', '$1#', $html);
    // data: URIs except allowed raster images — blocks data:image/svg+xml etc. (quoted then unquoted)
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)("|\')\s*data\s*:\s*(?!image/(?:png|jpeg|gif|webp)\b)[^"\']*\2#i', '$1$2#$2', $html);
    $html = preg_replace('#(\b(?:' . $urlAttrs . ')\s*=\s*)data\s*:\s*(?!image/(?:png|jpeg|gif|webp)\b)[^\s>]*#i', '$1#', $html);

    // 5) Defuse CSS-based vectors inside style="" / style=''.
    $defuseCss = function ($css) {
        return preg_replace('#(expression|behavio[u]?r|javascript|vbscript|@import)\s*[:(]#i', 'blocked-', $css);
    };
    $html = preg_replace_callback('#(\sstyle\s*=\s*")([^"]*)(")#i', fn($m) => $m[1] . $defuseCss($m[2]) . $m[3], $html);
    $html = preg_replace_callback("#(\sstyle\s*=\s*')([^']*)(')#i", fn($m) => $m[1] . $defuseCss($m[2]) . $m[3], $html);

    // 6) Force external links to open safely in a new tab.
    $html = preg_replace('#<a\b([^>]*)>#i', '<a$1 target="_blank" rel="noopener noreferrer">', $html);

    return $html;
}

function find_folder($mbox, $ref, $keywords) {
    $list = @imap_list($mbox, $ref, '*');
    if (!$list) return null;
    foreach ($list as $raw) {
        $name = mb_convert_encoding(str_replace($ref, '', $raw), 'UTF-8', 'UTF7-IMAP');
        $low  = strtolower($name);
        foreach ($keywords as $kw) {
            if (strpos($low, $kw) !== false) return $name;
        }
    }
    return null;
}

function parse_thread_ids($rawHeaders) {
    $out = ['msg_id' => '', 'in_reply_to' => '', 'references' => []];
    if (!$rawHeaders) return $out;

    $unfolded = preg_replace('/\r?\n[\t ]+/', ' ', $rawHeaders);
    foreach (preg_split('/\r?\n/', $unfolded) as $line) {
        if (preg_match('/^Message-ID:\s*(.*)$/i', $line, $m)) {
            if (preg_match('/<([^>\s]+)>/', $m[1], $idM)) $out['msg_id'] = $idM[1];
        } elseif (preg_match('/^In-Reply-To:\s*(.*)$/i', $line, $m)) {
            if (preg_match('/<([^>\s]+)>/', $m[1], $idM)) $out['in_reply_to'] = $idM[1];
        } elseif (preg_match('/^References:\s*(.*)$/i', $line, $m)) {
            preg_match_all('/<([^>\s]+)>/', $m[1], $rM);
            $out['references'] = $rM[1];
        }
    }
    return $out;
}

/**
 * Locate UIDs in $box whose Message-ID/In-Reply-To/References contains $needle.
 * Uses a static per-folder overview cache so multi-pass BFS only fetches once
 * per folder per request. Capped at MAX_OVERVIEW_SCAN messages — for large
 * folders (Inbox with thousands of messages) we cap at the most recent slice
 * because real conversations are almost always within recent history.
 */
function find_thread_uids_in_folder($box, $folder, $needle, &$diag = null) {
    static $overviewCache = [];
    $MAX_SCAN = 3000;

    if (!isset($overviewCache[$folder])) {
        $check = @imap_check($box);
        $total = $check ? (int)$check->Nmsgs : 0;
        if ($total === 0) {
            $overviewCache[$folder] = [];
        } else {
            $end   = $total;
            $start = max(1, $total - $MAX_SCAN + 1);
            $rows  = @imap_fetch_overview($box, "$start:$end", 0);
            $overviewCache[$folder] = is_array($rows) ? $rows : [];
            if ($diag !== null) {
                $diag['scans'][] = [
                    'folder' => $folder,
                    'total'  => $total,
                    'scanned'=> count($overviewCache[$folder]),
                ];
            }
        }
    }

    $needleLow = strtolower($needle);
    $hits      = [];
    foreach ($overviewCache[$folder] as $om) {
        $matched = false;
        foreach (['message_id', 'in_reply_to', 'references'] as $hdr) {
            if (empty($om->$hdr)) continue;
            if (stripos($om->$hdr, $needle) !== false) { $matched = true; break; }
        }
        if ($matched) {
            $u = (int)@imap_uid($box, $om->msgno);
            if ($u > 0) $hits[] = $u;
        }
    }
    if ($diag !== null) {
        $diag['searches'][] = [
            'folder' => $folder,
            'needle' => $needle,
            'hits'   => $hits,
        ];
    }
    return $hits;
}

/**
 * Find current UIDs in $mbox for a set of Message-IDs by scanning the folder
 * overview (imap_search HEADER is unreliable on this Dovecot — see the thread
 * lookup note). Used to undo a move/delete by locating the messages in their new
 * folder. Bounded to the most recent 5000 messages.
 */
function uids_for_message_ids($mbox, array $ids) {
    $want = [];
    foreach ($ids as $id) {
        $id = strtolower(trim((string)$id, '<> '));
        if ($id !== '') $want[$id] = true;
    }
    if (!$want) return [];
    $check = @imap_check($mbox);
    $total = $check ? (int)$check->Nmsgs : 0;
    if ($total === 0) return [];
    $start = max(1, $total - 5000 + 1);
    $rows  = @imap_fetch_overview($mbox, "$start:$total", 0);
    if (!is_array($rows)) return [];
    $uids = [];
    foreach ($rows as $om) {
        if (empty($om->message_id)) continue;
        if (isset($want[strtolower(trim($om->message_id, '<> '))])) {
            $u = (int)@imap_uid($mbox, $om->msgno);
            if ($u > 0) $uids[] = $u;
        }
    }
    return $uids;
}

function build_thread_msg($mbox, $uid, $folder, $withBody) {
    $msgno = imap_msgno($mbox, $uid);
    if (!$msgno) return null;
    $h = @imap_headerinfo($mbox, $msgno);
    if (!$h) return null;

    // Pull threading IDs from raw header (imap_headerinfo's message_id/references
    // are sometimes stripped of folding or angle brackets; raw is reliable).
    $rawHeaders = @imap_fetchheader($mbox, $uid, FT_UID);
    $tids       = parse_thread_ids($rawHeaders);
    $msgIdAngle = $tids['msg_id'] !== '' ? '<' . $tids['msg_id'] . '>' : '';
    $refsString = '';
    if (!empty($tids['references'])) {
        $refsString = implode(' ', array_map(function ($r) { return '<' . $r . '>'; }, $tids['references']));
    }

    $from = parse_addr($h->fromaddress ?? '');
    $msg  = [
        'uid'        => (int)$uid,
        'folder'     => $folder,
        'subject'    => decode_header($h->subject ?? '') ?: '(no subject)',
        'from_name'  => $from['name'],
        'from_addr'  => $from['email'],
        'to'         => decode_header($h->toaddress ?? ''),
        'cc'         => decode_header($h->ccaddress ?? ''),
        'bcc'        => decode_header($h->bccaddress ?? ''), // present on saved drafts, lets Resume repopulate Bcc
        'date'       => $h->date ?? '',
        'timestamp'  => isset($h->udate) ? (int)$h->udate : (int)strtotime($h->date ?? 'now'),
        'seen'       => empty($h->Unseen),
        'has_body'   => (bool)$withBody,
        'has_attachments' => false,
        'body'       => '',
        'is_html'    => false,
        'message_id' => $msgIdAngle,
        'references' => $refsString,
        'trust'       => assess_sender_trust($rawHeaders, $from['name'], $from['email'], $_SESSION['email'] ?? ''),
        'unsubscribe' => parse_list_unsubscribe($rawHeaders),
    ];

    if ($withBody) {
        $structure = @imap_fetchstructure($mbox, $uid, FT_UID);
        if ($structure) {
            $body = extract_body($mbox, $uid, $structure);
            $msg['attachments'] = enumerate_attachments($structure);
            $msg['has_attachments'] = has_visible_attachments($msg['attachments']);
            $msg['body']        = rewrite_cid_images($body['body'], $msg['attachments'], $folder, $uid, account_effective_id());
            $msg['is_html']     = $body['is_html'];
            $invite = detect_invite($mbox, $uid, $structure);
            if ($invite) $msg['invite'] = $invite;
        }
    } else {
        $msg['attachments'] = [];
    }

    return $msg;
}

/* ---------- Actions ---------- */

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$folder = $_GET['folder'] ?? ($_POST['folder'] ?? 'INBOX');

if ($action === 'status') {
    // Lightweight poll probe: ONE imap_status round-trip for a single folder,
    // instead of the full `folders` action that runs imap_status on every folder.
    // The client polls this each cycle to detect new mail cheaply, and only falls
    // back to the full `folders` refresh when a change is seen (or periodically).
    $target = ($folder !== '') ? $folder : 'INBOX';
    if (!valid_mailbox_name($target)) fail('Invalid folder', 400);
    $ref  = imap_ref();
    $mbox = open_box('INBOX', OP_HALFOPEN);
    if (!$mbox) fail('Could not connect to mail server');
    $st = @imap_status($mbox, $ref . $target, SA_UNSEEN | SA_MESSAGES | SA_UIDNEXT);
    @imap_close($mbox);
    if (!$st) fail('Could not read folder status');
    ok([
        'folder'  => $target,
        'unread'  => (int)($st->unseen ?? 0),
        'total'   => (int)($st->messages ?? 0),
        'uidnext' => (int)($st->uidnext ?? 0),
    ]);
}

if ($action === 'folders') {
    $ref  = imap_ref();
    $mbox = open_box('INBOX', OP_HALFOPEN);
    if (!$mbox) fail('Could not connect to mail server');

    // Detect hierarchy delimiter (typically '.' on cPanel/Dovecot, '/' elsewhere)
    $delim = '.';
    $boxes = @imap_getmailboxes($mbox, $ref, '*');
    if (is_array($boxes) && !empty($boxes[0]->delimiter)) {
        $delim = $boxes[0]->delimiter;
    }

    $list = @imap_list($mbox, $ref, '*');
    $folders = [];
    if ($list) {
        foreach ($list as $raw) {
            $utf8 = mb_convert_encoding(str_replace($ref, '', $raw), 'UTF-8', 'UTF7-IMAP');
            $status = @imap_status($mbox, $raw, SA_UNSEEN | SA_MESSAGES);
            $folders[] = [
                'name'   => $utf8,
                'unread' => $status ? (int)($status->unseen ?? 0)   : 0,
                'total'  => $status ? (int)($status->messages ?? 0) : 0,
            ];
        }
    }
    @imap_close($mbox);

    usort($folders, function ($a, $b) {
        $order = ['inbox' => 0, 'sent' => 1, 'drafts' => 2, 'trash' => 99, 'deleted' => 99, 'spam' => 98, 'junk' => 98];
        $aw = 50; $bw = 50;
        foreach ($order as $k => $v) {
            if (stripos($a['name'], $k) !== false) { $aw = $v; break; }
        }
        foreach ($order as $k => $v) {
            if (stripos($b['name'], $k) !== false) { $bw = $v; break; }
        }
        return $aw === $bw ? strcasecmp($a['name'], $b['name']) : $aw - $bw;
    });

    ok(['folders' => $folders, 'delimiter' => $delim]);
}

if ($action === 'messages') {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;

    $mbox = open_box($folder);
    if (!$mbox) fail('Could not open folder "' . $folder . '"');

    $check = @imap_check($mbox);
    $total = $check ? (int)$check->Nmsgs : 0;

    if ($total === 0) {
        @imap_close($mbox);
        ok(['messages' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'folder' => $folder]);
    }

    // Paginate over the WHOLE mailbox so every page is reachable. Each page is a
    // slice of $perPage raw messages (newest first); conversations are grouped
    // only WITHIN that slice. Grouping across the entire mailbox would collapse
    // big same-reference threads (e.g. hundreds of delivery-failure bounces) into
    // a few rows and hide everything else.
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = min($page, $pages);
    $end   = $total - (($page - 1) * $perPage);
    $start = max(1, $end - $perPage + 1);

    $messages = [];
    $overview = @imap_fetch_overview($mbox, "$start:$end", 0);
    if (!is_array($overview)) {
        // Transient read error vs a genuinely empty slice — surface an error so
        // the client doesn't show "0 messages" for a mailbox that has some.
        @imap_close($mbox);
        fail('Could not read messages from "' . $folder . '". Please try again.');
    }
    if ($overview) {
        // Flat list — one row per message, newest first. Conversation grouping is
        // intentionally NOT done in the list: it collapses large same-reference
        // floods (e.g. thousands of delivery-failure bounces of a single send)
        // into one row and hides the rest of the page. Threading still happens in
        // the reading pane — opening any message fetches and shows its full
        // conversation. (Per-page grouping also caused split/short pages.)
        $overview = array_reverse($overview); // imap_fetch_overview returns oldest→newest
        foreach ($overview as $m) {
            $from = parse_addr($m->from ?? '');
            $uid  = (int)imap_uid($mbox, $m->msgno);
            $messages[] = [
                'uid'          => $uid,
                'subject'      => decode_header($m->subject ?? '') ?: '(no subject)',
                'from_name'    => $from['name'],
                'from_addr'    => $from['email'],
                'date'         => $m->date ?? '',
                'timestamp'    => isset($m->udate) ? (int)$m->udate : (int)strtotime($m->date ?? 'now'),
                'seen'         => !empty($m->seen),
                'flagged'      => !empty($m->flagged),
                'answered'     => !empty($m->answered),
                'size'         => (int)($m->size ?? 0),
                'message_id'   => isset($m->message_id) ? $m->message_id : '',
                'thread_count' => 1,
                'thread_uids'  => [$uid],
            ];
        }
        usort($messages, function ($a, $b) { return $b['timestamp'] - $a['timestamp']; });
    }
    @imap_close($mbox);

    ok([
        'messages' => $messages,
        'total'    => $total,
        'page'     => $page,
        'pages'    => $pages,
        'folder'   => $folder,
    ]);
}

if ($action === 'attach_flags') {
    // Lazy attachment probe, run AFTER the list has already rendered. The client
    // sends the visible UIDs; we do the (bounded) per-message BODYSTRUCTURE
    // fetches here, off the hot path, so the inbox itself feels instant.
    // Returns { flags: { "<uid>": true, ... } } — only UIDs that have a real
    // (non-inline) attachment are included.
    $uids = array_values(array_filter(
        array_map('intval', explode(',', (string)($_GET['uids'] ?? ''))),
        fn($u) => $u > 0
    ));
    if (empty($uids)) ok(['flags' => (object)[]]);
    if (count($uids) > 200) $uids = array_slice($uids, 0, 200);

    $mbox = open_box($folder);
    if (!$mbox) fail('Could not open folder "' . $folder . '"');
    $flags = [];
    foreach ($uids as $u) {
        $st = @imap_fetchstructure($mbox, $u, FT_UID);
        if ($st && structure_has_attachment($st)) $flags[(string)$u] = true;
    }
    @imap_close($mbox);
    ok(['flags' => (object)$flags]);
}

if ($action === 'unified') {
    // Merged INBOX view across every signed-in account. We mirror each account's
    // credentials into the flat session keys in turn (the same trick accounts_boot
    // uses) so open_box() targets that account, fetch its latest slice, tag each
    // row with the owning account id, then merge-sort by date and cap the total.
    // Bounded by design: a fixed slice per account and a hard overall cap keep the
    // number of IMAP round-trips predictable regardless of mailbox size.
    $perAccount = 25;
    $cap        = 50;
    $accounts   = $_SESSION['accounts'] ?? [];

    $messages = [];
    foreach ($accounts as $id => $acct) {
        account_mirror($acct);
        $mbox = open_box('INBOX');
        if (!$mbox) { @imap_errors(); @imap_alerts(); continue; } // skip unreachable account

        $check = @imap_check($mbox);
        $total = $check ? (int)$check->Nmsgs : 0;
        if ($total > 0) {
            $end   = $total;
            $start = max(1, $end - $perAccount + 1);
            $ov    = @imap_fetch_overview($mbox, "$start:$end", 0);
            if (is_array($ov)) {
                foreach ($ov as $m) {
                    $from = parse_addr($m->from ?? '');
                    $uid  = (int)imap_uid($mbox, $m->msgno);
                    $messages[] = [
                        'uid'             => $uid,
                        'subject'         => decode_header($m->subject ?? '') ?: '(no subject)',
                        'from_name'       => $from['name'],
                        'from_addr'       => $from['email'],
                        'date'            => $m->date ?? '',
                        'timestamp'       => isset($m->udate) ? (int)$m->udate : (int)strtotime($m->date ?? 'now'),
                        'seen'            => !empty($m->seen),
                        'flagged'         => !empty($m->flagged),
                        'answered'        => !empty($m->answered),
                        'size'            => (int)($m->size ?? 0),
                        'thread_count'    => 1,
                        'thread_uids'     => [$uid],
                        'has_attachments' => false, // skipped here to bound IMAP load
                        'acct'            => $id,
                        'acct_email'      => (string)($acct['email'] ?? ''),
                        'folder'          => 'INBOX',
                    ];
                }
            }
        }
        @imap_close($mbox);
    }

    usort($messages, function ($a, $b) { return $b['timestamp'] - $a['timestamp']; });
    if (count($messages) > $cap) $messages = array_slice($messages, 0, $cap);

    // Restore the effective account's mirror so any later code in this request
    // (and the connection state) reflects the account the user is focused on.
    $effId = account_active_id();
    if (isset($accounts[$effId])) account_mirror($accounts[$effId]);

    ok([
        'messages' => $messages,
        'total'    => count($messages),
        'page'     => 1,
        'pages'    => 1,
        'folder'   => 'INBOX',
        'unified'  => true,
    ]);
}

if ($action === 'attachment') {
    $uid     = (int)($_GET['uid'] ?? 0);
    $section = (string)($_GET['section'] ?? '');
    $preview = !empty($_GET['preview']);
    if (!$uid || !preg_match('/^[0-9]+(\.[0-9]+)*$/', $section)) {
        http_response_code(400);
        header('Content-Type: text/plain'); echo 'Bad request'; exit;
    }

    $mbox = open_box($folder);
    if (!$mbox) {
        http_response_code(500);
        header('Content-Type: text/plain'); echo 'Could not open folder'; exit;
    }

    $structure = @imap_fetchstructure($mbox, $uid, FT_UID);
    $part = $structure ? locate_part($structure, $section) : null;
    if (!$part) {
        @imap_close($mbox);
        http_response_code(404);
        header('Content-Type: text/plain'); echo 'Attachment not found'; exit;
    }

    $data = @imap_fetchbody($mbox, $uid, $section, FT_UID);
    @imap_close($mbox);
    if ($data === false) {
        http_response_code(500);
        header('Content-Type: text/plain'); echo 'Could not fetch attachment'; exit;
    }
    if (($part->encoding ?? 0) === 3)      $data = base64_decode($data);
    elseif (($part->encoding ?? 0) === 4)  $data = quoted_printable_decode($data);

    $name = part_filename($part);
    if ($name === '') $name = 'attachment';
    $name = preg_replace('/[\x00-\x1F\/\\\\]/u', '_', $name);
    $type = part_mime_type($part);

    // --- Attachment-serving hardening (defense against content-type XSS) ---
    // An attachment's MIME type is fully attacker-controlled, and preview=1 serves
    // it inline in a same-origin <img>/<iframe>. A part claiming text/html or
    // image/svg+xml would otherwise execute script on our origin inside the
    // authenticated session (full account takeover from opening a message).
    // Defenses, most important first:
    //   1. nosniff — the browser must never sniff octet-stream up to text/html.
    //   2. Inline ONLY a strict allowlist of inert types with their real type;
    //      force everything else to application/octet-stream + attachment, which
    //      downloads rather than renders. This alone neutralises the XSS.
    //   3. A locked-down CSP as belt-and-suspenders on the renderable text path
    //      (NOT on image/pdf: CSP `sandbox` would break the native PDF viewer).
    header('X-Content-Type-Options: nosniff');
    $typeLc     = strtolower(trim(explode(';', $type)[0]));
    $inlineSafe = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf', 'text/plain'];
    if ($preview && in_array($typeLc, $inlineSafe, true)) {
        header('Content-Type: ' . $typeLc);
        $disp = 'inline';
        if ($typeLc === 'text/plain') {
            header("Content-Security-Policy: default-src 'none'; sandbox");
        }
    } else {
        header('Content-Type: application/octet-stream');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        $disp = 'attachment';
    }
    header('Content-Length: ' . strlen($data));
    // RFC 5987 encoding for non-ASCII filenames; ASCII fallback for older clients.
    $asciiName = preg_replace('/[^\x20-\x7e]/', '_', $name);
    header('Content-Disposition: ' . $disp
        . '; filename="' . str_replace('"', '\\"', $asciiName) . '"'
        . "; filename*=UTF-8''" . rawurlencode($name));
    echo $data;
    exit;
}

if ($action === 'message') {
    $uid = (int)($_GET['uid'] ?? 0);
    if (!$uid) fail('Missing uid', 400);

    $mbox = open_box($folder);
    if (!$mbox) fail('Could not open folder "' . $folder . '"');

    $structure = @imap_fetchstructure($mbox, $uid, FT_UID);
    if (!$structure) {
        @imap_close($mbox);
        fail('Message not found');
    }

    $msgno   = imap_msgno($mbox, $uid);
    $headers = @imap_headerinfo($mbox, $msgno);
    if (!$headers) {
        @imap_close($mbox);
        fail('Could not load message headers');
    }

    $body         = extract_body($mbox, $uid, $structure);
    $msgAtts      = enumerate_attachments($structure);
    $renderedBody = rewrite_cid_images($body['body'], $msgAtts, $folder, $uid, account_effective_id());

    $from = parse_addr($headers->fromaddress ?? '');
    $toRaw  = $headers->toaddress ?? '';
    $ccRaw  = $headers->ccaddress ?? '';

    @imap_setflag_full($mbox, (string)$uid, '\\Seen', ST_UID);

    $result = [
        'uid'         => $uid,
        'folder'      => $folder,
        'subject'     => decode_header($headers->subject ?? '') ?: '(no subject)',
        'from_name'   => $from['name'],
        'from_addr'   => $from['email'],
        'to'          => decode_header($toRaw),
        'cc'          => decode_header($ccRaw),
        'date'        => $headers->date ?? '',
        'timestamp'   => isset($headers->udate) ? (int)$headers->udate : (int)strtotime($headers->date ?? 'now'),
        'body'        => $renderedBody,
        'is_html'     => $body['is_html'],
        'attachments' => $msgAtts,
    ];

    $invite = detect_invite($mbox, $uid, $structure);
    if ($invite) $result['invite'] = $invite;

    @imap_close($mbox);
    ok($result);
}

if ($action === 'search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        ok(['results' => [], 'q' => $q, 'folders' => [], 'total' => 0]);
    }
    if (mb_strlen($q) > 200) $q = mb_substr($q, 0, 200);

    // Resolve which folders to search: current folder if user is in something
    // unusual, plus INBOX + Sent (the common case).
    $ref = imap_ref();
    $foldersToSearch = ['INBOX'];
    if (strcasecmp($folder, 'INBOX') !== 0) $foldersToSearch[] = $folder;
    $tmp = open_box('INBOX', OP_HALFOPEN);
    if ($tmp) {
        $sentName = find_folder($tmp, $ref, ['sent']);
        if ($sentName && !in_array($sentName, $foldersToSearch, true)) $foldersToSearch[] = $sentName;
        @imap_close($tmp);
    }

    // Escape for IMAP quoted string ("\\" and "\"" are the only sequences c-client cares about).
    $needle = str_replace(['\\', '"'], ['\\\\', '\\"'], $q);
    // Default = HEADER search (subject/from/to): fast, because the server never
    // scans message bodies. A BODY full-text scan is the slow part on IMAP
    // servers without a full-text index, so it's opt-in via ?scope=full.
    $full = (($_GET['scope'] ?? '') === 'full');
    if ($full) {
        $criterion = 'OR OR OR SUBJECT "' . $needle . '" FROM "' . $needle . '" TO "' . $needle . '" BODY "' . $needle . '"';
        $critList  = ['SUBJECT', 'FROM', 'TO', 'BODY'];
    } else {
        $criterion = 'OR OR SUBJECT "' . $needle . '" FROM "' . $needle . '" TO "' . $needle . '"';
        $critList  = ['SUBJECT', 'FROM', 'TO'];
    }

    $results  = [];
    $seenKey  = [];
    $perFolderCap = 80;

    foreach ($foldersToSearch as $f) {
        $box = open_box($f);
        if (!$box) continue;

        $uids = @imap_search($box, $criterion, SE_UID);
        if (!$uids) {
            // Fall back: try each criterion separately in case the OR form fails on this server.
            $uids = [];
            foreach ($critList as $crit) {
                $r = @imap_search($box, $crit . ' "' . $needle . '"', SE_UID);
                if ($r) $uids = array_merge($uids, $r);
            }
            $uids = array_values(array_unique(array_map('intval', $uids)));
        } else {
            $uids = array_values(array_unique(array_map('intval', $uids)));
        }

        if (empty($uids)) { @imap_close($box); continue; }

        // Newest UIDs first; cap before fetching overview to keep things snappy.
        rsort($uids);
        if (count($uids) > $perFolderCap) $uids = array_slice($uids, 0, $perFolderCap);

        $ov = @imap_fetch_overview($box, implode(',', $uids), FT_UID);
        if ($ov) {
            foreach ($ov as $m) {
                $uid = (int)$m->uid;
                $key = $f . ':' . $uid;
                if (isset($seenKey[$key])) continue;
                $seenKey[$key] = true;
                $from = parse_addr($m->from ?? '');
                $results[] = [
                    'uid'          => $uid,
                    'folder'       => $f,
                    'subject'      => decode_header($m->subject ?? '') ?: '(no subject)',
                    'from_name'    => $from['name'],
                    'from_addr'    => $from['email'],
                    'to'           => decode_header($m->to ?? ''),
                    'date'         => $m->date ?? '',
                    'timestamp'    => isset($m->udate) ? (int)$m->udate : (int)strtotime($m->date ?? 'now'),
                    'seen'         => !empty($m->seen),
                    'flagged'      => !empty($m->flagged),
                    'answered'     => !empty($m->answered),
                    'thread_count' => 1,
                    'thread_uids'  => [$uid],
                ];
            }
        }
        @imap_close($box);
    }

    usort($results, function ($a, $b) { return $b['timestamp'] - $a['timestamp']; });
    if (count($results) > 200) $results = array_slice($results, 0, 200);

    ok([
        'results' => $results,
        'q'       => $q,
        'folders' => $foldersToSearch,
        'total'   => count($results),
        'scope'   => $full ? 'full' : 'headers',
    ]);
}

if ($action === 'thread') {
    $uid = (int)($_GET['uid'] ?? 0);
    if (!$uid) fail('Missing uid', 400);

    $ref  = imap_ref();
    $mbox = open_box($folder);
    if (!$mbox) fail('Could not open folder');

    $msgno = imap_msgno($mbox, $uid);
    if (!$msgno) {
        @imap_close($mbox);
        fail('Message not found');
    }

    $rawHeaders = @imap_fetchheader($mbox, $uid, FT_UID);
    $startInfo  = parse_thread_ids($rawHeaders);
    $focused    = build_thread_msg($mbox, $uid, $folder, true);

    @imap_setflag_full($mbox, (string)$uid, '\\Seen', ST_UID);
    @imap_close($mbox);

    if (!$focused) fail('Could not load message');

    // Passively harvest the people in this thread into the address book for
    // compose autocomplete. Thread opens are user-initiated and bounded, unlike
    // the frequently-polled message list. Best-effort; never blocks the response.
    require_once __DIR__ . '/../lib/contacts.php';

    // Singleton fast path: no chain references and no Message-ID anyone could reply to.
    // We still try to find replies if we have a Message-ID — those CAN exist even if
    // this message doesn't reference anyone. Only true singleton when msg_id is empty.
    if ($startInfo['msg_id'] === ''
        && $startInfo['in_reply_to'] === ''
        && empty($startInfo['references'])) {
        contacts_harvest_messages($_SESSION['email'], [$focused]);
        ok([
            'thread'      => [$focused],
            'subject'     => $focused['subject'],
            'focused_uid' => $uid,
            'folders'     => [$folder],
        ]);
    }

    $foldersToSearch = [$folder];
    if (strcasecmp($folder, 'INBOX') !== 0) $foldersToSearch[] = 'INBOX';
    $tmp = open_box('INBOX', OP_HALFOPEN);
    if ($tmp) {
        $sent = find_folder($tmp, $ref, ['sent']);
        if ($sent && !in_array($sent, $foldersToSearch, true)) $foldersToSearch[] = $sent;
        @imap_close($tmp);
    }

    $boxes = [];
    foreach ($foldersToSearch as $f) {
        $boxes[$f] = open_box($f);
    }

    $diag = null; // set to [] to re-enable per-call diagnostic capture
    $threadIds = [];
    if ($startInfo['msg_id'] !== '')      $threadIds[strtolower($startInfo['msg_id'])]      = $startInfo['msg_id'];
    if ($startInfo['in_reply_to'] !== '') $threadIds[strtolower($startInfo['in_reply_to'])] = $startInfo['in_reply_to'];
    foreach ($startInfo['references'] as $r) {
        $threadIds[strtolower($r)] = $r;
    }

    $foundMessages   = [];
    $foundFolderUid  = [$folder . ':' . $uid => true];
    $checkedIds      = [];
    $startKey        = $startInfo['msg_id'] !== '' ? strtolower($startInfo['msg_id']) : ($folder . ':' . $uid);
    $foundMessages[$startKey] = $focused;

    $maxIterations = 4;
    $maxMessages   = 40;

    for ($iter = 0; $iter < $maxIterations; $iter++) {
        $idsToCheck = array_diff_key($threadIds, $checkedIds);
        if (empty($idsToCheck)) break;
        $newIds = false;

        foreach ($idsToCheck as $key => $idValue) {
            $checkedIds[$key] = true;
            $cleanId = trim($idValue, '<> ');
            if ($cleanId === '' || strlen($cleanId) > 250) continue;
            if (preg_match('/[\r\n"]/', $cleanId)) continue;

            foreach ($foldersToSearch as $f) {
                if (!$boxes[$f]) continue;
                $box    = $boxes[$f];
                $needle = $cleanId;

                // PHP's imap_search HEADER criteria is silently broken via c-client
                // on this Dovecot install — every HEADER query returns no hits even
                // for IDs that demonstrably exist in the folder. Fall back to scanning
                // imap_fetch_overview (which returns message_id/in_reply_to/references
                // per message) and matching client-side.
                $allUids = find_thread_uids_in_folder($box, $f, $needle, $diag);
                $allUids = array_values(array_unique(array_map('intval', $allUids)));

                foreach ($allUids as $foundUid) {
                    $fkey = $f . ':' . $foundUid;
                    if (isset($foundFolderUid[$fkey])) continue;
                    $foundFolderUid[$fkey] = true;

                    $foundRaw = @imap_fetchheader($box, $foundUid, FT_UID);
                    if (!$foundRaw) continue;
                    $info = parse_thread_ids($foundRaw);

                    $msgKey = $info['msg_id'] !== '' ? strtolower($info['msg_id']) : $fkey;
                    if (isset($foundMessages[$msgKey])) continue;

                    $msgData = build_thread_msg($box, $foundUid, $f, false);
                    if (!$msgData) continue;
                    $foundMessages[$msgKey] = $msgData;

                    if ($info['msg_id'] !== '' && !isset($threadIds[strtolower($info['msg_id'])])) {
                        $threadIds[strtolower($info['msg_id'])] = $info['msg_id'];
                        $newIds = true;
                    }
                    if ($info['in_reply_to'] !== '' && !isset($threadIds[strtolower($info['in_reply_to'])])) {
                        $threadIds[strtolower($info['in_reply_to'])] = $info['in_reply_to'];
                        $newIds = true;
                    }
                    foreach ($info['references'] as $r) {
                        if (!isset($threadIds[strtolower($r)])) {
                            $threadIds[strtolower($r)] = $r;
                            $newIds = true;
                        }
                    }

                    if (count($foundMessages) >= $maxMessages) break 4;
                }
            }
        }

        if (!$newIds) break;
    }

    $messages = array_values($foundMessages);
    usort($messages, function ($a, $b) { return $b['timestamp'] - $a['timestamp']; });

    // One structure fetch per message: set the attachment flag for EVERY message
    // (so collapsed thread headers can show a paperclip) and eagerly load the
    // body for just the latest message — the only one expanded by default. Older
    // messages load their body on demand when expanded; the focused message (if
    // not the latest) already carries its body from build_thread_msg() above.
    // The thread is capped ($maxMessages), so this is a bounded number of
    // structure fetches — not the per-row N+1 the message list deliberately avoids.
    $eagerCount = min(1, count($messages));
    foreach ($messages as $i => $_m) {
        $f       = $messages[$i]['folder'];
        $bodyUid = (int)$messages[$i]['uid'];
        if (!isset($boxes[$f]) || !$boxes[$f]) continue;
        $structure = @imap_fetchstructure($boxes[$f], $bodyUid, FT_UID);
        if (!$structure) continue;
        $atts = enumerate_attachments($structure);
        $messages[$i]['has_attachments'] = has_visible_attachments($atts);
        if ($i < $eagerCount && empty($messages[$i]['has_body'])) {
            $body = extract_body($boxes[$f], $bodyUid, $structure);
            $messages[$i]['attachments'] = $atts;
            $messages[$i]['body']        = rewrite_cid_images($body['body'], $atts, $f, $bodyUid, account_effective_id());
            $messages[$i]['is_html']     = $body['is_html'];
            $messages[$i]['has_body']    = true;
            $invite = detect_invite($boxes[$f], $bodyUid, $structure);
            if ($invite) $messages[$i]['invite'] = $invite;
        }
    }

    foreach ($boxes as $box) { if ($box) @imap_close($box); }

    contacts_harvest_messages($_SESSION['email'], $messages);

    ok([
        'thread'      => $messages,
        'subject'     => $messages[0]['subject'] ?? '',
        'focused_uid' => $uid,
        'folders'     => $foldersToSearch,
    ]);
}

/**
 * Accepts either a single `uid` POST field or `uids[]` array. Returns a
 * comma-separated UID-set string suitable for IMAP sequence-set arguments,
 * or '' if nothing valid was sent.
 */
function gather_uid_set() {
    $uids = [];
    if (isset($_POST['uids']) && is_array($_POST['uids'])) {
        foreach ($_POST['uids'] as $u) {
            $u = (int)$u;
            if ($u > 0) $uids[] = $u;
        }
    }
    if (empty($uids) && isset($_POST['uid'])) {
        $u = (int)$_POST['uid'];
        if ($u > 0) $uids[] = $u;
    }
    $uids = array_values(array_unique($uids));
    return $uids;
}

if ($action === 'delete') {
    if ($method !== 'POST') fail('Method not allowed', 405);
    $uids = gather_uid_set();
    if (empty($uids)) fail('Missing uid', 400);

    $ref  = imap_ref();
    $mbox = open_box($folder);
    if (!$mbox) fail('Could not open folder "' . $folder . '"');

    $set   = implode(',', $uids);
    $trash = find_folder($mbox, $ref, ['trash', 'deleted', 'bin']);

    if ($trash && strcasecmp($trash, $folder) !== 0) {
        $moved = @imap_mail_move($mbox, $set, $trash, CP_UID);
        if (!$moved) {
            @imap_close($mbox);
            fail('Could not move to Trash');
        }
    } else {
        @imap_delete($mbox, $set, FT_UID);
    }
    @imap_expunge($mbox);
    @imap_close($mbox);
    ok(['ok' => true, 'count' => count($uids)]);
}

if ($action === 'move') {
    if ($method !== 'POST') fail('Method not allowed', 405);
    $uids = gather_uid_set();
    $from = $_POST['from'] ?? 'INBOX';
    $to   = $_POST['to']   ?? '';
    if (empty($uids) || !$to) fail('Missing parameters', 400);
    if (!valid_mailbox_name($to)) fail('Invalid destination folder', 400);

    $mbox = open_box($from);
    if (!$mbox) fail('Could not open source folder');

    $set   = implode(',', $uids);
    $moved = @imap_mail_move($mbox, $set, $to, CP_UID);
    @imap_expunge($mbox);
    @imap_close($mbox);

    if (!$moved) fail('Could not move message');
    ok(['ok' => true, 'count' => count($uids)]);
}

if ($action === 'restore') {
    // Undo a delete/archive/move: move messages identified by Message-ID back from
    // $from (where they landed) to $to (where they were). Message-ID match survives
    // the uid change a move causes.
    if ($method !== 'POST') fail('Method not allowed', 405);
    $from = $_POST['from'] ?? '';
    $to   = $_POST['to']   ?? '';
    $ids  = (isset($_POST['message_ids']) && is_array($_POST['message_ids'])) ? $_POST['message_ids'] : [];
    if ($from === '' || $to === '' || empty($ids)) fail('Missing parameters', 400);
    if (!valid_mailbox_name($from) || !valid_mailbox_name($to)) fail('Invalid folder', 400);

    $mbox = open_box($from);
    if (!$mbox) fail('Could not open folder');
    $uids  = uids_for_message_ids($mbox, $ids);
    $count = 0;
    if ($uids) {
        if (@imap_mail_move($mbox, implode(',', $uids), $to, CP_UID)) {
            @imap_expunge($mbox);
            $count = count($uids);
        }
    }
    @imap_close($mbox);
    ok(['ok' => true, 'count' => $count]);
}

if ($action === 'flag') {
    if ($method !== 'POST') fail('Method not allowed', 405);
    $uids   = gather_uid_set();
    $setOn  = !empty($_POST['set']);
    if (empty($uids)) fail('Missing uid', 400);

    $mbox = open_box($folder);
    if (!$mbox) fail('Could not open folder');

    $set = implode(',', $uids);
    if ($setOn) @imap_setflag_full($mbox, $set, '\\Flagged', ST_UID);
    else        @imap_clearflag_full($mbox, $set, '\\Flagged', ST_UID);

    @imap_close($mbox);
    ok(['ok' => true, 'count' => count($uids)]);
}

if ($action === 'unread') {
    if ($method !== 'POST') fail('Method not allowed', 405);
    $uids = gather_uid_set();
    if (empty($uids)) fail('Missing uid', 400);

    $mbox = open_box($folder);
    if (!$mbox) fail('Could not open folder');

    @imap_clearflag_full($mbox, implode(',', $uids), '\\Seen', ST_UID);
    @imap_close($mbox);
    ok(['ok' => true, 'count' => count($uids)]);
}

if ($action === 'read') {
    if ($method !== 'POST') fail('Method not allowed', 405);
    $uids = gather_uid_set();
    if (empty($uids)) fail('Missing uid', 400);

    $mbox = open_box($folder);
    if (!$mbox) fail('Could not open folder');

    @imap_setflag_full($mbox, implode(',', $uids), '\\Seen', ST_UID);
    @imap_close($mbox);
    ok(['ok' => true, 'count' => count($uids)]);
}

if ($action === 'mark_folder_read') {
    if ($method !== 'POST') fail('Method not allowed', 405);
    $f = trim((string)($_POST['folder'] ?? ''));
    if ($f === '') fail('Missing folder', 400);

    $box = open_box($f);
    if (!$box) fail('Could not open folder');

    $check = @imap_check($box);
    $total = $check ? (int)$check->Nmsgs : 0;
    $marked = 0;
    if ($total > 0) {
        if (@imap_setflag_full($box, '1:' . $total, '\\Seen')) {
            $marked = $total;
        }
    }
    @imap_close($box);
    ok(['ok' => true, 'count' => $marked]);
}

if ($action === 'create_folder') {
    if ($method !== 'POST') fail('Method not allowed', 405);
    $name = trim((string)($_POST['name'] ?? ''));
    $parent = trim((string)($_POST['parent'] ?? ''));
    if ($name === '') fail('Folder name required', 400);
    if (!valid_mailbox_name($name) || ($parent !== '' && !valid_mailbox_name($parent))) {
        fail('Invalid folder name', 400);
    }

    $ref = imap_ref();
    $tmp = open_box('INBOX', OP_HALFOPEN);
    if (!$tmp) fail('Could not connect');

    // Determine the hierarchy delimiter ('.' on Dovecot/cPanel, '/' elsewhere)
    // by inspecting an existing folder. Default to '.' which covers the
    // typical INBOX.<name> namespace this app runs under.
    $delim = '.';
    $list = @imap_list($tmp, $ref, '*');
    if (is_array($list) && count($list) > 0) {
        // imap_getmailboxes gives delimiter cleanly:
        $boxes = @imap_getmailboxes($tmp, $ref, '*');
        if (is_array($boxes) && !empty($boxes[0]->delimiter)) {
            $delim = $boxes[0]->delimiter;
        }
    }

    $segment = trim($name, $delim . ' ');
    $fullName = $parent !== '' ? $parent . $delim . $segment : 'INBOX' . $delim . $segment;

    // Convert to UTF7-IMAP for non-ASCII names.
    $imapName = mb_convert_encoding($fullName, 'UTF7-IMAP', 'UTF-8');
    $created = @imap_createmailbox($tmp, $ref . $imapName);
    if ($created) @imap_subscribe($tmp, $ref . $imapName);
    @imap_close($tmp);

    if (!$created) {
        $errs = @imap_errors() ?: ['IMAP server rejected the name'];
        fail('Could not create folder: ' . implode('; ', $errs));
    }

    ok(['ok' => true, 'name' => $fullName]);
}

if ($action === 'delete_folder') {
    if ($method !== 'POST') fail('Method not allowed', 405);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') fail('Folder name required', 400);
    if (!valid_mailbox_name($name)) fail('Invalid folder name', 400);

    // Refuse to delete the standard system folders. We compare against the
    // last segment so e.g. "INBOX.Sent" still maps to "sent" and is protected.
    $standard = ['inbox', 'sent', 'drafts', 'trash', 'deleted', 'spam', 'junk', 'archive', 'later', 'outbox'];
    $segments = preg_split('/[.\\/]/', $name);
    $leaf     = strtolower(end($segments));
    if (in_array($leaf, $standard, true)) fail('Cannot delete a standard mail folder.', 403);
    if (strcasecmp($name, 'INBOX') === 0)  fail('Cannot delete a standard mail folder.', 403);

    $ref  = imap_ref();
    $tmp  = open_box('INBOX', OP_HALFOPEN);
    if (!$tmp) fail('Could not connect');

    // Detect any subfolders that would be left orphaned — block deletion if so.
    $delim = '.';
    $boxes = @imap_getmailboxes($tmp, $ref, '*');
    if (is_array($boxes) && !empty($boxes[0]->delimiter)) $delim = $boxes[0]->delimiter;

    $list = @imap_list($tmp, $ref, '*');
    $hasChildren = false;
    if (is_array($list)) {
        foreach ($list as $raw) {
            $utf8 = mb_convert_encoding(str_replace($ref, '', $raw), 'UTF-8', 'UTF7-IMAP');
            if ($utf8 !== $name && strpos($utf8, $name . $delim) === 0) {
                $hasChildren = true;
                break;
            }
        }
    }
    if ($hasChildren) {
        @imap_close($tmp);
        fail('Folder has subfolders. Delete those first.', 409);
    }

    $imapName = mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
    @imap_unsubscribe($tmp, $ref . $imapName);
    $deleted = @imap_deletemailbox($tmp, $ref . $imapName);
    @imap_close($tmp);

    if (!$deleted) {
        $errs = @imap_errors() ?: ['IMAP server rejected the request'];
        fail('Could not delete folder: ' . implode('; ', $errs));
    }
    ok(['ok' => true, 'name' => $name]);
}

fail('Unknown action: ' . $action, 400);
