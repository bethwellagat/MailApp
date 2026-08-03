<?php
/**
 * Test runner — zero dependencies.
 *
 *   php tests/run.php            run everything
 *   php tests/run.php sanitizer  run only files matching "sanitizer"
 *   VERBOSE=1 php tests/run.php  also print passing assertions
 *
 * Exits non-zero if anything failed, so it can gate a deploy.
 */

require __DIR__ . '/lib.php';

$filter = $argv[1] ?? '';
$files  = glob(__DIR__ . '/test_*.php');
sort($files);
if ($filter !== '') {
    $files = array_values(array_filter($files, fn($f) => strpos(basename($f), $filter) !== false));
}
if (!$files) {
    fwrite(STDERR, "no test files matched" . ($filter !== '' ? " \"$filter\"" : '') . "\n");
    exit(1);
}

$started = microtime(true);
echo "\n";
foreach ($files as $f) {
    echo basename($f, '.php') . "\n";
    require $f;
    echo "\n";
}
t_cleanup();

$ms = (microtime(true) - $started) * 1000;
$pass = $GLOBALS['T_PASS'];
$fail = $GLOBALS['T_FAIL'];

if ($fail > 0) {
    echo "FAILURES\n";
    foreach ($GLOBALS['T_FAILURES'] as $x) echo "  - $x\n";
    echo "\n";
}
printf("%d passed, %d failed  (%.0f ms)\n\n", $pass, $fail, $ms);
exit($fail > 0 ? 1 : 0);
