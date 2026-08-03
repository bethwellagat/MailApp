<?php
/** Shared helpers in lib/util.php: sizes, atomic writes, poll cache, folders, TLS, expunge. */

require_once T_ROOT . '/lib/util.php';

t_group('ini_bytes — PHP shorthand sizes');
t_eq('2M',        ini_bytes('2M'),        2097152);
t_eq('8M',        ini_bytes('8M'),        8388608);
t_eq('512K',      ini_bytes('512K'),      524288);
t_eq('1G',        ini_bytes('1G'),        1073741824);
t_eq('plain int', ini_bytes('8388608'),   8388608);
t_eq('whitespace',ini_bytes('  40M '),    41943040);
t_eq('unlimited', ini_bytes('-1'),        0);
t_eq('empty',     ini_bytes(''),          0);

t_group('atomic_write_json — publish must be all-or-nothing');
$f = t_tmpdir() . '/store.json';
t_ok('writes',              atomic_write_json($f, ['a' => 1]) === true);
t_eq('round-trips',         json_decode(file_get_contents($f), true), ['a' => 1]);
t_ok('overwrites',          atomic_write_json($f, ['a' => 2]) === true);
t_eq('new value visible',   json_decode(file_get_contents($f), true), ['a' => 2]);
t_ok('leaves no temp files', count(glob(t_tmpdir() . '/store.json.*')) === 0,
     implode(',', glob(t_tmpdir() . '/store.json.*')));
// A value PHP cannot encode must fail without destroying the existing file.
$bad = fopen('php://memory', 'r');
t_ok('unencodable value returns false', atomic_write_json($f, ['h' => $bad]) === false);
fclose($bad);
t_eq('previous contents intact', json_decode(file_get_contents($f), true), ['a' => 2]);

t_group('resolve_folder — must not pick a decoy folder and move mail into it');
// Mirrors the ranking in resolve_folder() over a synthetic mailbox list.
function t_pick(array $names, array $kw) {
    $best = null; $bestScore = -1;
    foreach ($names as $name) {
        $parts = preg_split('/[.\/]/', $name);
        $leaf  = strtolower((string)end($parts));
        $depth = max(0, count($parts) - 1);
        $low   = strtolower($name);
        foreach ($kw as $i => $k) {
            $k = strtolower($k);
            if     ($leaf === $k)                $t = 3;
            elseif (strpos($leaf, $k) === 0)     $t = 2;
            elseif (strpos($low, $k) !== false)  $t = 1;
            else continue;
            $s = $t * 1000 - $i * 100 - min($depth, 20);
            if ($s > $bestScore) { $bestScore = $s; $best = $name; }
            break;
        }
    }
    return $best;
}
$boxes = ['INBOX', 'INBOX.Trashed receipts', 'INBOX.Trash', 'INBOX.Archive.Sent 2019',
          'INBOX.Sent', 'INBOX.Deleted Items', 'INBOX.Projects.archive-old', 'INBOX.Archive'];
t_eq('real Trash beats "Trashed receipts"', t_pick($boxes, ['trash','deleted','bin']), 'INBOX.Trash');
t_eq('real Sent beats "Archive.Sent 2019"', t_pick($boxes, ['sent']),                  'INBOX.Sent');
t_eq('real Archive beats nested decoy',     t_pick($boxes, ['archive']),               'INBOX.Archive');
t_eq('keyword order is a preference',       t_pick(['INBOX.Deleted Items','INBOX.Trash'], ['trash','deleted']), 'INBOX.Trash');
t_eq('substring is the fallback',           t_pick(['INBOX.Trashed receipts'], ['trash']), 'INBOX.Trashed receipts');
t_eq('no match yields null',                t_pick(['INBOX','INBOX.Work'], ['trash']), null);

t_group('TLS policy — validate remote hosts, stay relaxed for local ones');
foreach (['localhost','127.0.0.1','::1','192.168.1.10','10.0.0.5','172.16.0.9'] as $h) {
    t_ok("local: $h", tls_host_is_local($h) === true);
}
foreach (['mail.example.com','imap.gmail.com','8.8.8.8','203.0.113.7'] as $h) {
    t_ok("public: $h", tls_host_is_local($h) === false);
}
t_eq('public + ssl is validated',      imap_tls_flags('mail.example.com', true),  '/imap/ssl');
t_eq('localhost + ssl stays relaxed',  imap_tls_flags('localhost', true),         '/imap/ssl/novalidate-cert');
t_eq('private IP + ssl stays relaxed', imap_tls_flags('192.168.1.10', true),      '/imap/ssl/novalidate-cert');
t_eq('no ssl means notls',             imap_tls_flags('mail.example.com', false), '/imap/notls');

t_group('expunge scoping — another client\'s pending deletes must survive');
// Mirrors the partition inside expunge_only(): foreign = all \Deleted minus ours.
$part = fn(array $all, array $ours) => array_values(array_diff(array_map('intval', $all), array_map('intval', $ours)));
t_eq('only our own flagged',        $part([5,6], [5,6]),      []);
t_eq('another client pending',      $part([5,6,99], [5,6]),   [99]);
t_eq('only foreign flagged',        $part([99,100], []),      [99,100]);
t_eq('nothing flagged yet',         $part([], [5]),           []);
t_eq('string and int UIDs compare', $part(['5','99'], [5]),   [99]);

t_group('poll cache — background polls share one IMAP round-trip');
$em = 'tests-poll@example.invalid';
@unlink(_poll_cache_file($em));
t_ok('cold miss',            poll_cache_get($em, 'status:INBOX', 30) === null);
poll_cache_put($em, 'status:INBOX', ['unread' => 3]);
t_eq('warm hit',             poll_cache_get($em, 'status:INBOX', 30)['unread'] ?? null, 3);
t_ok('folders are separate', poll_cache_get($em, 'status:Sent', 30) === null);
t_ok('expiry respected',     poll_cache_get($em, 'status:INBOX', 0) === null);
for ($i = 0; $i < 40; $i++) poll_cache_put($em, "status:F$i", ['n' => $i]);
$raw = json_decode((string)file_get_contents(_poll_cache_file($em)), true);
t_ok('file stays bounded',   count($raw) <= 24, 'entries: ' . count($raw));
t_ok('newest kept',          isset($raw['status:F39']));
t_ok('oldest evicted',       !isset($raw['status:F0']));
@unlink(_poll_cache_file($em));

t_group('strip_event_handlers — parses attributes, does not pattern-match them');
t_eq('drops the handler only',
     strip_event_handlers('<img src="x" onerror="alert(1)" alt="p">'),
     '<img src="x" alt="p">');
t_eq('keeps a value that begins with "on"',
     strip_event_handlers('<a href="online=1">'),
     '<a href="online=1">');
t_eq('unbalanced quote still handled',
     strip_event_handlers('<img src=x" onerror=alert(1)>'),
     '<img src=x">');
t_eq('valueless attributes survive',
     strip_event_handlers('<td nowrap>'),
     '<td nowrap>');
