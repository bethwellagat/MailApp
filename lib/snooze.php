<?php
require_once __DIR__ . '/util.php';
/**
 * Per-user snooze tracking.
 *
 * When a user snoozes a message we:
 *   1. Move it to a "Later" folder via IMAP (created if missing).
 *   2. Record { uid_in_later, original_folder, wake_at } in this user's JSON.
 *
 * The ajax `wake` action (run on each poll) scans the JSON file for entries
 * past their wake_at and moves them back to the original folder marked unread.
 * Bookkeeping lives in data/snoozes/<sha256(email)>.json.
 *
 * Why a JSON file (vs IMAP keyword/header): cPanel-shared IMAP doesn't
 * provide custom keyword storage reliably, and inspecting headers means an
 * IMAP fetch per message. A small JSON file is fast + atomic to update.
 */

function _snooze_dir() { return __DIR__ . '/../data/snoozes'; }

function _snooze_file($email) {
    $dir = _snooze_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/' . hash('sha256', strtolower(trim($email))) . '.json';
}

function load_snoozes($email) {
    if (!$email) return ['snoozes' => []];
    $file = _snooze_file($email);
    if (!is_file($file)) return ['snoozes' => []];
    $raw = @file_get_contents($file);
    if ($raw === false) return ['snoozes' => []];
    $data = @json_decode($raw, true);
    if (!is_array($data) || !isset($data['snoozes'])) return ['snoozes' => []];
    return $data;
}

function save_snoozes($email, $data) {
    if (!$email || !is_array($data)) return false;
    $file = _snooze_file($email);
    $tmp  = $file . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return @rename($tmp, $file);
}

function snooze_uuid() { return gen_uuid(); }

/**
 * Serialize a load→modify→save on the snooze file so concurrent requests
 * (two tabs snoozing at once, or a background `wake` poll racing an
 * interactive `add`/`cancel`) can't clobber each other's records and strand a
 * message in "Later" forever. Same lock discipline lib/prefs.php uses.
 * snooze_lock() returns a handle for snooze_unlock(); PHP also releases the
 * lock automatically if the script exits while still holding it.
 */
function snooze_lock($email) {
    $lock = @fopen(_snooze_file($email) . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    return $lock;
}
function snooze_unlock($lock) {
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
}

/**
 * Find or create a "Later" folder. Returns its IMAP name, or false.
 * Reuses any folder containing "later" (case-insensitive); falls back to
 * creating "Later" at the top level.
 */
function ensure_later_folder($mbox, $ref) {
    $list = @imap_list($mbox, $ref, '*');
    if ($list) {
        foreach ($list as $raw) {
            $utf8 = mb_convert_encoding(str_replace($ref, '', $raw), 'UTF-8', 'UTF7-IMAP');
            if (stripos($utf8, 'later') !== false) return $utf8;
        }
    }
    $name = 'Later';
    $imapName = mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
    if (@imap_createmailbox($mbox, $ref . $imapName)) {
        @imap_subscribe($mbox, $ref . $imapName);
        return $name;
    }
    return false;
}

// (Removed dead process_due_snoozes(): it was never called and read a
//  'later_uid' field the writer never persisted. The live wake logic is the
//  `wake` action in ajax/snooze.php.)
