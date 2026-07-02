<?php
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in']);
    exit;
}

require_once __DIR__ . '/../lib/calendar.php';
require_once __DIR__ . '/../lib/ics_parser.php';
require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();
session_write_close(); // release the session lock early — a slow ICS feed sync must not block the user's other requests (see fetch.php)

$email = $_SESSION['email'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Serialize the quick calendar mutations' load→modify→save so concurrent writes
// (two tabs, or a poll racing an edit) can't clobber each other. feed_sync and
// rsvp do slow network I/O first and take the lock themselves; read-only actions
// need none. Held until the script exits (ok()/fail() both exit → PHP releases).
$__CAL_QUICK = ['event_add', 'event_update', 'event_delete', 'feed_add', 'feed_delete', 'feed_toggle'];
$__calLock = in_array($action, $__CAL_QUICK, true) ? calendar_lock($email) : null;

function ok($data) { echo json_encode($data); exit; }
function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
function require_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST required', 405);
}
function input_json() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/* ------------------------------------------------------------ */
/* IMAP access for RSVP — re-fetch the invitation server-side so */
/* the organizer/UID we reply to are authoritative. Credentials  */
/* live only in $_SESSION, never on disk.                        */
/* ------------------------------------------------------------ */

function _cal_imap_ref() {
    $ssl   = !empty($_SESSION['imap_ssl']);
    $port  = (int)($_SESSION['imap_port'] ?? 993);
    $host  = $_SESSION['imap_host'];
    $flags = $ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls';
    return '{' . $host . ':' . $port . $flags . '}';
}

function _cal_open_box($folder) {
    // Reject c-client metacharacters / control chars so a folder value cannot
    // rewrite the {host:port} ref and redirect the IMAP connection.
    if (!is_string($folder) || $folder === '' || preg_match('/[{}\x00-\x1F\x7F]/', $folder)) return false;
    return @imap_open(_cal_imap_ref() . $folder, $_SESSION['email'], $_SESSION['password'], OP_READONLY, 1);
}

/** Recursively locate the first text/calendar part: returns ['part','section'] or null. */
function _cal_find_calendar($structure, $section = '') {
    if (empty($structure->parts)) {
        if (($structure->type ?? -1) === TYPETEXT && strtoupper($structure->subtype ?? '') === 'CALENDAR') {
            return ['part' => $structure, 'section' => $section === '' ? '1' : $section];
        }
        return null;
    }
    foreach ($structure->parts as $i => $p) {
        $sec = $section === '' ? (string)($i + 1) : $section . '.' . ($i + 1);
        if (($p->type ?? -1) === TYPETEXT && strtoupper($p->subtype ?? '') === 'CALENDAR') {
            return ['part' => $p, 'section' => $sec];
        }
        if (!empty($p->parts)) {
            $found = _cal_find_calendar($p, $sec);
            if ($found) return $found;
        }
    }
    return null;
}

function _cal_part_charset($part) {
    foreach (['parameters', 'dparameters'] as $bag) {
        if (!empty($part->$bag)) {
            foreach ($part->$bag as $p) {
                if (strtolower($p->attribute) === 'charset') return $p->value;
            }
        }
    }
    return '';
}

function _cal_decode_section($mbox, $uid, $section, $encoding, $charset) {
    $data = @imap_fetchbody($mbox, $uid, $section, FT_UID);
    if ($data === false || $data === '') return '';
    if ($encoding === ENCBASE64)              $data = base64_decode($data);
    elseif ($encoding === ENCQUOTEDPRINTABLE) $data = quoted_printable_decode($data);
    if ($charset && strtoupper($charset) !== 'UTF-8') {
        $c = @mb_convert_encoding($data, 'UTF-8', $charset);
        if ($c !== false) $data = $c;
    }
    return $data;
}

/** Wrap an iTip REPLY as a multipart/alternative message (cover note + REPLY). */
function _cal_build_reply_mime($from, $fromName, $to, $verb, $summary, $icsReply) {
    $boundary = 'rsvp_' . bin2hex(random_bytes(8));
    $domain   = substr(strrchr($from, '@'), 1) ?: 'localhost';
    $msgId    = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    $fromHdr  = $fromName !== '' ? mime_header_encode($fromName) . ' <' . $from . '>' : $from;
    $subject  = $verb . ': ' . $summary;
    $text     = $verb . ' the invitation "' . $summary . '".' . "\r\n";

    $headers = [
        'From: ' . $fromHdr,
        'To: ' . $to,
        'Subject: ' . mime_header_encode($subject),
        'MIME-Version: 1.0',
        'Date: ' . date('r'),
        'Message-ID: ' . $msgId,
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $body  = '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($text) . "\r\n\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/calendar; method=REPLY; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($icsReply), 76, "\r\n") . "\r\n";
    $body .= '--' . $boundary . "--\r\n";

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

/* ------------------------------------------------------------ */
/* Helpers                                                      */
/* ------------------------------------------------------------ */

function gather_events_in_range($cal, $from, $to) {
    $out = [];

    foreach ($cal['events'] ?? [] as $e) {
        if (!isset($e['start'], $e['end'])) continue;
        if ($e['end'] >= $from && $e['start'] <= $to) {
            $out[] = array_merge($e, ['source' => 'local']);
        }
    }

    $feeds = [];
    foreach ($cal['feeds'] ?? [] as $f) $feeds[$f['id']] = $f;
    $feedEvents = (array)($cal['feed_events'] ?? []);
    foreach ($feedEvents as $fid => $events) {
        $feed = $feeds[$fid] ?? null;
        if (!$feed || empty($feed['enabled'])) continue;
        foreach ((array)$events as $e) {
            $expanded = ics_expand_event($e, $from, $to);
            foreach ($expanded as $occ) {
                $occ['source']     = 'feed';
                $occ['feed_id']    = $fid;
                $occ['feed_name']  = $feed['name'] ?? '';
                $occ['feed_color'] = $feed['color'] ?? '#1f5fb8';
                $occ['id']         = $fid . ':' . ($occ['uid'] ?? '?') . ':' . ($occ['start'] ?? '');
                $out[] = $occ;
            }
        }
    }

    usort($out, function($a, $b) {
        return strcmp($a['start'] ?? '', $b['start'] ?? '');
    });
    return $out;
}

/* ------------------------------------------------------------ */
/* Routes                                                       */
/* ------------------------------------------------------------ */

if ($action === 'events') {
    $from = $_GET['from'] ?? gmdate('Y-m-d\T00:00:00\Z', strtotime('-1 month'));
    $to   = $_GET['to']   ?? gmdate('Y-m-d\T23:59:59\Z', strtotime('+12 months'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $from) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $to)) {
        fail('Bad date range');
    }
    $cal = load_calendar($email);
    ok([
        'events' => gather_events_in_range($cal, $from, $to),
        'feeds'  => array_values($cal['feeds'] ?? []),
    ]);
}

if ($action === 'event_add') {
    require_post();
    $e = input_json();
    $clean = sanitize_event_input($e);
    if (!$clean) fail('Title and start are required');
    $clean['source']     = 'local';
    $clean['created_at'] = gmdate('c');
    $cal = load_calendar($email);
    $cal['events'][] = $clean;
    if (!save_calendar($email, $cal)) fail('Could not save event', 500);
    ok(['event' => $clean]);
}

if ($action === 'event_update') {
    require_post();
    $e = input_json();
    $id = $e['id'] ?? '';
    if (!$id) fail('id required');
    $clean = sanitize_event_input($e);
    if (!$clean) fail('Title and start are required');
    $clean['id'] = $id;
    $cal = load_calendar($email);
    $found = false;
    foreach ($cal['events'] as $i => $ev) {
        if (($ev['id'] ?? '') === $id) {
            $cal['events'][$i] = array_merge($ev, $clean);
            $found = true;
            break;
        }
    }
    if (!$found) fail('Event not found', 404);
    if (!save_calendar($email, $cal)) fail('Could not save event', 500);
    ok(['event' => $cal['events'][$i]]);
}

if ($action === 'event_delete') {
    require_post();
    $e = input_json();
    $id = $e['id'] ?? '';
    if (!$id) fail('id required');
    $cal = load_calendar($email);
    $before = count($cal['events']);
    $cal['events'] = array_values(array_filter($cal['events'], fn($ev) => ($ev['id'] ?? '') !== $id));
    if (count($cal['events']) === $before) fail('Event not found', 404);
    if (!save_calendar($email, $cal)) fail('Could not save', 500);
    ok(['deleted' => $id]);
}

if ($action === 'feed_add') {
    require_post();
    $e = input_json();
    $name = trim((string)($e['name'] ?? ''));
    $url  = trim((string)($e['url'] ?? ''));
    $color = trim((string)($e['color'] ?? '#1f5fb8'));
    if ($name === '' || $url === '') fail('Name and URL are required');
    if (!preg_match('#^https?://#i', $url)) fail('URL must start with http:// or https://');
    $cal = load_calendar($email);
    $feed = [
        'id'       => calendar_uuid(),
        'name'     => mb_substr($name, 0, 80),
        'url'      => $url,
        'color'    => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#1f5fb8',
        'enabled'  => true,
        'created_at'      => gmdate('c'),
        'last_sync_at'    => null,
        'last_sync_error' => null,
        'event_count'     => 0,
    ];
    $cal['feeds'][] = $feed;
    if (!save_calendar($email, $cal)) fail('Could not save feed', 500);
    ok(['feed' => $feed]);
}

if ($action === 'feed_delete') {
    require_post();
    $e = input_json();
    $id = $e['id'] ?? '';
    if (!$id) fail('id required');
    $cal = load_calendar($email);
    $before = count($cal['feeds']);
    $cal['feeds'] = array_values(array_filter($cal['feeds'], fn($f) => ($f['id'] ?? '') !== $id));
    if (is_array($cal['feed_events'])) unset($cal['feed_events'][$id]);
    elseif (is_object($cal['feed_events'])) unset($cal['feed_events']->$id);
    if (count($cal['feeds']) === $before) fail('Feed not found', 404);
    if (!save_calendar($email, $cal)) fail('Could not save', 500);
    ok(['deleted' => $id]);
}

if ($action === 'feed_toggle') {
    require_post();
    $e = input_json();
    $id = $e['id'] ?? '';
    $enabled = !empty($e['enabled']);
    if (!$id) fail('id required');
    $cal = load_calendar($email);
    $found = false;
    foreach ($cal['feeds'] as $i => $f) {
        if (($f['id'] ?? '') === $id) {
            $cal['feeds'][$i]['enabled'] = $enabled;
            $found = true;
            break;
        }
    }
    if (!$found) fail('Feed not found', 404);
    if (!save_calendar($email, $cal)) fail('Could not save', 500);
    ok(['feed' => $cal['feeds'][$i]]);
}

if ($action === 'feed_sync') {
    require_post();
    $e = input_json();
    $id = $e['id'] ?? '';
    if (!$id) fail('id required');
    $cal = load_calendar($email);
    $idx = -1;
    foreach ($cal['feeds'] as $i => $f) {
        if (($f['id'] ?? '') === $id) { $idx = $i; break; }
    }
    if ($idx < 0) fail('Feed not found', 404);

    [$body, $err] = calendar_fetch_url($cal['feeds'][$idx]['url']);

    // Re-acquire under lock and reload: the network fetch above must NOT run while
    // holding the lock, and a concurrent calendar write during it would otherwise
    // be clobbered by our stale pre-fetch snapshot. Re-find the feed by id.
    $lock = calendar_lock($email);
    $cal  = load_calendar($email);
    $idx  = -1;
    foreach ($cal['feeds'] as $i => $f) { if (($f['id'] ?? '') === $id) { $idx = $i; break; } }
    if ($idx < 0) { calendar_unlock($lock); fail('Feed not found', 404); }

    if ($err) {
        $cal['feeds'][$idx]['last_sync_error'] = $err;
        $cal['feeds'][$idx]['last_sync_at']    = gmdate('c');
        save_calendar($email, $cal);
        calendar_unlock($lock);
        fail($err, 502);
    }

    $events = ics_parse($body);
    if (!is_array($cal['feed_events'])) $cal['feed_events'] = (array)$cal['feed_events'];
    $cal['feed_events'][$id] = $events;
    $cal['feeds'][$idx]['last_sync_at']    = gmdate('c');
    $cal['feeds'][$idx]['last_sync_error'] = null;
    $cal['feeds'][$idx]['event_count']     = count($events);
    $saved = save_calendar($email, $cal);
    calendar_unlock($lock);
    if (!$saved) fail('Could not save', 500);

    ok([
        'feed'  => $cal['feeds'][$idx],
        'count' => count($events),
    ]);
}

if ($action === 'rsvp') {
    require_post();
    if (!function_exists('imap_open')) fail('IMAP extension not enabled', 500);
    if (empty($_SESSION['imap_host']) || empty($_SESSION['smtp_host']) || empty($_SESSION['password'])) {
        fail('Mail session unavailable', 401);
    }
    require_once __DIR__ . '/../lib/mailer.php';

    $in       = input_json();
    $folder   = (string)($in['folder'] ?? 'INBOX');
    $uid      = (int)($in['uid'] ?? 0);
    $response = strtolower(trim((string)($in['response'] ?? '')));
    $map = [
        'accept'    => 'ACCEPTED', 'accepted'  => 'ACCEPTED',
        'decline'   => 'DECLINED', 'declined'  => 'DECLINED',
        'tentative' => 'TENTATIVE',
    ];
    if (!isset($map[$response])) fail('Invalid RSVP response');
    $partstat = $map[$response];
    if (!$uid) fail('Missing message uid');
    if (preg_match('/[\r\n]/', $folder)) fail('Bad folder');

    // Re-fetch the invitation from the user's own mailbox. The organizer address
    // we reply to is taken from this server-side copy, never from the client.
    $mbox = _cal_open_box($folder);
    if (!$mbox) fail('Could not open mailbox', 502);
    $structure = @imap_fetchstructure($mbox, $uid, FT_UID);
    $calPart   = $structure ? _cal_find_calendar($structure) : null;
    if (!$calPart) { @imap_close($mbox); fail('No calendar invitation on that message', 404); }
    $raw = _cal_decode_section($mbox, $uid, $calPart['section'], $calPart['part']->encoding ?? 0, _cal_part_charset($calPart['part']));
    @imap_close($mbox);
    if ($raw === '') fail('Could not read the invitation', 502);

    $itip = ics_parse_itip($raw);
    if (empty($itip['events'])) fail('Invitation contained no event', 422);
    $ev = $itip['events'][0];

    $organizer = strtolower(trim((string)($ev['organizer']['email'] ?? '')));
    if ($organizer === '' || !filter_var($organizer, FILTER_VALIDATE_EMAIL)) {
        fail('Invitation has no valid organizer to reply to', 422);
    }

    $me      = $_SESSION['email'];
    $myName  = (string)($_SESSION['display_name'] ?? '');
    $summary = $ev['summary'] !== '' ? $ev['summary'] : '(no title)';
    $verb    = ['ACCEPTED' => 'Accepted', 'DECLINED' => 'Declined', 'TENTATIVE' => 'Tentatively accepted'][$partstat];

    $replyIcs = ics_build_reply($ev, $me, $myName, $partstat);
    $message  = _cal_build_reply_mime($me, $myName, $organizer, $verb, $summary, $replyIcs);
    $r = smtp_send($_SESSION['smtp_host'], $me, [$organizer], $message, $me, $_SESSION['password']);
    if (!$r['ok']) fail('Could not send the RSVP: ' . $r['error'], 502);

    // Reflect the decision locally so it appears in the sidebar calendar. All the
    // network I/O (IMAP re-fetch + SMTP reply) is done above, so locking the
    // load→save here is quick.
    $calLock = calendar_lock($email);
    $cal    = load_calendar($email);
    $uidKey = (string)$ev['uid'];
    if ($uidKey !== '') {
        $cal['events'] = array_values(array_filter($cal['events'], function ($e) use ($uidKey) {
            return ($e['ics_uid'] ?? '') !== $uidKey;
        }));
    }
    if ($partstat !== 'DECLINED') {
        $cal['events'][] = [
            'id'         => calendar_uuid(),
            'source'     => 'invite',
            'ics_uid'    => $uidKey,
            'title'      => mb_substr($summary, 0, 200),
            'start'      => $ev['start'],
            'end'        => $ev['end'],
            'all_day'    => !empty($ev['all_day']),
            'location'   => mb_substr((string)$ev['location'], 0, 200),
            'notes'      => mb_substr((string)$ev['description'], 0, 4000),
            'rsvp'       => $partstat,
            'organizer'  => $organizer,
            'updated_at' => gmdate('c'),
        ];
    }
    save_calendar($email, $cal);
    calendar_unlock($calLock);

    ok(['ok' => true, 'partstat' => $partstat, 'added' => $partstat !== 'DECLINED']);
}

fail('Unknown action: ' . $action);
