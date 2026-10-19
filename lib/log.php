<?php
/**
 * Server-side log — data/logs/app.log, rotated.
 *
 * Until now the server wrote nothing down. A request that failed without a
 * fatal (an IMAP folder that would not open, an SMTP rejection, a filter that
 * could not move a message) left no trace, and the one fault that did get
 * reported — the fatal guard — reported to the user on screen, not to the
 * person running the install. The charset fault that blanked a tenant's inbox
 * ran for an unknown time before anyone noticed.
 *
 * One file, under data/ (already denied to the web), rotated at 1 MB with
 * three generations kept. Lines are scrubbed of anything that looks like a
 * password or token before they are written, because a log that could ever
 * contain a credential is a log nobody should keep.
 *
 * Never throws and never prints: a logging failure must not become the thing
 * that breaks the request it was asked to describe.
 */

const APP_LOG_MAX_BYTES = 1048576;   // rotate past 1 MB
const APP_LOG_KEEP      = 3;         // app.log.1 … app.log.3
const APP_LOG_LINE_MAX  = 2000;      // one line never longer than this

/** The data root; tests point it at a scratch directory. */
function app_data_root() {
    return isset($GLOBALS['MAILAPP_DATA_DIR']) ? rtrim((string)$GLOBALS['MAILAPP_DATA_DIR'], '/') : __DIR__ . '/../data';
}

function app_log_dir()  { return app_data_root() . '/logs'; }
function app_log_file() { return app_log_dir() . '/app.log'; }

/**
 * Remove anything that reads like a secret. Deliberately broad: a password
 * that slips into an IMAP error string or a URL is worse than an over-eager
 * redaction of a harmless word.
 */
function app_log_scrub($s) {
    $s = (string)$s;
    $s = preg_replace('/[\r\n]+/', ' ', $s);
    // Quote-tolerant, so "password":"x" in a JSON fragment is caught as well as password=x.
    $s = preg_replace('/\b(password|passwd|passwort|pwd|pass|token|secret|api[_-]?key)("?\s*[=:]\s*"?)[^\s"]+/i', '$1$2[redacted]', $s);
    $s = preg_replace('/\b(Authorization\s*:\s*)\S+(\s+\S+)?/i', '$1[redacted]', $s);
    $s = preg_replace('/\bAUTH\s+(PLAIN|LOGIN)\s+\S+/i', 'AUTH $1 [redacted]', $s);
    // user:pass@host in any URL or IMAP ref
    $s = preg_replace('#(://[^/\s:@]+:)[^@\s]+@#', '$1[redacted]@', $s);
    return $s;
}

/**
 * Redact by KEY before anything is encoded. A regex over the finished JSON
 * has to guess at quoting; a value stored under "password" needs no guess.
 */
function app_log_redact(array $ctx) {
    $out = [];
    foreach ($ctx as $k => $v) {
        if (is_string($k) && preg_match('/^(password|passwd|passwort|pwd|pass|token|secret|api[_-]?key|authorization|cookie)$/i', $k)) {
            $out[$k] = '[redacted]';
        } elseif (is_array($v)) {
            $out[$k] = app_log_redact($v);
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
}

/** One log line, fully formed. Pure, so the format is testable. */
function app_log_line($level, $msg, array $ctx = [], $time = null) {
    $time  = $time ?? time();
    $level = strtoupper(substr(preg_replace('/[^a-z]/i', '', (string)$level) ?: 'info', 0, 5));
    $json  = $ctx ? json_encode(app_log_redact($ctx), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : '';
    $line  = gmdate('Y-m-d\TH:i:s\Z', $time) . ' ' . str_pad($level, 5) . ' ' . app_log_scrub($msg)
           . ($json !== '' && $json !== false ? ' ' . app_log_scrub($json) : '');
    // mb_strcut: a byte cap that never splits a character, with room for the
    // three-byte ellipsis — a plain substr overshot the cap by exactly that.
    if (strlen($line) > APP_LOG_LINE_MAX) $line = mb_strcut($line, 0, APP_LOG_LINE_MAX - 3, 'UTF-8') . '…';
    return $line;
}

/** Shift app.log → .1 → .2 … when it has grown past $max. */
function app_log_rotate($file, $max = APP_LOG_MAX_BYTES, $keep = APP_LOG_KEEP) {
    $size = @filesize($file);
    if ($size === false || $size < $max) return false;
    for ($i = $keep; $i >= 1; $i--) {
        $from = $i === 1 ? $file : $file . '.' . ($i - 1);
        $to   = $file . '.' . $i;
        if (is_file($from)) {
            if ($i === $keep && is_file($to)) @unlink($to);
            @rename($from, $to);
        }
    }
    return true;
}

/**
 * Append one line. Levels: info, warn, error, fatal. $ctx is any array of
 * details; it is JSON-encoded and scrubbed. Returns true if written.
 */
function app_log($level, $msg, array $ctx = []) {
    try {
        $dir = app_log_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
        $file = app_log_file();
        app_log_rotate($file);
        $line = app_log_line($level, $msg, $ctx) . "\n";
        $h = @fopen($file, 'a');
        if (!$h) return false;
        $ok = false;
        if (@flock($h, LOCK_EX)) { $ok = @fwrite($h, $line) !== false; @flock($h, LOCK_UN); }
        @fclose($h);
        return $ok;
    } catch (\Throwable $e) {
        return false;
    }
}

/** The last $n lines, newest last. Reads only the tail of the file. */
function app_log_tail($n = 200) {
    $file = app_log_file();
    if (!is_file($file)) return [];
    $size = (int)@filesize($file);
    $chunk = min($size, 256 * 1024);
    $h = @fopen($file, 'r');
    if (!$h) return [];
    if ($chunk < $size) @fseek($h, $size - $chunk);
    $data = (string)@stream_get_contents($h);
    @fclose($h);
    $lines = preg_split('/\r?\n/', rtrim($data, "\r\n"));
    if ($chunk < $size && count($lines) > 1) array_shift($lines);   // first line may be cut
    $lines = array_values(array_filter($lines, fn($l) => $l !== ''));
    return array_slice($lines, -max(1, (int)$n));
}

/** What Diagnostics shows about the log itself. */
function app_log_status() {
    $file = app_log_file();
    $gens = [];
    for ($i = 1; $i <= APP_LOG_KEEP; $i++) if (is_file($file . '.' . $i)) $gens[] = $i;
    return [
        'file'        => 'data/logs/app.log',
        'bytes'       => is_file($file) ? (int)@filesize($file) : 0,
        'generations' => $gens,
        'writable'    => is_dir(app_log_dir()) ? is_writable(app_log_dir()) : is_writable(app_data_root()),
    ];
}
