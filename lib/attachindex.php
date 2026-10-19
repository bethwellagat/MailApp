<?php
/**
 * Attachment index — remembers, per folder, which messages carry a real file.
 *
 * IMAP's search language has no key for "has an attachment". The only honest
 * way to answer has:attachment is to look at each message's structure once
 * and remember the answer. The list already does that look for its paperclip
 * icons, one BODYSTRUCTURE per message, but kept the result in the browser
 * tab; this keeps it on the server instead, so every page viewed and every
 * search run leaves the index a little more complete.
 *
 * One JSON file per (user, account, folder), keyed by UID, reset whenever the
 * folder's UIDVALIDITY changes — the server's own signal that UIDs were
 * renumbered and nothing remembered about them can be trusted.
 *
 * Everything here is pure except the two file functions, and those use the
 * same lock-and-atomic-write path as every other store in data/.
 */

function attach_index_dir() {
    return __DIR__ . '/../data/attach-index';
}

/**
 * The file for one folder. Hashed, never the raw names: an IMAP folder name
 * can contain a slash, a dot, or anything the sender's locale allowed, and a
 * path built from it would be a path built by a stranger.
 */
function attach_index_path($email, $acct, $folder) {
    $key = sha1(strtolower(trim((string)$email))) . '-' . sha1((string)$acct) . '-' . sha1((string)$folder);
    return attach_index_dir() . '/' . $key . '.json';
}

/** An empty index for a folder at this UIDVALIDITY. */
function attach_index_empty($uidvalidity) {
    return ['uidvalidity' => (int)$uidvalidity, 'flags' => []];
}

/**
 * Read the index for a folder; start over if the UIDVALIDITY has moved on.
 * A missing or unreadable file is an empty index, never an error.
 */
function attach_index_load($path, $uidvalidity) {
    $uidvalidity = (int)$uidvalidity;
    if (!is_file($path)) return attach_index_empty($uidvalidity);
    $raw = @file_get_contents($path);
    $d = $raw === false ? null : @json_decode($raw, true);
    if (!is_array($d) || (int)($d['uidvalidity'] ?? -1) !== $uidvalidity || !is_array($d['flags'] ?? null)) {
        return attach_index_empty($uidvalidity);
    }
    $flags = [];
    foreach ($d['flags'] as $uid => $v) {
        $u = (int)$uid;
        if ($u > 0) $flags[$u] = $v ? 1 : 0;
    }
    return ['uidvalidity' => $uidvalidity, 'flags' => $flags];
}

/**
 * Split a set of UIDs by what the index knows.
 *
 * Returns [matching, unknown]: the UIDs whose remembered answer satisfies
 * $want ('attachment' or 'no-attachment'), and the UIDs the index has never
 * seen. UIDs the index knows and that do NOT match are simply dropped — that
 * is the filtering. Order of the input is preserved in both lists.
 */
function attach_index_filter(array $index, array $uids, $want) {
    $wantFlag = ($want === 'no-attachment') ? 0 : 1;
    $match = [];
    $unknown = [];
    $flags = $index['flags'] ?? [];
    foreach ($uids as $uid) {
        $u = (int)$uid;
        if (!array_key_exists($u, $flags)) { $unknown[] = $u; continue; }
        if ((int)$flags[$u] === $wantFlag) $match[] = $u;
    }
    return [$match, $unknown];
}

/** Record answers: uid => bool. Returns how many were new or changed. */
function attach_index_merge(array &$index, array $results) {
    $changed = 0;
    foreach ($results as $uid => $has) {
        $u = (int)$uid;
        if ($u <= 0) continue;
        $v = $has ? 1 : 0;
        if (!isset($index['flags'][$u]) || $index['flags'][$u] !== $v) {
            $index['flags'][$u] = $v;
            $changed++;
        }
    }
    return $changed;
}

/**
 * Write the index back, keeping at most $cap entries — the newest, since
 * UIDs only ever grow and old mail is what people search least.
 */
function attach_index_save($path, array $index, $cap = 20000) {
    $flags = $index['flags'] ?? [];
    if (count($flags) > $cap) {
        krsort($flags, SORT_NUMERIC);
        $flags = array_slice($flags, 0, $cap, true);
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    $lk = function_exists('store_lock') ? store_lock($path, 500) : false;
    $ok = function_exists('atomic_write_json')
        ? atomic_write_json($path, ['uidvalidity' => (int)($index['uidvalidity'] ?? 0), 'flags' => $flags])
        : (@file_put_contents($path, json_encode(['uidvalidity' => (int)($index['uidvalidity'] ?? 0), 'flags' => $flags])) !== false);
    if ($lk && function_exists('store_unlock')) store_unlock($lk);
    return (bool)$ok;
}
