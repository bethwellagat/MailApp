<?php
/**
 * Self-update: the copy must be transactional.
 *
 * A failure part-way through used to leave the app running half of one release
 * and half of another, which white-screens every tenant on the host. These tests
 * inject a REAL mid-copy failure and assert the tree comes back byte-for-byte —
 * and, critically, that the tree was genuinely spliced first, or the rollback
 * assertion would prove nothing.
 */

require_once T_ROOT . '/lib/updater.php';
require_once T_ROOT . '/lib/mailer.php';   // smtp_is_tls_error lives here

$EXCLUDE = ['data', '.git', '.github', '.gitignore', 'tests', 'cgi-bin', '.well-known'];

function t_put($p, $c) { @mkdir(dirname($p), 0755, true); file_put_contents($p, $c); }
function t_snapshot($dir) {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $out[substr($f->getPathname(), strlen($dir) + 1)] = md5_file($f->getPathname());
    ksort($out);
    return $out;
}
function t_build($app, $rel, $poison = false) {
    t_put("$app/inbox.php",            "OLD inbox\n");
    t_put("$app/version.php",          "OLD version\n");
    t_put("$app/assets/app.js",        "OLD appjs\n");
    t_put("$app/assets/style.css",     "OLD css\n");
    t_put("$app/lib/session.php",      "OLD session\n");
    t_put("$app/data/prefs/user.json", "PRECIOUS USER DATA\n");
    t_put("$rel/inbox.php",            "NEW inbox\n");
    t_put("$rel/version.php",          "NEW version\n");
    t_put("$rel/assets/app.js",        "NEW appjs\n");
    t_put("$rel/assets/style.css",     "NEW css\n");
    t_put("$rel/lib/session.php",      "NEW session\n");
    t_put("$rel/lib/brandnew.php",     "NEW file added by this release\n");
    t_put("$rel/data/prefs/user.json", "REPO PLACEHOLDER - MUST NOT OVERWRITE\n");
    if ($poison) { t_put("$rel/zz_poison.php", "unreadable\n"); chmod("$rel/zz_poison.php", 0000); }
}

$base = t_tmpdir() . '/upd';

t_group('successful update');
$app = "$base/c1/app"; $rel = "$base/c1/rel"; $bak = "$base/c1/backup";
t_build($app, $rel); @mkdir($bak, 0700, true);
$journal = [];
[$copied, $err] = _update_copy_tree($rel, $app, $EXCLUDE, $bak, $journal);
t_ok('no error',              $err === null, (string)$err);
t_ok('files were copied',     $copied === 6, "copied=$copied");
t_eq('inbox.php replaced',    trim((string)@file_get_contents("$app/inbox.php")), 'NEW inbox');
t_ok('new file created',      is_file("$app/lib/brandnew.php"));
t_eq('USER DATA UNTOUCHED',   trim((string)@file_get_contents("$app/data/prefs/user.json")), 'PRECIOUS USER DATA');

t_group('failure part-way through is rolled back');
$app = "$base/c2/app"; $rel = "$base/c2/rel"; $bak = "$base/c2/backup";
t_build($app, $rel, true); @mkdir($bak, 0700, true);
$before = t_snapshot($app);
$journal = [];
[$copied, $err] = _update_copy_tree($rel, $app, $EXCLUDE, $bak, $journal);
t_ok('copy reported an error',        $err !== null);
t_ok('some files were replaced first', $copied > 0, "copied=$copied");
t_ok('tree WAS spliced (test is meaningful)', t_snapshot($app) !== $before);
$failed = _update_rollback($journal, $bak, $app);
t_ok('rollback reported no failures', $failed === 0, "failed=$failed");
t_eq('tree restored byte-for-byte',   t_snapshot($app), $before);
t_ok('added file removed again',      !is_file("$app/lib/brandnew.php"));
t_eq('USER DATA still untouched',     trim((string)@file_get_contents("$app/data/prefs/user.json")), 'PRECIOUS USER DATA');

t_group('a file is never replaced without a backup');
$app = "$base/c3/app"; $rel = "$base/c3/rel"; $bak = "$base/c3/backup";
t_build($app, $rel); @mkdir($bak, 0500, true); chmod($bak, 0500); // unwritable backup
$before = t_snapshot($app);
$journal = [];
[$copied, $err] = _update_copy_tree($rel, $app, $EXCLUDE, $bak, $journal);
t_ok('aborted with an error',   $err !== null, (string)$err);
t_ok('error names the backup',  $err !== null && stripos((string)$err, 'back') !== false, (string)$err);
t_eq('nothing was changed',     t_snapshot($app), $before);
chmod($bak, 0700);

t_group('SMTP TLS error classification drives the relaxed-cert fallback');
foreach (['SSL: certificate verify failed', 'self-signed certificate in chain',
          'unable to get local issuer certificate', 'Peer certificate CN mismatch'] as $e) {
    t_ok('certificate error matches: ' . substr($e, 0, 34), smtp_is_tls_error($e) === true);
}
// These must NOT match: a server that is merely unreachable, or refuses STARTTLS,
// would otherwise be recorded as needing relaxed TLS — disabling certificate
// verification install-wide for BOTH protocols.
foreach (['connect: Connection refused', 'AUTH 535 bad credentials',
          'STARTTLS 502', 'TLS upgrade failed', 'EHLO2 421'] as $e) {
    t_ok('not a certificate error: ' . substr($e, 0, 34), smtp_is_tls_error($e) === false);
}

t_group('pruning files removed upstream — must only ever delete what WE deployed');
$app = "$base/c4/app";
t_put("$app/inbox.php",             "x");
t_put("$app/lib/old_removed.php",   "superseded code that still answers requests");
t_put("$app/assets/app.js",         "x");
t_put("$app/customer_custom.php",   "a file the customer added themselves");
t_put("$app/data/prefs/user.json",  "PRECIOUS USER DATA");

$prev    = ['inbox.php', 'lib/old_removed.php', 'assets/app.js'];  // what we shipped last time
$current = ['inbox.php', 'assets/app.js'];                          // new release drops one
$removed = _update_prune($app, $prev, $current, $EXCLUDE);
t_ok('removed the file dropped upstream', $removed === 1 && !is_file("$app/lib/old_removed.php"), "removed=$removed");
t_ok('kept files still in the release',   is_file("$app/inbox.php") && is_file("$app/assets/app.js"));
t_ok('NEVER touches a customer file',     is_file("$app/customer_custom.php"));
t_ok('NEVER touches user data',           is_file("$app/data/prefs/user.json"));

// No manifest recorded yet (first update after this ships) must prune nothing.
t_put("$app/lib/another.php", "y");
t_eq('no previous manifest prunes nothing', _update_prune($app, [], ['inbox.php'], $EXCLUDE), 0);
t_ok('...and leaves the file alone', is_file("$app/lib/another.php"));

// Path escapes must be refused outright.
t_put("$app/../ESCAPE_TARGET.txt", "must survive");
$escapes = ['../ESCAPE_TARGET.txt', '/etc/hosts', 'data/prefs/user.json', '', 'lib/../../ESCAPE_TARGET.txt'];
t_eq('every path escape refused', _update_prune($app, $escapes, [], $EXCLUDE), 0);
t_ok('parent-directory file untouched', is_file("$app/../ESCAPE_TARGET.txt"));
t_ok('data/ file untouched',            is_file("$app/data/prefs/user.json"));

t_group('manifest generation');
$rel = "$base/c5/rel";
t_put("$rel/inbox.php", "x"); t_put("$rel/lib/a.php", "x"); t_put("$rel/assets/b.css", "x");
t_put("$rel/data/should_not_list.json", "x"); t_put("$rel/tests/t.php", "x");
$m = _update_file_list($rel, $EXCLUDE);
t_eq('lists shipped files only', $m, ['assets/b.css', 'inbox.php', 'lib/a.php']);
