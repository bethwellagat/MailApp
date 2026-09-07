<?php
/**
 * Charset handling for sender-declared text.
 *
 * A single message whose From header announced "windows-1256" took an entire
 * mailbox off the air. mb_convert_encoding() throws a ValueError on PHP 8 for a
 * charset it does not recognise, the "@" in front of it suppresses diagnostics
 * but not exceptions, and the message list decodes every row's headers — so one
 * bad label killed the response for all 400 messages being scanned.
 *
 * The charset is chosen by whoever sent the mail. These assertions are about
 * the two properties that make that safe: it never throws, and what it returns
 * is always encodable as JSON.
 */

require_once T_ROOT . '/lib/util.php';

t_group('the charset that broke a mailbox');
/* "السلام" in windows-1256. mbstring does not know this encoding at all —
   iconv does, which is the whole reason for the fallback. */
$arabic1256 = "\xC7\xE1\xD3\xE1\xC7\xE3";
$out = mail_to_utf8($arabic1256, 'windows-1256');
t_eq('windows-1256 decodes to the right text', $out, 'السلام');
t_ok('and the result is valid UTF-8', mb_check_encoding($out, 'UTF-8'), bin2hex($out));
t_eq('the name is case-insensitive', mail_to_utf8($arabic1256, 'WINDOWS-1256'), 'السلام');
t_eq('and surrounding space is ignored', mail_to_utf8($arabic1256, '  windows-1256 '), 'السلام');

t_group('charsets mbstring does not know but real mail uses');
/* Every one of these throws if handed straight to mb_convert_encoding(). The
   bug was never specific to Arabic. */
foreach (['windows-1256', 'windows-1250', 'gb2312', 'big5', 'cp1256'] as $cs) {
    $r = mail_to_utf8("\x41\x42\x43", $cs);
    t_ok("$cs is handled without throwing", is_string($r) && mb_check_encoding($r, 'UTF-8'), var_export($r, true));
}

t_group('charsets mbstring does know still work');
t_eq('iso-8859-1', mail_to_utf8("caf\xE9", 'iso-8859-1'), 'café');
t_eq('windows-1252', mail_to_utf8("caf\xE9", 'windows-1252'), 'café');
t_eq('utf-8 passes through', mail_to_utf8('café', 'UTF-8'), 'café');
t_eq('utf8 spelled without the dash', mail_to_utf8('café', 'utf8'), 'café');

t_group('the labels imap_mime_header_decode actually emits');
/* It reports "default" for an unencoded header, and the old code special-cased
   that string. The replacement has to keep doing so or every plain ASCII
   subject would be run through a converter for no reason. */
t_eq('"default" means no conversion', mail_to_utf8('Plain Subject', 'default'), 'Plain Subject');
t_eq('an empty charset means no conversion', mail_to_utf8('Plain Subject', ''), 'Plain Subject');

t_group('it must never throw, whatever the sender writes');
/**
 * This is the property that keeps one message from emptying a mailbox. The
 * caller is a loop over hundreds of messages and the input is attacker-chosen,
 * so a throw anywhere here is an outage.
 */
$hostile = [
    'x-unknown-charset', 'not a charset', '', ' ', '../../etc/passwd',
    'UTF-8; charset=evil', "utf-8\x00", str_repeat('A', 500),
    '<script>', '%s%s%s', 'ISO-2022-JP-MS', 'unicode-1-1-utf-7',
];
$threw = [];
foreach ($hostile as $cs) {
    try {
        $r = mail_to_utf8("some \xC7\xE1 bytes", $cs);
        if (!is_string($r))                      $threw[] = "non-string for " . var_export($cs, true);
        if (!mb_check_encoding($r, 'UTF-8'))     $threw[] = "invalid utf8 for " . var_export($cs, true);
    } catch (\Throwable $e) {
        $threw[] = var_export($cs, true) . ' → ' . get_class($e);
    }
}
t_ok('no charset name throws, and every result is valid UTF-8', $threw === [],
     implode(' | ', array_slice($threw, 0, 4)));

t_group('the output is always safe to hand to json_encode');
/**
 * json_encode() returns FALSE on invalid UTF-8 and the endpoints echo its
 * result directly, so a string that survives conversion but is still malformed
 * produces an empty body — which reaches the user as the same "Invalid server
 * response" the charset fault did.
 */
$badBytes = "valid then \xFF\xFE broken \x80\x81";
$r = mail_to_utf8($badBytes, 'UTF-8');
t_ok('malformed UTF-8 is scrubbed', mb_check_encoding($r, 'UTF-8'), bin2hex($r));
t_ok('and json_encode succeeds on it', json_encode(['s' => $r]) !== false, json_last_error_msg());
t_ok('the readable part survives', strpos($r, 'valid then') !== false, $r);

$r = mail_to_utf8($badBytes, 'x-nonsense');
t_ok('an unknown charset also yields encodable text', json_encode(['s' => $r]) !== false);

t_group('scrubbing leaves good text alone');
t_eq('ascii untouched',    mail_scrub_utf8('Hello'), 'Hello');
t_eq('utf-8 untouched',    mail_scrub_utf8('café ☕ السلام'), 'café ☕ السلام');
t_eq('empty stays empty',  mail_scrub_utf8(''), '');

t_group('mbstring name resolution');
t_ok('a known encoding resolves', mail_charset_canon('iso-8859-1') !== null);
t_ok('an alias resolves',         mail_charset_canon('latin1') !== null);
t_ok('an unknown one does not',   mail_charset_canon('windows-1256') === null,
     'if this ever passes, mbstring gained the encoding and the iconv path is no longer exercised');
t_ok('nonsense does not',         mail_charset_canon('definitely-not-a-charset') === null);

t_group('no sender-declared charset reaches mb_convert_encoding');
/**
 * The functional test cannot run here — decode_header() needs the IMAP
 * extension, which this box does not have — so this asserts the same thing at
 * the source level: every mb_convert_encoding() left in the endpoints converts
 * FROM a literal encoding the code chose, never from a variable holding
 * whatever the sender wrote. A variable there is the bug, restored.
 */
foreach (['ajax/fetch.php', 'ajax/calendar.php'] as $file) {
    $src = @file_get_contents(T_ROOT . '/' . $file);
    t_ok("$file is readable", is_string($src) && $src !== '');
    if (!is_string($src)) continue;

    /* Third argument of mb_convert_encoding($text, 'UTF-8', HERE).
       Split on balanced parentheses rather than by regex: the first argument is
       often a call of its own — str_replace($ref, '', $raw) — whose commas a
       naive pattern reads as argument separators, so it reports the wrong token
       and fails on correct code. */
    $dynamic = [];
    $offset  = 0;
    while (($at = strpos($src, 'mb_convert_encoding', $offset)) !== false) {
        $offset = $at + 19;
        $open   = strpos($src, '(', $at);
        if ($open === false) continue;
        $depth = 0; $args = ['']; $n = strlen($src);
        for ($i = $open; $i < $n; $i++) {
            $ch = $src[$i];
            if ($ch === '(') { $depth++; if ($depth === 1) continue; }
            elseif ($ch === ')') { $depth--; if ($depth === 0) break; }
            elseif ($ch === ',' && $depth === 1) { $args[] = ''; continue; }
            $args[count($args) - 1] .= $ch;
        }
        if (count($args) < 3) continue;
        $from = trim($args[2]);
        // A quoted literal is fine: the code picked it, not the sender.
        if (preg_match('/^\'[^\']*\'$/', $from) || preg_match('/^"[^"]*"$/', $from)) continue;
        $dynamic[] = $from;
    }
    t_ok("$file converts only from literal encodings", $dynamic === [],
         'sender-controlled: ' . implode(', ', $dynamic));

    /* And the safe wrapper is actually the thing being used. */
    t_ok("$file routes sender text through mail_to_utf8()",
         strpos($src, 'mail_to_utf8(') !== false);
}

t_group('the JSON writers cannot be defeated by one bad byte');
/* Comments stripped first: this file explains the flag in prose right beside
   the code, and a check for the bare name passed on the comment alone — a test
   that cannot fail is worse than no test, because it reads like cover. */
$src  = @file_get_contents(T_ROOT . '/ajax/fetch.php');
$code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', (string)$src);

t_ok('ok() substitutes invalid UTF-8 rather than returning false',
     preg_match('/json_encode\s*\([^;]*JSON_INVALID_UTF8_SUBSTITUTE/', $code) === 1,
     'json_encode() returns false on bad bytes and the result is echoed directly');
t_ok('fail() does too — an error message can carry a bad byte as easily',
     substr_count($code, 'JSON_INVALID_UTF8_SUBSTITUTE') >= 2,
     'found ' . substr_count($code, 'JSON_INVALID_UTF8_SUBSTITUTE') . ' in code');
t_ok('and it still notices if encoding failed anyway',
     strpos($code, 'json_last_error_msg()') !== false);

t_group('resolving charsets must not print anything');
/**
 * mb_list_encodings() also returns non-charsets — Base64, UUENCODE,
 * HTML-ENTITIES, Quoted-Printable — and mb_encoding_aliases() raises a
 * deprecation notice for them on PHP 8.3+. Any notice printed here lands in the
 * middle of a JSON body, which is the exact failure this whole change exists to
 * remove. The first version of the fix did it.
 */
/* Asserted at the source, not by watching for output: the alias map is built
   once into a static, so by the time this group runs it is already populated
   and a runtime check would pass whatever the code does — the failure only
   happens on the FIRST call in a process, which in production is a real
   request. A test that can only fail depending on its position in the file is
   not a test. */
$u = @file_get_contents(T_ROOT . '/lib/util.php');
t_ok('lib/util.php is readable', is_string($u) && $u !== '');
$fn = '';
if (is_string($u) && preg_match('/function mail_charset_canon.*?\n    \}/s', $u, $m)) $fn = $m[0];
t_ok('mail_charset_canon() was found', $fn !== '');
t_ok('it skips the non-charset entries mb_list_encodings() returns',
     strpos($fn, 'notCharsets') !== false && strpos($fn, 'html-entities') !== false,
     'Base64/UUENCODE/HTML-ENTITIES/Quoted-Printable raise a deprecation on PHP 8.3+');
t_ok('and silences anything the alias lookup still says',
     strpos($fn, '@mb_encoding_aliases') !== false,
     'an unsilenced notice prints into the middle of the JSON body');

/* Belt and braces: it is at least silent when called here. */
ob_start();
$r = mail_to_utf8("\xC7\xE1\xD3\xE1\xC7\xE3", 'windows-1256');
$printed = ob_get_clean();
t_eq('nothing is printed while resolving a charset', $printed, '');
t_eq('and the conversion still worked', $r, 'السلام');
