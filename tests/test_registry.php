<?php
/**
 * The installation registry: signing, the check-in, the store, and the fleet
 * update — every decision tested without a network or a real update.
 */
$GLOBALS['MAILAPP_DATA_DIR'] = t_tmpdir() . '/data';
require_once T_ROOT . '/lib/registry.php';
$T = 1792400000;

// Armed for the whole file, before any check-in can happen: applying an update
// is reached only through this seam, so no test — and no mutation of a guard
// while proving it bites — can ever call the real updater and rewrite the tree.
$GLOBALS['T_APPLIED'] = 0;
$GLOBALS['REGISTRY_APPLY'] = function () { $GLOBALS['T_APPLIED']++; return ['version' => 'abc1234def', 'files' => 42]; };

t_group('registry: signing');
$sig = registry_sign('{"a":1}', 'shh', $T);
t_ok('a signature is hex sha256', preg_match('/^[a-f0-9]{64}$/', $sig) === 1);
t_ok('the right body, secret and moment pass', registry_sig_ok('{"a":1}', 'shh', $T, $sig, $T));
t_ok('a changed body fails',     !registry_sig_ok('{"a":2}', 'shh', $T, $sig, $T));
t_ok('a wrong secret fails',     !registry_sig_ok('{"a":1}', 'other', $T, $sig, $T));
t_ok('a different moment fails', !registry_sig_ok('{"a":1}', 'shh', $T + 1, $sig, $T));
t_ok('five minutes late is still fine', registry_sig_ok('{"a":1}', 'shh', $T, $sig, $T + 299));
t_ok('…six is not — a captured request cannot be replayed', !registry_sig_ok('{"a":1}', 'shh', $T, $sig, $T + 301));
t_ok('…and neither is one from the future', !registry_sig_ok('{"a":1}', 'shh', $T + 400, registry_sign('{"a":1}', 'shh', $T + 400), $T));
t_ok('an empty signature or secret fails', !registry_sig_ok('{"a":1}', 'shh', $T, '', $T) && !registry_sig_ok('{"a":1}', '', $T, $sig, $T));
t_ok('a new secret is 48 hex characters', preg_match('/^[a-f0-9]{48}$/', registry_new_secret()) === 1);

t_group('registry: an install that is not configured says nothing');
t_ok('no config, no check-in', !registry_configured() && !empty(registry_checkin()['skipped']));
$sent = [];
$GLOBALS['REGISTRY_TRANSPORT'] = function ($url, $body, $headers, $timeout) use (&$sent, $T) {
    $sent[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
    // Stand in for registry.php itself: verify, store, answer signed.
    $ts = 0; $sig = '';
    foreach ($headers as $h) { if (stripos($h, 'X-Registry-Time:') === 0) $ts = (int)trim(substr($h, 16)); if (stripos($h, 'X-Registry-Sig:') === 0) $sig = trim(substr($h, 15)); }
    if (!registry_sig_ok($body, $GLOBALS['T_SERVER_SECRET'], $ts, $sig, $ts)) return ['{"error":"Forbidden"}', 403, ''];
    $rec   = registry_store(json_decode($body, true), $ts);
    $reply = registry_reply_for($GLOBALS['T_SERVER'], $rec, $ts);
    $reply['sig'] = registry_sign(json_encode($reply, JSON_UNESCAPED_SLASHES), $GLOBALS['T_SERVER_SECRET'], $reply['ts']);
    return [json_encode($reply, JSON_UNESCAPED_SLASHES), 200, ''];
};
$GLOBALS['T_SERVER_SECRET'] = 'fleet-secret';
$GLOBALS['T_SERVER']        = ['secret' => 'fleet-secret', 'update_at' => 0];
$_SERVER['HTTP_HOST'] = 'mail.example.com';
registry_client_save(registry_client() + []);
$c = registry_client(); $c['url'] = 'https://hub.example/registry.php'; $c['secret'] = 'fleet-secret'; $c['auto_update'] = true;
t_ok('configured', registry_client_save($c) && registry_configured());
t_ok('the config file is private — it holds the shared secret', (fileperms(registry_client_file()) & 0777) === 0600);

t_group('registry: what an install says about itself');
$p = registry_payload($T);
t_eq('the host it answers on', $p['host'], 'mail.example.com');
t_ok('an install id is made once and kept', preg_match('/^[a-f0-9]{16}$/', $p['install_id']) === 1 && $p['install_id'] === registry_install_id());
t_ok('php version and the imap fact', $p['php'] === PHP_VERSION && is_bool($p['imap']));
$flat = strtolower(json_encode($p));
t_ok('no address, no password, no token, no message', !preg_match('/@|password|secret|token|subject/', $flat));

t_group('registry: the check-in round trip');
$r = registry_checkin(false, $T);
t_ok('it reported in and was answered', !empty($r['ok']) && empty($r['applied']));
t_ok('the request was signed', (bool)array_filter($sent[0]['headers'], fn($h) => stripos($h, 'X-Registry-Sig:') === 0));
t_eq('the registry now knows one install', count(registry_all($T)['installs']), 1);
$rec = registry_all($T)['installs'][0];
t_eq('…by host', $rec['host'], 'mail.example.com');
t_eq('…and when it was first and last seen', [$rec['first_seen'], $rec['last_seen']], [$T, $T]);
t_eq('the client remembers the moment, with no error', [registry_client()['last_checkin'], registry_client()['last_error']], [$T, '']);
$c = registry_client(); $c['secret'] = 'wrong'; registry_client_save($c);
$r = registry_checkin(false, $T);
t_ok('a wrong secret is refused by the registry, and said so', !empty($r['error']) && str_contains(registry_client()['last_error'], '403'));
$c = registry_client(); $c['secret'] = 'fleet-secret'; registry_client_save($c);

t_group('registry: only the registry may ask for an update');
$GLOBALS['T_SERVER'] = ['secret' => 'fleet-secret', 'update_at' => $T + 10];
$GLOBALS['REGISTRY_TRANSPORT'] = fn($url, $body, $h, $t) => [json_encode(['ok' => true, 'update_at' => $T + 10, 'ts' => $T, 'sig' => 'deadbeef']), 200, ''];
$r = registry_checkin(true, $T);
t_ok('an unsigned instruction is ignored', !empty($r['error']) && str_contains($r['error'], 'not signed'));
$GLOBALS['REGISTRY_TRANSPORT'] = function ($url, $body, $h, $t) use ($T) {
    $reply = ['ok' => true, 'update_at' => $T + 10, 'ts' => $T - 600];   // correctly signed, but long ago
    $reply['sig'] = registry_sign(json_encode(['ok' => true, 'update_at' => $T + 10, 'ts' => $T - 600], JSON_UNESCAPED_SLASHES), 'fleet-secret', $T - 600);
    return [json_encode($reply, JSON_UNESCAPED_SLASHES), 200, ''];
};
$r = registry_checkin(true, $T);
t_ok('a stale instruction is ignored too', !empty($r['error']) && str_contains($r['error'], 'not signed'));

t_group('registry: when an update is actually applied');
$GLOBALS['REGISTRY_TRANSPORT'] = function ($url, $body, $h, $t) use ($T) {
    $reply = ['ok' => true, 'update_at' => $T + 10, 'ts' => $T];
    $reply['sig'] = registry_sign(json_encode($reply, JSON_UNESCAPED_SLASHES), 'fleet-secret', $T);
    return [json_encode($reply, JSON_UNESCAPED_SLASHES), 200, ''];
};
$c = registry_client(); $c['auto_update'] = false; registry_client_save($c);
$r = registry_checkin(true, $T);
t_ok('an install that switched remote updates off is offered and declines', !empty($r['update_offered']) && empty($r['applied']) && str_contains($r['why'], 'switched off'));
t_eq('…and does not record it as acted on, so turning it on later still works', registry_client()['applied_at'], 0);
$c = registry_client(); $c['auto_update'] = true; registry_client_save($c);
$c = registry_client(); $c['auto_update'] = true; $c['applied_at'] = 0; registry_client_save($c);
$r = registry_checkin(true, $T);
t_ok('with the switch on, in the background, the update is applied', !empty($r['applied']) && $GLOBALS['T_APPLIED'] === 1);
t_ok('…and what happened is recorded for the registry to read back', str_contains(registry_client()['last_result'], 'abc1234') && str_contains(registry_client()['last_result'], '42 files'));
t_eq('…and the moment acted on is remembered', registry_client()['applied_at'], $T + 10);
$r = registry_checkin(true, $T);
t_ok('the same request is never applied twice', empty($r['applied']) && $GLOBALS['T_APPLIED'] === 1);
$GLOBALS['REGISTRY_APPLY'] = function () { return ['error' => 'ZipArchive is missing']; };
$c = registry_client(); $c['applied_at'] = 0; registry_client_save($c);
registry_checkin(true, $T);
t_ok('a failed update is reported, not retried in a loop', str_contains(registry_client()['last_result'], 'failed: ZipArchive') && registry_client()['applied_at'] === $T + 10);
$c = registry_client(); $c['auto_update'] = false; $c['applied_at'] = 0; registry_client_save($c);
t_eq('a visitor\'s request only rewrites files where the response can be finished first',
     registry_may_apply(false, 'fpm-fcgi'), function_exists('cron_can_finish_request') ? cron_can_finish_request() : false);
t_ok('a background run always may', registry_may_apply(true, 'fpm-fcgi'));
t_ok('…and so does the command line', registry_may_apply(false, 'cli'));

t_group('registry: the fleet instruction');
$s = ['secret' => 'x', 'update_at' => 0];
t_eq('nothing asked for', registry_reply_for($s, null, $T)['update_at'], 0);
t_eq('a fleet-wide request reaches an install', registry_reply_for(['secret' => 'x', 'update_at' => 500], null, $T)['update_at'], 500);
t_eq('a request aimed at one install reaches it', registry_reply_for($s, ['update_at' => 700], $T)['update_at'], 700);
t_eq('the later of the two wins', registry_reply_for(['secret' => 'x', 'update_at' => 500], ['update_at' => 700], $T)['update_at'], 700);
t_ok('turning this install into a registry makes a secret', ($srv = registry_server_enable('me@x.com')) && strlen($srv['secret']) === 48 && registry_server() !== null);
t_ok('the registry config is private', (fileperms(registry_server_file()) & 0777) === 0600);
t_eq('asking the fleet to update records the moment', registry_request_update('', 'me@x.com', $T + 50), $T + 50);
t_eq('…where every install will read it', registry_server()['update_at'], $T + 50);
$key = registry_all($T)['installs'][0]['key'];
t_eq('asking one install records it on that install only', registry_request_update($key, 'me@x.com', $T + 60), $T + 60);
t_eq('…and is read back', registry_record($key)['update_at'], $T + 60);
t_eq('asking an install nobody has heard of does nothing', registry_request_update('ffffffffffffffff_00000000', '', $T), 0);
t_ok('a registry can be switched off again', registry_server_disable() && registry_server() === null);

t_group('registry: the list, and what it judges');
$now = $T + 100;
$records = [
    ['install_id' => 'a', 'host' => 'old.example',    'version' => '1.9.0',  'last_seen' => $now - 60,   'first_seen' => 1, 'users' => 2],
    ['install_id' => 'b', 'host' => 'current.example','version' => '1.17.0', 'last_seen' => $now - 10,   'first_seen' => 1, 'users' => 1],
    ['install_id' => 'c', 'host' => 'quiet.example',  'version' => '1.17.0', 'last_seen' => $now - 9000, 'first_seen' => 1, 'users' => 0],
];
$sum = registry_summarise($records, $now);
t_eq('the newest version in the fleet is found', $sum['newest'], '1.17.0');
t_eq('newest check-in first', array_column($sum['installs'], 'host'), ['current.example', 'old.example', 'quiet.example']);
t_eq('the older install is marked behind', array_column($sum['installs'], 'behind'), [false, true, false]);
t_eq('the one that stopped reporting is marked quiet', array_column($sum['installs'], 'quiet'), [false, false, true]);
t_ok('1.9.0 really is older than 1.17.0 (not a string comparison)', version_compare('1.9.0', '1.17.0', '<'));

t_group('registry: the same copy on two domains is two installs');
// These are deployed by copying a folder, data/ and all — so the same install
// id turns up on a second domain. Keyed by id alone the two would share a row
// and take turns overwriting it.
$twin = 'aaaaaaaabbbbbbbb';
registry_store(['install_id' => $twin, 'host' => 'first.example',  'version' => '1.18.1'], $T);
registry_store(['install_id' => $twin, 'host' => 'second.example', 'version' => '1.18.1'], $T + 1);
$hosts = array_column(registry_all($T + 2)['installs'], 'host');
t_ok('both domains are listed', in_array('first.example', $hosts, true) && in_array('second.example', $hosts, true));
t_ok('…as separate records', registry_record_key($twin, 'first.example') !== registry_record_key($twin, 'second.example'));
t_ok('…and the same domain twice is still one record', registry_record_key($twin, 'FIRST.example') === registry_record_key($twin, 'first.example'));
t_eq('asking one of them to update leaves the other alone',
     [registry_request_update(registry_record_key($twin, 'first.example'), '', $T + 5) > 0,
      (int)registry_record(registry_record_key($twin, 'second.example'))['update_at']], [true, 0]);
t_ok('a key cannot escape the registry folder', strpos(registry_record_path('../../etc/passwd'), '..') === false);

t_group('registry: a record is never trusted as sent');
t_eq('an id this app never issued is refused outright', registry_store(['install_id' => 'BAD/../id', 'host' => 'x'], $T), null);
t_eq('…including an empty one', registry_store(['host' => 'x'], $T), null);
$rec = registry_store(['install_id' => 'abcdef0123456789', 'host' => "evil\n<script>", 'version' => '1.0<b>', 'php' => '8.4', 'users' => -5, 'scheduler' => 'self!', 'result' => str_repeat('x', 500)], $T);
t_eq('the host is stripped to what a host may contain', $rec['host'], 'evilscript');
t_eq('the version too', $rec['version'], '1.0b');
t_eq('a negative user count cannot be stored', $rec['users'], 0);
t_eq('the scheduler state is one word', $rec['scheduler'], 'self');
t_ok('a long result is cut', strlen($rec['result']) === 160);
t_ok('the id cannot escape the registry folder', strpos(registry_record_path('../../etc/passwd'), '..') === false);

t_group('registry: the check-in is cheap');
$c = registry_client(); $c['last_checkin'] = $T; registry_client_save($c);
t_ok('not due a moment later', !registry_due(registry_client(), $T + 60));
t_ok('due after the interval', registry_due(registry_client(), $T + REGISTRY_INTERVAL));
unset($GLOBALS['REGISTRY_TRANSPORT'], $GLOBALS['REGISTRY_APPLY'], $GLOBALS['T_SERVER'], $GLOBALS['T_SERVER_SECRET'], $GLOBALS['T_APPLIED'], $_SERVER['HTTP_HOST']);
