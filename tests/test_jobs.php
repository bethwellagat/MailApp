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
