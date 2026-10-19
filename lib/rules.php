<?php
require_once __DIR__ . '/util.php';
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
 *           "from": "", "to": "", "subject": "",
 *           "has_words": "", "not_words": "",
 *           "has_attachment": false
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

    $clean = [
        'id'         => $r['id'] ?? rule_uuid(),
        'enabled'    => array_key_exists('enabled', $r) ? (bool)$r['enabled'] : true,
        'match'      => [
            'from'           => mb_substr(trim((string)($match['from']      ?? '')), 0, 200),
            'to'             => mb_substr(trim((string)($match['to']        ?? '')), 0, 200),
            'subject'        => mb_substr(trim((string)($match['subject']   ?? '')), 0, 200),
            'has_words'      => mb_substr(trim((string)($match['has_words'] ?? '')), 0, 200),
            'not_words'      => mb_substr(trim((string)($match['not_words'] ?? '')), 0, 200),
            'has_attachment' => !empty($match['has_attachment']),
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
             || $clean['match']['subject'] !== ''
             || $clean['match']['has_words'] !== ''
             || $clean['match']['not_words'] !== ''
             || $clean['match']['has_attachment'];
    $hasAction = $clean['actions']['skip_inbox']
              || $clean['actions']['mark_read']
              || $clean['actions']['star']
              || $clean['actions']['move_to'] !== ''
              || $clean['actions']['delete'];
    if (!$hasMatch || !$hasAction) return null;
    return $clean;
}

/** Build an IMAP SEARCH expression from a rule's match criteria. */
function rule_to_imap_search($match) {
    $esc = function ($s) { return str_replace(['\\', '"'], ['\\\\', '\\"'], $s); };
    $parts = [];
    if (!empty($match['from']))      $parts[] = 'FROM "'    . $esc($match['from'])    . '"';
    if (!empty($match['to']))        $parts[] = 'TO "'      . $esc($match['to'])      . '"';
    if (!empty($match['subject']))   $parts[] = 'SUBJECT "' . $esc($match['subject']) . '"';
    if (!empty($match['has_words'])) $parts[] = 'TEXT "'    . $esc($match['has_words']) . '"';
    if (!empty($match['not_words'])) $parts[] = 'NOT TEXT "'. $esc($match['not_words']) . '"';
    if (empty($parts)) return 'ALL';
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
