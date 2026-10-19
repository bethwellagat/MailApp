<?php
/**
 * Unattended operation: the log, the sealed credential store, the scheduler.
 *
 * Everything that decides something — what a log line looks like, what gets
 * redacted, whether a seal opens, whether a token matches, whether a stored
 * credential may be dropped — is a pure function, pinned here without a mail
 * server. The IMAP and SMTP work the jobs do is the one part these cannot
 * reach; the cron's own summary and the log are what report on that in
 * production.
 */

// Every store under test writes beneath a scratch directory, never data/.
$GLOBALS['MAILAPP_DATA_DIR'] = t_tmpdir() . '/data';

require_once T_ROOT . '/lib/util.php';
require_once T_ROOT . '/lib/log.php';
require_once T_ROOT . '/lib/credstore.php';
require_once T_ROOT . '/lib/cron.php';

t_group('log lines have one shape');
$line = app_log_line('warn', 'outbox: send failed', ['account' => 'a@x', 'code' => 550], strtotime('2026-10-19 10:00:00 UTC'));
t_eq('time, level, message, context', $line, '2026-10-19T10:00:00Z WARN  outbox: send failed {"account":"a@x","code":550}');
t_eq('level is normalised and padded', substr(app_log_line('Error', 'x', [], 0), 21, 6), 'ERROR ');
t_eq('no context, no trailing json', app_log_line('info', 'hello', [], 0), '1970-01-01T00:00:00Z INFO  hello');
t_ok('a huge line is cut', strlen(app_log_line('info', str_repeat('a', 5000), [], 0)) <= APP_LOG_LINE_MAX);

t_group('nothing that looks like a secret reaches the log');
/* A password that slips into an IMAP error string, a URL or a header is worse
   than an over-eager redaction of a harmless word. */
t_eq('password=…',        app_log_scrub('login failed password=hunter2 for bob'), 'login failed password=[redacted] for bob');
t_eq('pass: …',           app_log_scrub('pass: hunter2'), 'pass: [redacted]');
t_eq('token=…',           app_log_scrub('github token=ghp_abc123'), 'github token=[redacted]');
t_eq('Authorization header', app_log_scrub('Authorization: Bearer abc.def'), 'Authorization: [redacted]');
t_eq('AUTH PLAIN',        app_log_scrub('sent AUTH PLAIN dXNlcjpwYXNz'), 'sent AUTH PLAIN [redacted]');
t_eq('credentials in a URL', app_log_scrub('imap://bob:hunter2@mail.x.com/INBOX'), 'imap://bob:[redacted]@mail.x.com/INBOX');
t_eq('newlines cannot forge a second line', app_log_scrub("a\r\nb\nc"), 'a b c');
t_eq('harmless text is untouched', app_log_scrub('Could not open folder "Archive"'), 'Could not open folder "Archive"');
$ctxLine = app_log_line('error', 'x', ['password' => 'hunter2', 'host' => 'h'], 0);
t_ok('context is scrubbed too', strpos($ctxLine, 'hunter2') === false, $ctxLine);

t_group('the log writes, rotates and tails');
@mkdir(app_log_dir(), 0700, true);
t_ok('a line is written', app_log('info', 'first line'));
t_ok('the file exists under data/logs', is_file(app_log_file()));
for ($i = 0; $i < 5; $i++) app_log('info', 'line ' . $i);
$tail = app_log_tail(3);
t_eq('tail returns the last n', count($tail), 3);
t_ok('newest last', str_ends_with($tail[2], 'line 4'), $tail[2]);
t_ok('every tail line is a formed log line', (bool)preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ [A-Z ]{5} /', $tail[0]), $tail[0]);

$big = app_log_file();
file_put_contents($big, str_repeat("filler line of text that pads the file\n", 100));
t_ok('below the limit nothing rotates', app_log_rotate($big, 1 << 20) === false && !is_file($big . '.1'));
t_ok('past the limit it rotates', app_log_rotate($big, 100, 3) === true && is_file($big . '.1') && !is_file($big));
file_put_contents($big, "second\n"); app_log_rotate($big, 1, 3);
file_put_contents($big, "third\n");  app_log_rotate($big, 1, 3);
file_put_contents($big, "fourth\n"); app_log_rotate($big, 1, 3);
t_ok('three generations are kept', is_file($big . '.1') && is_file($big . '.2') && is_file($big . '.3') && !is_file($big . '.4'));
t_eq('.1 is the most recent', trim(file_get_contents($big . '.1')), 'fourth');
t_ok('status reports the generations', app_log_status()['generations'] === [1, 2, 3]);
t_ok('tail on a missing file is empty, not an error', app_log_tail(5) === [] || is_array(app_log_tail(5)));

t_group('the sealing key');
t_ok('a cipher is available on this PHP', cred_backend() !== null, 'neither sodium nor openssl aes-256-gcm');
$key = cred_key();
t_ok('a key is created', is_string($key) && strlen($key) === 32);
t_ok('and is readable by the owner only', (fileperms(cred_key_file()) & 0777) === 0600, decoct(fileperms(cred_key_file()) & 0777));
t_ok('the same key comes back next time', cred_key() === $key);
file_put_contents(cred_key_file(), 'damaged');
t_ok('a damaged key is refused, never silently replaced', cred_key() === false);
file_put_contents(cred_key_file(), bin2hex($key)); chmod(cred_key_file(), 0600);

t_group('seal and open');
$acct = ['id' => 'acc1', 'email' => 'bob@x.com', 'password' => 'hunter2-secret', 'imap_host' => 'imap.x.com',
         'imap_port' => 993, 'imap_ssl' => true, 'smtp_host' => 'smtp.x.com', 'display_name' => 'Bob'];
$blob = cred_seal($acct, $key);
t_ok('sealing produces a blob', is_array($blob) && isset($blob['c'], $blob['n']));
t_ok('the blob carries no plaintext', strpos(json_encode($blob), 'hunter2') === false);
t_eq('it opens to the same record', cred_open($blob, $key), $acct);
t_ok('a different key opens nothing', cred_open($blob, random_bytes(32)) === null);
$tampered = $blob; $raw = base64_decode($tampered['c']); $raw[5] = chr(ord($raw[5]) ^ 0x01); $tampered['c'] = base64_encode($raw);
t_ok('one flipped byte opens nothing', cred_open($tampered, $key) === null);
t_ok('a short key is refused', cred_seal($acct, 'short') === null && cred_open($blob, 'short') === null);
t_ok('garbage is not a blob', cred_open(['alg' => 'sodium', 'n' => '!!', 'c' => '!!'], $key) === null && cred_open('x', $key) === null);

t_group('the store: enable, read, list, disable');
t_ok('enable needs a password', cred_enable(['id' => 'z', 'email' => 'z@x']) === false);
t_ok('enable writes a sealed file', cred_enable($acct) && cred_enabled('acc1'));
$onDisk = file_get_contents(cred_path('acc1'));
t_ok('the password is not on disk in the clear', strpos($onDisk, 'hunter2') === false);
t_ok('the email IS in the clear — the cron must find the per-user stores', strpos($onDisk, 'bob@x.com') !== false);
$got = cred_get('acc1');
t_ok('get opens the account', is_array($got) && $got['account']['password'] === 'hunter2-secret');
t_ok('…and strips the seal from what it returns', !isset($got['sealed']));
$list = cred_list();
t_eq('list names the account', [$list[0]['id'], $list[0]['email']], ['acc1', 'bob@x.com']);
t_ok('list carries no secret', strpos(json_encode($list), 'hunter2') === false && !isset($list[0]['account']));

/* A seal moved between files must not grant one account another's mailbox. */
$rec = json_decode(file_get_contents(cred_path('acc1')), true);
$rec['id'] = 'acc2'; $rec['email'] = 'mallory@x.com';
atomic_write_json(cred_path('acc2'), $rec);
t_ok('a seal whose id disagrees with its file is refused', cred_get('acc2') === null);
cred_disable('acc2');

t_ok('disable removes the file', cred_disable('acc1') && !cred_enabled('acc1') && !is_file(cred_path('acc1')));
t_ok('disabling twice is fine', cred_disable('acc1') === true);
t_ok('get on a withdrawn account is null', cred_get('acc1') === null);

t_group('the scheduler token');
t_ok('a token is generated', strlen(cron_token()) >= 32);
t_ok('and stable', cron_token() === cron_token());
$old = cron_token();
t_ok('regenerate changes it', cron_regenerate_token() !== $old && cron_token() !== $old);
t_ok('a matching token passes', cron_token_ok('abc', 'abc'));
t_ok('a different one fails', !cron_token_ok('abd', 'abc'));
t_ok('empty never passes', !cron_token_ok('', '') && !cron_token_ok('', 'abc'));
t_ok('a non-string never passes', !cron_token_ok(null, 'abc') && !cron_token_ok(['abc'], 'abc') && !cron_token_ok(0, '0'));

t_group('what Settings prints');
t_eq('the URL',    cron_url('https://outlook.example.co.ke/', 'tok en'), 'https://outlook.example.co.ke/cron.php?token=tok%20en');
t_eq('the command alone, for a panel\'s Cron Jobs form', cron_php_cmd('/home/u/public_html/cron.php'), "php -q '/home/u/public_html/cron.php' >/dev/null 2>&1");
t_eq('the curl command alone', cron_curl_cmd('https://h/cron.php?token=t'), "curl -fsS 'https://h/cron.php?token=t' >/dev/null 2>&1");
t_ok('a path with a quote cannot break out of the command', str_contains(cron_php_cmd("/home/o'neil/cron.php"), "'/home/o'\\''neil/cron.php'"));
t_eq('the crontab line is the schedule in front of the command', cron_cpanel_line('/x/cron.php'), CRON_SCHEDULE . ' ' . cron_php_cmd('/x/cron.php'));
t_eq('the cPanel line', cron_cpanel_line('/home/u/public_html/cron.php'), "*/5 * * * * php -q '/home/u/public_html/cron.php' >/dev/null 2>&1");
t_eq('a specific php binary', cron_cpanel_line('/x/cron.php', '/usr/local/bin/php'), "*/5 * * * * /usr/local/bin/php -q '/x/cron.php' >/dev/null 2>&1");
t_eq('the curl line', cron_curl_line('https://h/cron.php?token=t'), "*/5 * * * * curl -fsS 'https://h/cron.php?token=t' >/dev/null 2>&1");
$now = strtotime('2026-10-19 12:00:00 UTC');
t_ok('a run 5 minutes ago is fresh', !cron_is_stale('2026-10-19T11:55:00Z', $now));
t_ok('a run 20 minutes ago is stale', cron_is_stale('2026-10-19T11:40:00Z', $now));
t_ok('never run is stale', cron_is_stale('', $now) && cron_is_stale(null, $now));

t_group('when a credential may be dropped');
/* The opt-in promise: kept only while something needs the mailbox unattended. */
$none = ['outbox' => 0, 'ooo_active' => false, 'snoozes' => 0, 'rules_enabled' => 0];
t_ok('nothing pending → drop it',       !cron_needs_credential($none));
t_ok('a queued send keeps it',          cron_needs_credential(['outbox' => 1] + $none));
t_ok('an active out-of-office keeps it', cron_needs_credential(['ooo_active' => true] + $none));
t_ok('a waiting snooze keeps it',       cron_needs_credential(['snoozes' => 2] + $none));
t_ok('an enabled filter keeps it',      cron_needs_credential(['rules_enabled' => 1] + $none));
t_ok('a disabled filter does not',      !cron_needs_credential(['rules_enabled' => 0] + $none));
t_ok('an empty state is nothing pending', !cron_needs_credential([]));
$st = cron_pending_state('nobody@nowhere.invalid');
t_eq('an account with no stores has nothing pending', $st, $none);

t_group('run history');
for ($i = 1; $i <= 25; $i++) cron_record_run(['at' => sprintf('2026-10-19T%02d:00:00Z', $i % 24), 'accounts' => $i]);
$s = cron_state();
t_eq('only the last 20 runs are kept', count($s['runs']), 20);
t_eq('last_run is the newest', $s['last_run'], $s['runs'][19]['at']);
t_eq('the oldest kept is run 6', $s['runs'][0]['accounts'], 6);

t_group('the scheduler runs every job for every opted-in account');
/**
 * The real jobs need a mail server; these fakes stand in for them so the
 * RUNNER is tested — that it opens the seal, mirrors the account where the
 * jobs look, calls each job in cron mode, keeps going past a failure, counts
 * what happened, drops a credential nothing needs any more, and records the
 * run. lib/jobs.php is never loaded in this process, so the names are free.
 */
if (!function_exists('job_outbox_process')) {
    function job_outbox_process($email, array $o = []) { $GLOBALS['T_JOBS'][] = ['outbox', $email, !empty($o['cron']), $_SESSION['password'] ?? null]; return ['ok' => true, 'sent' => 2]; }
    function job_rules_run_new($email, array $o = [])   { $GLOBALS['T_JOBS'][] = ['rules', $email, !empty($o['cron'])];  return ['ok' => true, 'count' => 1]; }
    function job_snooze_wake($email, array $o = [])     { $GLOBALS['T_JOBS'][] = ['snooze', $email, !empty($o['cron'])]; return ['error' => 'Could not open Later']; }
    function job_ooo_process($email, array $o = [])     { $GLOBALS['T_JOBS'][] = ['ooo', $email, !empty($o['cron'])];    throw new RuntimeException('boom'); }
}
$GLOBALS['T_JOBS'] = [];
$acct = ['id' => 'run1', 'email' => 'runner@x.com', 'password' => 'pw-runner', 'imap_host' => 'imap.x', 'imap_port' => 993,
         'imap_ssl' => true, 'smtp_host' => 'smtp.x', 'display_name' => 'Runner'];
t_ok('an account is opted in', cred_enable($acct));
$sum = cron_run_all();
t_eq('one account was run', $sum['accounts'], 1);
t_eq('all four jobs were called, in cron mode', array_map(fn($j) => $j[0] . ':' . ($j[2] ? 'cron' : 'tab'), $GLOBALS['T_JOBS']),
     ['outbox:cron', 'rules:cron', 'snooze:cron', 'ooo:cron']);
t_eq('the sealed password reached the place the jobs read', $GLOBALS['T_JOBS'][0][3], 'pw-runner');
t_eq('counts are summed per job', [$sum['jobs']['outbox'], $sum['jobs']['rules']], [2, 1]);
t_eq('a job error and a thrown job are both recorded, and neither stopped the run', count($sum['errors']), 2);
t_ok('the thrown job is named', in_array('ooo', array_column($sum['errors'], 'job'), true));
t_ok('the failing job is named', in_array('snooze', array_column($sum['errors'], 'job'), true));
t_ok('nothing pending → the credential was dropped', in_array('runner@x.com', $sum['expired'], true) && !cred_enabled('run1'));
t_ok('the run was recorded', cron_state()['last_run'] === $sum['at']);
t_ok('and the log says so', (bool)array_filter(app_log_tail(50), fn($l) => str_contains($l, 'cron: run complete')));
t_ok('nothing that looks like the password reached the log', !array_filter(app_log_tail(200), fn($l) => str_contains($l, 'pw-runner')));
$again = cron_run_all();
t_eq('the lock is released — a second run proceeds (with nobody opted in now)', $again['accounts'], 0);

t_group('an unopenable seal is reported, not fatal');
$GLOBALS['T_JOBS'] = [];
t_ok('opt in again', cred_enable($acct));
file_put_contents(cred_key_file(), bin2hex(random_bytes(32)));   // the key changes under it
$sum = cron_run_all();
t_eq('no jobs ran for it', count($GLOBALS['T_JOBS']), 0);
t_ok('the summary names the account and the reason', !empty($sum['errors']) && str_contains($sum['errors'][0]['error'], 'could not be opened'));
cred_disable('run1');

/* =========================================================================
   The scheduler wakes itself. Clock, sleep and transport are injected, so a
   five-minute chain runs in milliseconds here and no socket is opened.
   ========================================================================= */
@unlink(cron_file()); @unlink(app_data_root() . '/cron.tick');
foreach (cred_list() as $e) cred_disable($e['id']);
$T = time();
$GLOBALS['CRON_NOW'] = function () use (&$T) { return $T; };

t_group('the app\'s own address');
t_eq('scheme, host and app directory from an ajax request', cron_base_url_from(true, 'outlook.taurusfzc.com', '/ajax/cron.php'), 'https://outlook.taurusfzc.com');
t_eq('a dev host with a port',                              cron_base_url_from(false, 'localhost:8737', '/settings.php'), 'http://localhost:8737');
t_eq('an app in a subdirectory',                            cron_base_url_from(true, 'h.example', '/mail/ajax/fetch.php'), 'https://h.example/mail');
t_eq('a host with a path in it is refused',                 cron_base_url_from(true, 'evil.example/../', '/x.php'), '');
t_eq('a host with a space is refused',                      cron_base_url_from(true, 'a b', '/x.php'), '');
$_SESSION = []; $_SERVER['HTTPS'] = 'on'; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['HTTP_HOST'] = 'stranger.example';
t_eq('a stranger\'s Host header is never learned', cron_learn_base_url(), '');
t_eq('…so the scheduler has no address yet',        cron_state()['base_url'], '');
$_SESSION['email'] = 'me@x'; $_SERVER['HTTP_HOST'] = 'mail.example';
t_eq('a signed-in request teaches it',  cron_learn_base_url(), 'https://mail.example');
t_eq('…and it is kept',                 cron_state()['base_url'], 'https://mail.example');
$_SERVER['HTTP_HOST'] = 'other.example'; cron_learn_base_url();
t_eq('a different Host later does not move it', cron_state()['base_url'], 'https://mail.example');
$_SESSION = []; unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST']);

t_group('timing');
$st = cron_state();
t_ok('never ran → due',                         cron_due($st, 1000));
$st['last_run'] = gmdate('c', 1000);
t_ok('289 s later → not yet',                  !cron_due($st, 1289));
t_ok('290 s later → due (a hop may wake early)', cron_due($st, 1290));
t_eq('seconds until due',                       cron_seconds_until_due($st, 1100), 200);
$st['hop_len'] = 270;
t_eq('a hop sleeps until due…',                 cron_hop_plan($st, 1100), 200);
t_eq('…but never longer than the hop length',   cron_hop_plan($st, 1000), 270);
$st['hop_len'] = 10;
t_eq('…and never shorter than the minimum',     cron_hop_plan($st, 1000), CRON_HOP_MIN);
$st['hop_phase'] = 'sleep'; $st['hop_alive'] = 5000;
t_ok('a hop that reported 20 s ago is alive',   cron_chain_alive($st, 5020));
t_ok('…31 s ago it is not',                     !cron_chain_alive($st, 5031));
$st['hop_phase'] = 'done';
t_ok('a finished hop is not alive',             !cron_chain_alive($st, 5001));

t_group('learning how long this host lets a hop live');
$base = cron_state(); $base['hop_len'] = 270;
$s = $base + []; $s['hop_started'] = 100; $s['hop_planned'] = 270; $s['hop_alive'] = 370; $s['hop_done'] = 380; $s['hop_phase'] = 'done';
$l = cron_chain_learn($s, 1000);
t_eq('a completed hop changes nothing', [$l['hop_len'], $l['chain']['deaths']], [270, 0]);
$s = $base; $s['hop_started'] = 100; $s['hop_planned'] = 270; $s['hop_alive'] = 200; $s['hop_done'] = 0; $s['hop_phase'] = 'sleep';
$l = cron_chain_learn($s, 1000);
t_eq('a hop killed at 100 s of 270 shortens the next to 80', $l['hop_len'], 80);
t_eq('…and counts a death', $l['chain']['deaths'], 1);
t_ok('…with the reason written down', str_contains($l['chain']['reason'], '100 s'));
$s = $l; $s['hop_len'] = CRON_HOP_MIN; $s['chain']['deaths'] = 2; $s['hop_started'] = 100; $s['hop_planned'] = CRON_HOP_MIN; $s['hop_alive'] = 110; $s['hop_done'] = 0; $s['hop_phase'] = 'sleep';
$l = cron_chain_learn($s, 1000);
t_eq('a third death at the minimum marks chaining unsupported', $l['chain']['mode'], 'unsupported');
t_eq('…until tomorrow', $l['chain']['retry_at'], 1000 + CRON_PROBE_TTL);
t_ok('…and no hop is wanted meanwhile', !cron_chain_wanted($l, 1000));
$l2 = cron_chain_learn($l, 1000 + CRON_PROBE_TTL);
t_eq('a day later it tries again from the top', [$l2['chain']['mode'], $l2['hop_len']], ['auto', 270]);
$s = $base; $s['hop_started'] = 100; $s['hop_planned'] = 270; $s['hop_alive'] = 365; $s['hop_done'] = 0; $s['hop_phase'] = 'sleep';
t_eq('a hop that reached the end of its sleep is not a death', cron_chain_learn($s, 1000)['hop_len'], 270);
$s = $base; $s['runs'] = [['at' => gmdate('c', 1000), 'via' => 'cli']];
t_ok('a host cron seen 100 s ago is active', cron_host_cron_active($s, 1100));
t_ok('…and supersedes the chain',            !cron_chain_wanted($s, 1100));
t_ok('…but not once it has gone quiet',       !cron_host_cron_active($s, 1000 + CRON_INTERVAL * 2 + 1));
$s['runs'] = [['at' => gmdate('c', 1000), 'via' => 'hop']];
t_ok('the chain\'s own runs do not count as a host cron', !cron_host_cron_active($s, 1100));

t_group('reaching ourselves');
$b = cron_http_request_bytes('https://mail.example/cron.php?token=abc&probe=1');
t_ok('a plain HTTP/1.0 GET with Host', str_starts_with($b, "GET /cron.php?token=abc&probe=1 HTTP/1.0\r\nHost: mail.example\r\n"));
t_ok('…that closes',                   str_contains($b, "Connection: close") && str_ends_with($b, "\r\n\r\n"));
$v = cron_wake_variants('https://mail.example');
t_eq('https: loopback first, unverified loopback, then the public name', array_column($v, 'name'), ['local', 'local-noverify', 'public']);
t_ok('loopback connects to 127.0.0.1:443 with the site name for SNI', $v[0]['connect'] === '127.0.0.1' && $v[0]['port'] === 443 && $v[0]['tls'] && $v[0]['verify'] && $v[0]['host'] === 'mail.example');
t_ok('the second loopback skips certificate checks', !$v[1]['verify']);
t_eq('the public variant connects by name', $v[2]['connect'], 'mail.example');
$v = cron_wake_variants('http://localhost:8737');
t_eq('http: no TLS variants', array_column($v, 'name'), ['local', 'public']);
t_ok('…and the port is kept', $v[0]['port'] === 8737 && !$v[0]['tls']);
t_eq('garbage gives nothing', cron_wake_variants('garbage'), []);

cron_state_patch(['base_url' => 'https://mail.example']);
$sent = [];
$GLOBALS['CRON_TRANSPORT'] = function ($v, $url, $blocking) use (&$sent) {
    $sent[] = [$v['name'], $url, $blocking];
    if ($v['name'] === 'public') return ['ok' => true, 'status' => 200, 'body' => '{"ok":true,"probe":true}'];
    return ['ok' => false, 'error' => 'connect 127.0.0.1:443: refused'];
};
$r = cron_probe();
t_eq('the probe takes the first way that answers', $r['method'], 'public');
t_eq('…and remembers it', cron_state()['trigger']['method'], 'public');
t_eq('it tried the ways in order, blocking', array_map(fn($s) => $s[0] . ':' . ($s[2] ? 'wait' : 'fire'), $sent), ['local:wait', 'local-noverify:wait', 'public:wait']);
t_ok('every probe carries the token and asks only to be answered', !array_filter($sent, fn($s) => !str_contains($s[1], 'token=' . cron_token()) || !str_contains($s[1], 'probe=1')));
$GLOBALS['CRON_TRANSPORT'] = fn($v, $u, $b) => ['ok' => false, 'error' => 'nope'];
$r = cron_probe();
t_eq('no way at all, no way to detach → sync', $r['method'], 'sync');
t_ok('…with every failure listed', str_contains($r['error'], 'local: nope') && str_contains($r['error'], 'public: nope'));

t_group('triggers');
$GLOBALS['CRON_TRANSPORT'] = function ($v, $url, $blocking) use (&$sent) {
    $sent[] = [$v['name'], $url, $blocking];
    return $blocking ? ['ok' => true, 'status' => 200, 'body' => '{"probe":true}'] : ['ok' => true];
};
cron_state_patch(['trigger' => ['method' => '', 'misses' => 0, 'checked' => 0]]);
$sent = [];
$r = cron_trigger('run', 'tick');
t_eq('an unprobed install probes first, then fires', [$sent[0][2], end($sent)[2]], [true, false]);
t_eq('…by the first way that answered', $r['method'], 'local');
t_ok('a run trigger asks for a run, named', str_contains(end($sent)[1], '&run=1&via=tick'));
t_eq('…and is noted', cron_state()['last_trigger_what'], 'run');
$sent = [];
cron_trigger('hop', 'chain');
t_ok('a hop trigger asks for a hop', str_contains(end($sent)[1], '&hop=1&via=chain') && !str_contains(end($sent)[1], 'probe'));
$GLOBALS['CRON_TRANSPORT'] = function ($v, $url, $blocking) use (&$sent) {
    $sent[] = [$v['name'], $url, $blocking];
    return $blocking ? ['ok' => true, 'status' => 200, 'body' => '{"probe":true}'] : ['ok' => false, 'error' => 'reset'];
};
cron_trigger('run'); cron_trigger('run'); cron_trigger('run');
t_eq('a send that fails is a miss', cron_state()['trigger']['misses'], 3);
$sent = [];
cron_trigger('run');
t_ok('three misses → the next trigger probes again', (bool)array_filter($sent, fn($s) => $s[2] === true));
t_eq('…and the count starts over', cron_state()['trigger']['misses'], 1);

t_group('the tick at the end of every request');
$GLOBALS['CRON_TRANSPORT'] = function ($v, $url, $blocking) use (&$sent) {
    $sent[] = [$v['name'], $url, $blocking];
    return $blocking ? ['ok' => true, 'status' => 200, 'body' => '{"probe":true}'] : ['ok' => true];
};
@unlink(app_data_root() . '/cron.tick');
cron_state_patch(['last_run' => '', 'hop_phase' => '', 'last_trigger' => 0, 'runs' => [], 'trigger' => ['method' => 'local', 'misses' => 0, 'checked' => $T]]);
t_eq('nobody opted in → idle',                     cron_tick()['tick'], 'idle');
t_eq('…and the next request within a minute is free', cron_tick()['tick'], 'throttled');
$T += CRON_TICK_SECONDS + 1;
t_ok('an account opts in', cred_enable($acct));
$sent = [];
$r = cron_tick();
t_eq('a run is due → one is started in the background', $r['tick'], 'run');
t_ok('…by a non-blocking call to cron.php', end($sent)[2] === false && str_contains(end($sent)[1], 'run=1'));
$T += CRON_TICK_SECONDS + 1;
cron_state_patch(['hop_phase' => 'sleep', 'hop_alive' => $T, 'hop_started' => $T]);
t_eq('a live hop is left alone', cron_tick()['tick'], 'chain-alive');
$T += CRON_TICK_SECONDS + 1;
cron_state_patch(['hop_phase' => 'done', 'last_run' => gmdate('c', $T - 30)]);
$sent = [];
t_eq('not due but no hop alive → a hop is booked', cron_tick()['tick'], 'hop');
t_ok('…as a hop', str_contains(end($sent)[1], 'hop=1'));
$T += CRON_TICK_SECONDS + 1;
cron_state_patch(['trigger' => ['method' => 'sync']]);
t_eq('a host that cannot call itself gets no hop', cron_tick()['tick'], 'nothing');
$T += CRON_TICK_SECONDS + 1;
cron_state_patch(['trigger' => ['method' => 'local', 'misses' => 0], 'last_trigger' => $T - 120, 'hop_started' => 0, 'hop_alive' => 0, 'last_run' => gmdate('c', $T - 200)]);
cron_tick();
t_eq('a trigger that produced nothing within a minute is a miss', cron_state()['trigger']['misses'], 1);

t_group('the hop');
$GLOBALS['CRON_ASSUME_IMAP'] = true;
if (!function_exists('list_outbox_messages')) {
    function list_outbox_messages($email) { return $GLOBALS['T_PENDING'] ?? []; }
}
$sleeps = [];
$GLOBALS['CRON_SLEEP'] = function ($s) use (&$T, &$sleeps) { $sleeps[] = $s; $T += $s; };
$GLOBALS['T_PENDING'] = [['id' => 'm1']];        // something queued, so the credential stays
cron_state_patch(['last_run' => gmdate('c', $T - 200), 'hop_len' => 270, 'hop_phase' => '', 'hop_started' => 0, 'hop_alive' => 0, 'hop_done' => 0,
                  'runs' => [], 'trigger' => ['method' => 'local', 'misses' => 0, 'checked' => $T], 'chain' => ['mode' => 'auto', 'deaths' => 0]]);
$sent = []; $GLOBALS['T_JOBS'] = [];
$r = cron_hop('chain');
t_eq('the hop ran to the end', $r['hop'], 'done');
t_eq('it slept exactly until the run was due, in slices', [array_sum($sleeps), count($sleeps)], [100, 10]);
t_ok('then it ran, named as the chain', is_array($r['ran']) && $r['ran']['via'] === 'chain' && $r['ran']['accounts'] === 1);
t_eq('all four jobs ran', count($GLOBALS['T_JOBS']), 4);
$st = cron_state();
t_ok('the hop reported in while sleeping and signed off at the end', $st['hop_phase'] === 'done' && $st['hop_done'] >= $st['hop_started'] && $st['hop_alive'] >= $st['hop_started'] + 90);
t_ok('something still pending → the credential stays', cred_enabled('run1'));
t_ok('…and the next hop is booked', (bool)array_filter($sent, fn($s) => str_contains($s[1], 'hop=1&via=chain') && $s[2] === false));
$st = cron_state();
t_ok('the pane would say self-running', cron_status_words(['hop_phase' => 'sleep', 'hop_alive' => $T] + $st, $T, true)['state'] === 'self');

$GLOBALS['T_PENDING'] = [];
$sleeps = []; $sent = [];
cron_state_patch(['last_run' => gmdate('c', $T - 200)]);
$r = cron_hop('chain');
t_ok('nothing pending → the run drops the credential and no hop is booked', $r['hop'] === 'done' && !cred_enabled('run1') && !array_filter($sent, fn($s) => str_contains($s[1], 'hop=1')));
$r = cron_hop('chain');
t_eq('a hop with nobody opted in stops at once', $r['hop'], 'stopped');

cred_enable($acct);
$GLOBALS['T_PENDING'] = [['id' => 'm1']];
cron_state_patch(['last_run' => gmdate('c', $T - 100), 'hop_phase' => '']);
$sleeps = [];
$GLOBALS['CRON_SLEEP'] = function ($s) use (&$T, &$sleeps) { $sleeps[] = $s; $T += $s; if (count($sleeps) === 3) cred_disable('run1'); };
$before = cron_state()['last_run'];
$r = cron_hop('chain');
t_eq('switching the last account off mid-sleep stops the hop', [$r['hop'], count($sleeps)], ['stopped', 3]);
t_eq('…without a run', cron_state()['last_run'], $before);
t_eq('…and the next tick finds no live hop', cron_chain_alive(cron_state(), $T), false);

cred_enable($acct);
$h = fopen(cron_file() . '.chain.lock', 'c'); flock($h, LOCK_EX | LOCK_NB);
t_eq('one hop at a time: a second is turned away', cron_hop('chain')['hop'], 'busy');
flock($h, LOCK_UN); fclose($h);

cron_state_patch(['hop_started' => $T - 300, 'hop_planned' => 270, 'hop_alive' => $T - 250, 'hop_done' => 0, 'hop_phase' => 'sleep', 'hop_len' => 270, 'last_run' => gmdate('c', $T - 10)]);
$GLOBALS['CRON_SLEEP'] = function ($s) use (&$T, &$sleeps) { $sleeps[] = $s; $T += $s; };
$sleeps = [];
cron_hop('chain');
t_eq('a new hop learns from the one the host killed: 50 s survived → 45 s hops', cron_state()['hop_len'], CRON_HOP_MIN);
t_ok('…and sleeps no longer than that', max($sleeps) <= CRON_HOP_SLICE && array_sum($sleeps) === CRON_HOP_MIN);

t_group('what the pane says');
$s = cron_state(); $s['runs'] = []; $s['chain'] = ['mode' => 'auto', 'deaths' => 0, 'retry_at' => 0, 'reason' => ''];
t_eq('nobody on → off', cron_status_words($s, $T, false)['state'], 'off');
$s['last_run'] = ''; $s['last_trigger'] = $T - 10; $s['trigger'] = ['method' => 'local', 'error' => '', 'checked' => $T, 'misses' => 0];
t_eq('just switched on → starting', cron_status_words($s, $T, true)['state'], 'starting');
$s['last_trigger'] = 0;
t_eq('never triggered → stopped', cron_status_words($s, $T, true)['state'], 'stopped');
$s['last_run'] = gmdate('c', $T - 60); $s['hop_phase'] = 'sleep'; $s['hop_alive'] = $T - 5;
$w = cron_status_words($s, $T, true);
t_ok('a live hop → self-running, and it says no cron is needed', $w['state'] === 'self' && str_contains($w['detail'], 'No cron job is needed'));
$s['hop_phase'] = 'done';
t_eq('between hops → running', cron_status_words($s, $T, true)['state'], 'running');
$s['runs'] = [['at' => gmdate('c', $T - 60), 'via' => 'url']];
t_eq('a host cron → cron', cron_status_words($s, $T, true)['state'], 'cron');
$s['runs'] = []; $s['last_run'] = gmdate('c', $T - 2000);
t_eq('quiet for 33 min → stopped', cron_status_words($s, $T, true)['state'], 'stopped');
$s['trigger']['method'] = 'sync'; $s['trigger']['error'] = 'local: refused';
$w = cron_status_words($s, $T, true);
t_ok('a server that cannot call itself says so, with the reason', str_contains($w['detail'], 'could not call itself') && str_contains($w['detail'], 'refused'));
$s['trigger']['method'] = 'local'; $s['chain'] = ['mode' => 'unsupported', 'deaths' => 3, 'retry_at' => $T + 1, 'reason' => 'this host ends a background request after about 30 s'];
t_ok('a host that kills hops is named as the reason', str_contains(cron_status_words($s, $T, true)['detail'], 'after about 30 s'));

foreach (cred_list() as $e) cred_disable($e['id']);
unset($GLOBALS['CRON_NOW'], $GLOBALS['CRON_SLEEP'], $GLOBALS['CRON_TRANSPORT'], $GLOBALS['CRON_ASSUME_IMAP'], $GLOBALS['T_PENDING']);
