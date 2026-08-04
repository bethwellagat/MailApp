<?php
/**
 * Minimal test helpers — no framework, no Composer, nothing to install.
 * Run the suite with:  php tests/run.php
 *
 * Design notes:
 *  - Assertions are plain functions; a test file is just PHP that calls them.
 *  - Several functions under test live INSIDE endpoint files (ajax/fetch.php
 *    executes on include), so t_extract_fn() lifts a single function out by
 *    source and evaluates it under a new name. It uses the tokenizer, NOT brace
 *    counting: an apostrophe inside a comment ("// don't …") desynchronises
 *    naive string skipping and silently swallows half the file.
 *  - Tests must never touch real user data. t_tmpdir() gives each run its own
 *    scratch directory, and t_data_path() refuses to hand back a path that
 *    already exists in data/.
 */

define('T_ROOT', dirname(__DIR__));

$GLOBALS['T_PASS'] = 0;
$GLOBALS['T_FAIL'] = 0;
$GLOBALS['T_FAILURES'] = [];
$GLOBALS['T_GROUP'] = '';

function t_group($name) {
    $GLOBALS['T_GROUP'] = $name;
    echo "  " . $name . "\n";
}

function t_ok($name, $cond, $detail = '') {
    if ($cond) {
        $GLOBALS['T_PASS']++;
        if (getenv('VERBOSE')) printf("    pass  %s\n", $name);
        return true;
    }
    $GLOBALS['T_FAIL']++;
    $GLOBALS['T_FAILURES'][] = ($GLOBALS['T_GROUP'] ? $GLOBALS['T_GROUP'] . ' / ' : '') . $name
                             . ($detail !== '' ? "  [$detail]" : '');
    printf("    FAIL  %s%s\n", $name, $detail !== '' ? "   [$detail]" : '');
    return false;
}

function t_eq($name, $actual, $expected) {
    return t_ok($name, $actual === $expected,
        'got ' . var_export($actual, true) . ', expected ' . var_export($expected, true));
}

function t_contains($name, $haystack, $needle) {
    return t_ok($name, strpos((string)$haystack, (string)$needle) !== false,
        'missing "' . $needle . '" in: ' . substr(trim((string)$haystack), 0, 160));
}

function t_not_contains($name, $haystack, $needle) {
    return t_ok($name, strpos((string)$haystack, (string)$needle) === false,
        'unexpected "' . $needle . '" in: ' . substr(trim((string)$haystack), 0, 160));
}

/** Lift one function out of a PHP file and define it under $alias. */
function t_extract_fn($file, $name, $alias) {
    if (function_exists($alias)) return;
    $tokens = token_get_all(file_get_contents($file));
    $n = count($tokens);
    $i = 0;
    for (; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
        if ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $name) break;
    }
    if ($i >= $n) { fwrite(STDERR, "could not find function $name in $file\n"); exit(1); }

    // Capture the real parameter list rather than assuming one argument. The
    // helper began life extracting the sanitizers, which all take a single
    // $html, and hardcoded that — so a two-argument function came out with an
    // undefined parameter and every assertion against it silently read ''.
    // For the single-argument callers this reproduces the same signature.
    $params = ''; $paren = 0; $k = $j + 1;
    for (; $k < $n; $k++) {
        $t   = $tokens[$k];
        $txt = is_array($t) ? $t[1] : $t;
        if (!is_array($t) && $t === '(') $paren++;
        if (!is_array($t) && $t === '{' && $paren === 0) break;   // body starts
        $params .= $txt;
        if (!is_array($t) && $t === ')') { $paren--; if ($paren === 0) { $k++; break; } }
    }
    $params = trim($params);
    if ($params === '' || $params[0] !== '(') {
        fwrite(STDERR, "could not read the parameter list of $name in $file\n");
        exit(1);
    }

    $depth = 0; $started = false; $body = '';
    for (; $i < $n; $i++) {
        $t   = $tokens[$i];
        $txt = is_array($t) ? $t[1] : $t;
        if (!is_array($t) && $t === '{') { $depth++; $started = true; }
        if ($started) $body .= $txt;
        if (!is_array($t) && $t === '}') { $depth--; if ($depth === 0) break; }
    }
    // Guard against a silently over-broad extraction.
    if (substr_count($body, "\n") > 200) {
        fwrite(STDERR, "extraction of $name from $file looks wrong (" . substr_count($body, "\n") . " lines)\n");
        exit(1);
    }
    eval("function $alias$params $body");
}

/** A scratch directory for this process, removed by t_cleanup(). */
function t_tmpdir() {
    static $dir = null;
    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/mailapp-tests-' . getmypid();
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * A path under data/ that is safe for a test to create and delete: it must not
 * already exist, so a test can never destroy real user state.
 */
function t_data_path($relative) {
    $p = T_ROOT . '/data/' . ltrim($relative, '/');
    if (file_exists($p)) {
        fwrite(STDERR, "refusing to use existing data path in tests: $relative\n");
        exit(1);
    }
    $GLOBALS['T_TEMP_DATA'][] = $p;
    return $p;
}

function t_cleanup() {
    foreach (($GLOBALS['T_TEMP_DATA'] ?? []) as $p) @unlink($p);
    $dir = sys_get_temp_dir() . '/mailapp-tests-' . getmypid();
    if (is_dir($dir)) {
        @exec('chmod -R u+rwx ' . escapeshellarg($dir) . ' 2>/dev/null');
        @exec('rm -rf ' . escapeshellarg($dir));
    }
}
