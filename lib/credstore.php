<?php
/**
 * Sealed credentials for unattended work — opt-in, per account.
 *
 * Scheduled sends, out-of-office replies, snooze wake-ups and filters all
 * open the mailbox, and the mailbox needs a password. Signed-in users carry
 * theirs in the PHP session; a cron job has no session. So an account that
 * wants those jobs to run while nobody is signed in has to let the server
 * keep its password. This file is how it keeps it: sealed with libsodium's
 * secretbox (AES-256-GCM through OpenSSL where sodium is missing) under a
 * key generated once into data/cred/.key, mode 0600, never sent anywhere.
 *
 * The honest limit, written here so nobody has to rediscover it: on shared
 * hosting the key sits beside the data, so a full compromise of the hosting
 * account exposes the password. That is no worse than the session files PHP
 * already writes to disk in plain text while a user is signed in — and it is
 * bounded to accounts that chose it, which can withdraw it at any time.
 *
 * What is NOT sealed: the account id and email, in the clear, because the
 * cron needs to list accounts and find their per-user stores (all keyed by
 * email) without opening every seal first.
 */

require_once __DIR__ . '/log.php';   // app_data_root()
require_once __DIR__ . '/util.php';  // atomic_write_json()

function cred_dir()      { return app_data_root() . '/cred'; }
function cred_key_file() { return cred_dir() . '/.key'; }
function cred_path($id)  { return cred_dir() . '/' . hash('sha256', (string)$id) . '.json'; }

/** Which cipher this PHP can use; null means unattended jobs cannot be offered. */
function cred_backend() {
    if (function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open')) return 'sodium';
    if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)) return 'openssl';
    return null;
}

/**
 * The install's sealing key: 32 random bytes, created once, readable by the
 * web user only. Returns the raw bytes, or false if it cannot be created or
 * read — in which case nothing is sealed and the caller says so.
 */
function cred_key() {
    $dir  = cred_dir();
    $file = cred_key_file();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    if (is_file($file)) {
        $hex = trim((string)@file_get_contents($file));
        if (strlen($hex) === 64 && ctype_xdigit($hex)) return hex2bin($hex);
        return false;   // a damaged key must not be silently replaced: that would orphan every seal
    }
    try { $raw = random_bytes(32); } catch (\Throwable $e) { return false; }
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, bin2hex($raw), LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    @chmod($file, 0600);
    return $raw;
}

/** Seal any array. Returns the blob to store, or null if sealing is impossible. */
function cred_seal(array $data, $key) {
    $plain = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($plain === false || !is_string($key) || strlen($key) !== 32) return null;
    $alg = cred_backend();
    if ($alg === 'sodium') {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = sodium_crypto_secretbox($plain, $nonce, $key);
        return ['v' => 1, 'alg' => 'sodium', 'n' => base64_encode($nonce), 'c' => base64_encode($box)];
    }
    if ($alg === 'openssl') {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) return null;
        return ['v' => 1, 'alg' => 'openssl', 'n' => base64_encode($iv), 'c' => base64_encode($ct), 't' => base64_encode($tag)];
    }
    return null;
}

/** Open a sealed blob. Null on any tampering, wrong key, or damage — never a partial result. */
function cred_open($blob, $key) {
    if (!is_array($blob) || !is_string($key) || strlen($key) !== 32) return null;
    $n = base64_decode((string)($blob['n'] ?? ''), true);
    $c = base64_decode((string)($blob['c'] ?? ''), true);
    if ($n === false || $c === false) return null;
    $plain = false;
    try {
        if (($blob['alg'] ?? '') === 'sodium' && function_exists('sodium_crypto_secretbox_open')) {
            if (strlen($n) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
            $plain = sodium_crypto_secretbox_open($c, $n, $key);
        } elseif (($blob['alg'] ?? '') === 'openssl' && function_exists('openssl_decrypt')) {
            $t = base64_decode((string)($blob['t'] ?? ''), true);
            if ($t === false) return null;
            $plain = openssl_decrypt($c, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $n, $t);
        }
    } catch (\Throwable $e) {
        return null;
    }
    if (!is_string($plain)) return null;
    $data = json_decode($plain, true);
    return is_array($data) ? $data : null;
}

/* ---------- the store ---------- */

/**
 * Keep an account's credentials for unattended jobs. $acct is the session's
 * account record (id, email, password, imap_host, imap_port, imap_ssl,
 * smtp_host, display_name). Returns true when sealed and written.
 */
function cred_enable(array $acct) {
    $id = (string)($acct['id'] ?? '');
    $email = strtolower(trim((string)($acct['email'] ?? '')));
    if ($id === '' || $email === '' || (string)($acct['password'] ?? '') === '') return false;
    $key = cred_key();
    if ($key === false) return false;
    $acct['id'] = $id;
    $sealed = cred_seal($acct, $key);
    if ($sealed === null) return false;
    return (bool)atomic_write_json(cred_path($id), [
        'id'         => $id,
        'email'      => $email,
        'enabled_at' => gmdate('c'),
        'sealed'     => $sealed,
    ]);
}

/** Withdraw. The file is removed outright; nothing of the seal is kept. */
function cred_disable($id) {
    $p = cred_path($id);
    if (!is_file($p)) return true;
    return (bool)@unlink($p);
}

function cred_enabled($id) {
    return is_file(cred_path($id));
}

/**
 * The record with its account opened, or null. A seal that opens to a
 * different account id than the file claims is treated as damage — a blob
 * copied from one account's file into another's must not grant that
 * account's jobs the other's mailbox.
 */
function cred_get($id) {
    $p = cred_path($id);
    if (!is_file($p)) return null;
    $rec = @json_decode((string)@file_get_contents($p), true);
    if (!is_array($rec) || !isset($rec['sealed'])) return null;
    $key = cred_key();
    if ($key === false) return null;
    $acct = cred_open($rec['sealed'], $key);
    if ($acct === null) return null;
    if ((string)($acct['id'] ?? '') !== (string)$id || (string)($rec['id'] ?? '') !== (string)$id) return null;
    unset($rec['sealed']);
    $rec['account'] = $acct;
    return $rec;
}

/** Every opted-in account, secrets untouched: id, email, enabled_at only. */
function cred_list() {
    $dir = cred_dir();
    if (!is_dir($dir)) return [];
    $out = [];
    foreach ((array)@glob($dir . '/*.json') as $f) {
        $rec = @json_decode((string)@file_get_contents($f), true);
        if (!is_array($rec) || empty($rec['id']) || empty($rec['email'])) continue;
        $out[] = ['id' => (string)$rec['id'], 'email' => (string)$rec['email'], 'enabled_at' => (string)($rec['enabled_at'] ?? '')];
    }
    usort($out, fn($a, $b) => strcmp($a['email'], $b['email']));
    return $out;
}
