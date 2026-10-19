<?php
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/search.php';   // the "query" condition speaks the search box's language
/**
 * Per-user filter rules (Gmail-style).
 *
 * Storage: data/rules/<sha256(email)>.json
 * Schema:
 *   {
 *     "rules": [
 *       {
 *         "id":      "uuid",
 *         "enabled": true,
 *         "match": {
 *           "mode": "all" | "any",            every condition, or any one of them
 *           "from": "", "to": "", "cc": "", "subject": "",
 *           "has_words": "", "not_words": "",
 *           "has_attachment": false,         always required when set (a structure check, not a search key)
 *           "larger": 0, "smaller": 0,       bytes; 0 = unset
 *           "older_than": 0, "newer_than": 0, days; 0 = unset
 *           "query": ""                      search-box operators: from:x is:unread -subject:y larger:5M …
 *         },
 *         "actions": {
 *           "skip_inbox": false,
 *           "mark_read":  false,
 *           "star":       false,
 *           "move_to":    "",
 *           "delete":     false
 *         },
 *         "created_at": "..."
 *       }
 *     ],
 *     "last_processed_uid": 0
 *   }
 *
 * Rules apply to newly arrived INBOX messages whose UID is greater than
 * last_processed_uid. They can also be applied on demand to existing
 * messages.
 */

function _rules_dir() { return __DIR__ . '/../data/rules'; }

function _rules_file($email) {
    $dir = _rules_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/' . hash('sha256', strtolower(trim($email))) . '.json';
}

function load_rules($email) {
    $defaults = ['rules' => [], 'last_processed_uid' => 0];
    if (!$email) return $defaults;
    $file = _rules_file($email);
    if (!is_file($file)) return $defaults;
    $raw = @file_get_contents($file);
    if ($raw === false) return $defaults;
    $data = @json_decode($raw, true);
    if (!is_array($data) || !isset($data['rules'])) return $defaults;
    if (!isset($data['last_processed_uid'])) $data['last_processed_uid'] = 0;
    return $data;
}

function save_rules($email, $data) {
    if (!$email || !is_array($data)) return false;
    return atomic_write_json(_rules_file($email), $data);
}

function rule_uuid() { return gen_uuid(); }

function sanitize_rule($r) {
    if (!is_array($r)) return null;
    $match = is_array($r['match'] ?? null) ? $r['match'] : [];
    $actions = is_array($r['actions'] ?? null) ? $r['actions'] : [];

    // Reject c-client metacharacters / control chars in the move-to folder so a
    // saved rule can't rewrite the {host:port} IMAP connection ref and exfiltrate
    // matched mail to an attacker's server (mirrors valid_mailbox_name in fetch.php).
    $moveTo = mb_substr(trim((string)($actions['move_to'] ?? '')), 0, 200);
    if ($moveTo !== '' && preg_match('/[{}\x00-\x1F\x7F]/', $moveTo)) $moveTo = '';

    $query = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($match['query'] ?? ''))), 0, 300);
    $qp    = $query !== '' ? search_parse($query) : null;
    $clean = [
        'id'         => $r['id'] ?? rule_uuid(),
        'enabled'    => array_key_exists('enabled', $r) ? (bool)$r['enabled'] : true,
        'match'      => [
            'mode'           => (($match['mode'] ?? 'all') === 'any') ? 'any' : 'all',
            'from'           => mb_substr(trim((string)($match['from']      ?? '')), 0, 200),
            'to'             => mb_substr(trim((string)($match['to']        ?? '')), 0, 200),
            'cc'             => mb_substr(trim((string)($match['cc']        ?? '')), 0, 200),
            'subject'        => mb_substr(trim((string)($match['subject']   ?? '')), 0, 200),
            'has_words'      => mb_substr(trim((string)($match['has_words'] ?? '')), 0, 200),
            'not_words'      => mb_substr(trim((string)($match['not_words'] ?? '')), 0, 200),
            // has:attachment in the query is the same condition as the box.
            'has_attachment' => !empty($match['has_attachment']) || ($qp && ($qp['has'] ?? null) === 'attachment'),
            'larger'         => rule_size_bytes($match['larger']  ?? ''),
            'smaller'        => rule_size_bytes($match['smaller'] ?? ''),
            'older_than'     => rule_days($match['older_than'] ?? 0),
            'newer_than'     => rule_days($match['newer_than'] ?? 0),
            'query'          => $query,
        ],
        'actions'    => [
            'skip_inbox' => !empty($actions['skip_inbox']),
            'mark_read'  => !empty($actions['mark_read']),
            'star'       => !empty($actions['star']),
            'move_to'    => $moveTo,
            'delete'     => !empty($actions['delete']),
        ],
        'created_at' => $r['created_at'] ?? gmdate('c'),
    ];

    // A rule must have at least one match criterion AND at least one action.
    $hasMatch = $clean['match']['from'] !== ''
             || $clean['match']['to'] !== ''
             || $clean['match']['cc'] !== ''
             || $clean['match']['subject'] !== ''
             || $clean['match']['has_words'] !== ''
             || $clean['match']['not_words'] !== ''
             || $clean['match']['has_attachment']
             || $clean['match']['larger'] > 0
             || $clean['match']['smaller'] > 0
             || $clean['match']['older_than'] > 0
             || $clean['match']['newer_than'] > 0
             || $clean['match']['query'] !== '';
    $hasAction = $clean['actions']['skip_inbox']
              || $clean['actions']['mark_read']
              || $clean['actions']['star']
              || $clean['actions']['move_to'] !== ''
              || $clean['actions']['delete'];
    if (!$hasMatch || !$hasAction) return null;
    return $clean;
}

/** "5M", "500K", "1024" → bytes; anything else 0 (unset). Already-numeric values pass through. */
function rule_size_bytes($v) {
    if (is_int($v) || is_float($v)) return max(0, (int)$v);
    $v = trim((string)$v);
    if ($v === '') return 0;
    if (ctype_digit($v)) return (int)$v;
    $n = _search_size($v);
    return $n === null ? 0 : max(0, (int)$n);
}

/** Bytes back to the short form the user typed: 5242880 → 5M, 51200 → 50K. */
function rule_size_text($bytes) {
    $bytes = (int)$bytes;
    if ($bytes <= 0) return '';
    if ($bytes % 1048576 === 0) return ($bytes / 1048576) . 'M';
    if ($bytes % 1024 === 0)    return ($bytes / 1024) . 'K';
    return (string)$bytes;
}

/** A day count for the age conditions: 0..3650. */
function rule_days($v) { return max(0, min(3650, (int)$v)); }

/** What the search parser could not understand in a rule's query, if anything. */
function rule_query_errors($query) {
    $query = trim((string)$query);
    return $query === '' ? [] : (search_parse($query)['errors'] ?? []);
}

/** Alternatives for IMAP: OR takes two keys, so three become OR (a) (OR (b) (c)). Pure. */
function rule_or_join(array $parts) {
    $parts = array_values($parts);
    if (count($parts) === 1) return $parts[0];
    $expr = '(' . array_pop($parts) . ')';
    while ($parts) {
        $p    = array_pop($parts);
        $expr = ($parts ? '(' : '') . 'OR (' . $p . ') ' . $expr . ($parts ? ')' : '');
    }
    return $expr;
}

/**
 * Build an IMAP SEARCH expression from a rule's match criteria.
 *
 * Each box is one search key; "any" folds them with OR, "all" lists them (an
 * IMAP list is an AND). The query box is parsed and translated exactly as the
 * search box is, so a rule can say anything a search can. Has-attachment is
 * not here: it is a structure check applied afterwards (rule_find_matches).
 */
function rule_to_imap_search($match, $now = null) {
    $now = $now ?? time();
    $esc = function ($s) { return str_replace(['\\', '"'], ['\\\\', '\\"'], $s); };
    $parts = [];
    if (!empty($match['from']))      $parts[] = 'FROM "'    . $esc($match['from'])    . '"';
    if (!empty($match['to']))        $parts[] = 'TO "'      . $esc($match['to'])      . '"';
    if (!empty($match['cc']))        $parts[] = 'CC "'      . $esc($match['cc'])      . '"';
    if (!empty($match['subject']))   $parts[] = 'SUBJECT "' . $esc($match['subject']) . '"';
    if (!empty($match['has_words'])) $parts[] = 'TEXT "'    . $esc($match['has_words']) . '"';
    if (!empty($match['not_words'])) $parts[] = 'NOT TEXT "'. $esc($match['not_words']) . '"';
    if (!empty($match['larger']))    $parts[] = 'LARGER '  . (int)$match['larger'];
    if (!empty($match['smaller']))   $parts[] = 'SMALLER ' . (int)$match['smaller'];
    if (!empty($match['older_than'])) $parts[] = 'BEFORE ' . search_imap_date(date('Y-m-d', $now - (int)$match['older_than'] * 86400));
    if (!empty($match['newer_than'])) $parts[] = 'SINCE '  . search_imap_date(date('Y-m-d', $now - (int)$match['newer_than'] * 86400));
    if (!empty($match['query'])) {
        $q = search_to_imap(search_parse((string)$match['query'], $now), false);
        if ($q !== 'ALL') $parts[] = $q;
    }
    if (empty($parts)) return 'ALL';
    if (($match['mode'] ?? 'all') === 'any' && count($parts) > 1) return rule_or_join($parts);
    return implode(' ', $parts);
}

/** Walk an IMAP body structure looking for any attachment part. */
function rule_structure_has_attachment($struct) {
    if (!$struct) return false;
    if (!empty($struct->disposition) && strcasecmp($struct->disposition, 'attachment') === 0) return true;
    if (!empty($struct->ifdisposition) && !empty($struct->disposition) && strcasecmp($struct->disposition, 'attachment') === 0) return true;
    if (isset($struct->dparameters) && is_array($struct->dparameters)) {
        foreach ($struct->dparameters as $p) {
            if (strcasecmp($p->attribute ?? '', 'filename') === 0 && !empty($p->value)) return true;
        }
    }
    if (isset($struct->parameters) && is_array($struct->parameters)) {
        foreach ($struct->parameters as $p) {
            if (strcasecmp($p->attribute ?? '', 'name') === 0 && !empty($p->value)) {
                // Ensure it's not the message itself (text/* with name is rare)
                $type = ($struct->type ?? -1);
                if ($type !== 0 /* TEXT */ && $type !== 1 /* MULTIPART */) return true;
            }
        }
    }
    if (!empty($struct->parts) && is_array($struct->parts)) {
        foreach ($struct->parts as $part) {
            if (rule_structure_has_attachment($part)) return true;
        }
    }
    return false;
}

/* ---------- matching and acting — shared by the endpoint and the scheduler ---------- */

require_once __DIR__ . '/util.php';   // resolve_folder(), expunge_only()
require_once __DIR__ . '/imap.php';   // imap_session_ref()

/**
 * Find UIDs in $folder that match $rule's criteria. Optional $minUid restricts
 * to UID > $minUid. has_attachment is post-filtered against bodystructure.
 * Returns array of UIDs (ints).
 */
function rule_find_matches($mbox, $rule, $minUid = 0, $cap = 1000) {
    $criteria = rule_to_imap_search($rule['match']);
    $found = @imap_search($mbox, $criteria, SE_UID);
    if (!is_array($found)) return [];
    $found = array_map('intval', $found);
    if ($minUid > 0) $found = array_values(array_filter($found, fn($u) => $u > $minUid));

    if ($rule['match']['has_attachment']) {
        $filtered = [];
        $i = 0;
        foreach ($found as $uid) {
            if (count($filtered) >= $cap) break;
            $struct = @imap_fetchstructure($mbox, $uid, FT_UID);
            if ($struct && rule_structure_has_attachment($struct)) {
                $filtered[] = $uid;
            }
            if (++$i > 5000) break; // safety
        }
        $found = $filtered;
    }

    if (count($found) > $cap) $found = array_slice($found, -$cap);
    return $found;
}

function apply_rule_actions($mbox, $folder, $uids, $actions) {
    if (empty($uids)) return ['matched' => 0];
    $set = implode(',', $uids);

    if (!empty($actions['mark_read'])) {
        @imap_setflag_full($mbox, $set, '\\Seen', ST_UID);
    }
    if (!empty($actions['star'])) {
        @imap_setflag_full($mbox, $set, '\\Flagged', ST_UID);
    }
    if (!empty($actions['delete'])) {
        $trash = resolve_folder($mbox, imap_session_ref(), ['trash', 'deleted', 'bin']);
        if (!$trash) {
            // No Trash folder: nothing was moved. Saying deleted=true here reported
            // a rule as having deleted mail that is still sitting in the inbox.
            return ['matched' => count($uids), 'deleted' => false, 'error' => 'No Trash folder found'];
        }
        @imap_mail_move($mbox, $set, $trash, CP_UID);
        expunge_only($mbox, $uids);
        return ['matched' => count($uids), 'deleted' => true];
    }
    if (!empty($actions['move_to']) && !preg_match('/[{}\x00-\x1F\x7F]/', $actions['move_to'])) {
        @imap_mail_move($mbox, $set, $actions['move_to'], CP_UID);
        expunge_only($mbox, $uids);
        return ['matched' => count($uids), 'moved_to' => $actions['move_to']];
    }
    if (!empty($actions['skip_inbox'])) {
        // "Archive" — ranked like every other special folder, so a decoy such as
        // "Projects/archive-old" cannot win over the real Archive.
        $archive = resolve_folder($mbox, imap_session_ref(), ['archive']);
        if ($archive) {
            @imap_mail_move($mbox, $set, $archive, CP_UID);
            expunge_only($mbox, $uids);
            return ['matched' => count($uids), 'archived' => true];
        }
    }
    return ['matched' => count($uids)];
}
