<?php
/**
 * Automatic updates: an install follows the repository on its own.
 * GitHub and the updater are both behind seams, armed before anything runs —
 * a test that could rewrite the working tree is a test nobody can safely run.
 */
$GLOBALS['MAILAPP_DATA_DIR'] = t_tmpdir() . '/data';
@mkdir($GLOBALS['MAILAPP_DATA_DIR'], 0700, true);   // this file runs first; nothing has made it yet
require_once T_ROOT . '/lib/autoupdate.php';
$T = 1792400000;
$GLOBALS['T_AUTO'] = ['checks' => 0, 'applies' => 0];
$GLOBALS['AUTOUPDATE_CHECK'] = function () { $GLOBALS['T_AUTO']['checks']++; return $GLOBALS['T_CHECK']; };
$GLOBALS['AUTOUPDATE_APPLY'] = function () { $GLOBALS['T_AUTO']['applies']++; return $GLOBALS['T_APPLY']; };
$GLOBALS['T_CHECK'] = ['ok' => true, 'latest' => str_repeat('b', 40), 'current' => str_repeat('a', 40)];
$GLOBALS['T_APPLY'] = ['ok' => true, 'files' => 12, 'version' => str_repeat('b', 40)];

t_group('auto-update: the decision');
$blank = auto_state();
$a = str_repeat('a', 40); $b = str_repeat('b', 40);
t_ok('a newer release is applied',        auto_decide($a, $b, $blank, $T)['apply']);
t_ok('the one already running is not',   !auto_decide($a, $a, $blank, $T)['apply']);
t_eq('…and says so',                      auto_decide($a, $a, $blank, $T)['why'], 'already running it');
t_ok('an unreachable repository is not a reason to do anything', !auto_decide($a, '', $blank, $T)['apply']);
t_ok('an install with no recorded build takes what it is offered', auto_decide('', $b, $blank, $T)['apply']);
$failed = $blank; $failed['failed_sha'] = $b; $failed['failed_at'] = $T;
t_ok('a release that failed here is left alone for a while', !auto_decide($a, $b, $failed, $T + 60)['apply']);
t_ok('…and tried again after the hour',    auto_decide($a, $b, $failed, $T + UPDATE_AUTO_RETRY + 1)['apply']);
t_ok('…while a newer one is never held back', auto_decide($a, str_repeat('c', 40), $failed, $T + 60)['apply']);

t_group('auto-update: following the repository');
t_ok('on by default — an install that was never told otherwise keeps itself current', auto_enabled());
$r = auto_tick(true, $T);
t_ok('it updated itself', !empty($r['applied']) && $GLOBALS['T_AUTO']['applies'] === 1);
t_ok('…and wrote down what it is now running', str_contains(auto_state()['last_result'], 'updated to bbbbbbb') && str_contains(auto_state()['last_result'], '12 files'));
$r = auto_tick(true, $T + 60);
t_eq('it does not ask GitHub again a minute later', $r['skipped'], 'checked recently');
t_eq('…and did not ask', $GLOBALS['T_AUTO']['checks'], 1);
$GLOBALS['T_CHECK'] = ['ok' => true, 'latest' => $b, 'current' => $b];
$r = auto_tick(true, $T + UPDATE_AUTO_INTERVAL + 1);
t_ok('a quarter of an hour later it looks again, and finds nothing to do', empty($r['applied']) && $GLOBALS['T_AUTO']['checks'] === 2 && $GLOBALS['T_AUTO']['applies'] === 1);

t_group('auto-update: a release that will not install');
$c = str_repeat('c', 40);
$GLOBALS['T_CHECK'] = ['ok' => true, 'latest' => $c, 'current' => $b];
$GLOBALS['T_APPLY'] = ['error' => 'ZipArchive is missing'];
$now = $T + 2 * UPDATE_AUTO_INTERVAL + 2;
$r = auto_tick(true, $now);
t_ok('the failure is reported, not hidden', empty($r['applied']) && str_contains(auto_state()['last_result'], 'failed: ZipArchive'));
t_eq('…and remembered against that release', auto_state()['failed_sha'], $c);
$before = $GLOBALS['T_AUTO']['applies'];
auto_tick(true, $now + UPDATE_AUTO_INTERVAL + 1);
t_eq('it is not retried every quarter of an hour', $GLOBALS['T_AUTO']['applies'], $before);
$GLOBALS['T_APPLY'] = ['ok' => true, 'files' => 3, 'version' => $c];
auto_tick(true, $now + UPDATE_AUTO_RETRY + UPDATE_AUTO_INTERVAL + 2);
t_eq('…but it is tried again after the hour', $GLOBALS['T_AUTO']['applies'], $before + 1);
t_eq('and a success clears the mark', auto_state()['failed_sha'], '');

t_group('auto-update: where it is allowed to run');
t_ok('a background run may',                auto_may_apply(true, 'fpm-fcgi'));
t_ok('the command line may',                auto_may_apply(false, 'cli'));
t_eq('a visitor\'s request only where the response can be finished first',
     auto_may_apply(false, 'fpm-fcgi'), function_exists('cron_can_finish_request') ? cron_can_finish_request() : false);
$GLOBALS['T_AUTO']['checks'] = 0;
t_eq('an unsafe moment asks nothing at all', auto_tick(false, $T + 99999)['skipped'] ?: 'ran', auto_may_apply(false) ? 'ran' : 'not a safe moment to rewrite files');

t_group('auto-update: switching it off');
t_ok('it can be switched off',  auto_set_enabled(false) && !auto_enabled());
t_eq('…and then nothing happens', auto_tick(true, $T + 999999)['skipped'], 'switched off here');
t_ok('the switch is kept with the update settings, which may hold a token', (fileperms(app_data_root() . '/update.json') & 0777) === 0600);
t_ok('and on again', auto_set_enabled(true) && auto_enabled());
$cfg = json_decode((string)file_get_contents(app_data_root() . '/update.json'), true);
t_eq('turning it on and off leaves the rest of the file alone', array_keys($cfg), ['auto']);
unset($GLOBALS['T_CHECK'], $GLOBALS['T_APPLY'], $GLOBALS['T_AUTO']);
t_safe_seams();   // hand the next test file a suite that cannot touch the tree
