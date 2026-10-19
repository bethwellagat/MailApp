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
    return atomic_write_json(_snooze_file($email), $data);
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

/* ---------- finding messages again — shared by the endpoint and the scheduler ---------- */

/** Fetch Message-ID for each src uid. Returns map src_uid => message-id. */
function fetch_message_ids($mbox, $uids) {
    $out = [];
    foreach ($uids as $uid) {
        $headers = @imap_fetchheader($mbox, (int)$uid, FT_UID);
        if (!$headers) continue;
        if (preg_match('/^Message-ID:\s*(.+)$/im', $headers, $m)) {
            $id = trim($m[1]);
            // Strip surrounding < >
            if (preg_match('/<([^>]+)>/', $id, $m2)) $id = $m2[1];
            $out[(int)$uid] = $id;
        }
    }
    return $out;
}

/**
 * Find UIDs in a mailbox whose Message-ID equals $messageId.
 * NOTE: imap_search('HEADER Message-ID ...') is silently broken via c-client on
 * some Dovecot builds — it returns no hits even for IDs that exist (see the same
 * note in ajax/fetch.php's thread lookup). Snooze wake relied on it, so snoozed
 * mail could fail to resurface / come back already-read. Scan the folder overview
 * and match message_id client-side instead — reliable across servers.
 */
function find_uids_by_message_id($mbox, $messageId) {
    if (!$messageId) return [];
    // Returns [] when the message is definitively NOT in this mailbox, and NULL
    // when we could not read the mailbox to find out. The caller must not treat
    // those the same: the wake sweep resolves (and deletes) a snooze record when
    // the message is gone, so a transient read failure used to discard the record
    // while the mail was still sitting in Later — it then never came back on its
    // own, even though the folder still held it.
    $check = @imap_check($mbox);
    if (!$check) return null;
    $total = (int)$check->Nmsgs;
    if ($total === 0) return [];
    $MAX_SCAN = 5000;
    $start = max(1, $total - $MAX_SCAN + 1);
    $rows  = @imap_fetch_overview($mbox, $start . ':' . $total, 0);
    if (!is_array($rows)) return null;
    $want = strtolower(trim($messageId, '<> '));
    $hits = [];
    foreach ($rows as $om) {
        if (empty($om->message_id)) continue;
        if (strtolower(trim($om->message_id, '<> ')) === $want) {
            $u = (int)@imap_uid($mbox, $om->msgno);
            if ($u > 0) $hits[] = $u;
        }
    }
    return $hits;
}
