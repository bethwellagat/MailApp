<?php
/**
 * Small shared helpers used across the lib/ modules.
 */

if (!function_exists('ini_bytes')) {
    /**
     * Parse a PHP ini shorthand size ("2M", "512K", "1G", or a plain byte count)
     * into an integer number of bytes. Returns 0 for empty / "-1" (unlimited) /
     * unparseable input, so callers can treat 0 as "no known limit". Mirrors PHP's
     * own K/M/G shorthand (binary — powers of 1024).
     */
    function ini_bytes($val) {
        $val = trim((string)$val);
        if ($val === '' || $val === '-1') return 0;
        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)/i', $val, $m)) return 0;
        $n = (float)$m[1];
        switch (strtoupper($m[2])) {
            case 'G': $n *= 1024; // fall through
            case 'M': $n *= 1024; // fall through
            case 'K': $n *= 1024;
        }
        return (int)$n;
    }
}

if (!function_exists('poll_gate')) {
    /**
     * Shared cross-tab/-request throttle for background poll jobs — the in-app
     * "cron" (no real cron on the host). Returns true AT MOST once per $seconds
     * per (email, job) across every open tab, so a job's expensive IMAP work runs
     * once per window instead of once per tab per poll.
     *
     * A per-account timestamp file guarded by a NON-BLOCKING lock: the first caller
     * in the window wins, stamps the time, returns true; concurrent callers fail the
     * lock (or see the fresh timestamp) and return false. Fails OPEN only if the
     * lock/dir is unusable, so a job is never permanently stalled. Cheap: one small
     * file read, plus a write only when due.
     */
    function poll_gate($email, $job, $seconds) {
        if (!$email) return true;
        $dir = __DIR__ . '/../data/poll';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $file = $dir . '/' . hash('sha256', strtolower(trim($email))) . '.json';
        $lock = @fopen($file . '.lock', 'c');
        if (!$lock) return true;                                                 // can't lock → fail open
        if (!@flock($lock, LOCK_EX | LOCK_NB)) { @fclose($lock); return false; } // another request is deciding
        $data = @json_decode((string) @file_get_contents($file), true);
        if (!is_array($data)) $data = [];
        $now = time();
        $due = ($now - (int) ($data[$job] ?? 0)) >= (int) $seconds;
        if ($due) {
            $data[$job] = $now;
            @file_put_contents($file, json_encode($data), LOCK_EX);
            @chmod($file, 0600);
        }
        @flock($lock, LOCK_UN);
        @fclose($lock);
        return $due;
    }
}

if (!function_exists('gen_uuid')) {
    /** RFC 4122 version-4 UUID, e.g. "f47ac10b-58cc-4372-a567-0e02b2c3d479". */
    function gen_uuid() {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }
}
