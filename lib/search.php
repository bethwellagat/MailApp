<?php
/**
 * Search query language — the one place that decides what a search means.
 *
 * Users type Gmail-style operators into the single search box:
 *
 *   from:thuraya has:attachment after:2026-07-01 -is:read "pay slip"
 *
 * This file parses that into a structure, turns the structure into an IMAP
 * SEARCH criterion, and describes it back in words for the filter chips. All
 * three are pure functions: no IMAP, no session, no clock unless one is passed
 * in, so every rule here is covered by tests that run without a mail server.
 *
 * Two operators are deliberately NOT translated to IMAP, because IMAP has no
 * key for them: `in:` chooses which folders the endpoint opens, and `has:`
 * is answered from the attachment index after the server search. The parser
 * still owns their meaning so the endpoint never re-parses the query.
 */

const SEARCH_MAX_QUERY = 300;
const SEARCH_MAX_TERMS = 16;

/* ---------- parsing ---------- */

/**
 * Split the raw query into tokens: bare words, "quoted phrases", key:value
 * and key:"quoted value", each optionally prefixed with - to negate.
 *
 * Returns a list of ['key' => string|'', 'value' => string, 'not' => bool].
 * A quote that never closes runs to the end of the query rather than failing:
 * someone mid-typing should still get results for what they have so far.
 */
function search_tokenize($q) {
    $q = (string)$q;
    $q = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $q);
    if (mb_strlen($q, 'UTF-8') > SEARCH_MAX_QUERY) $q = mb_substr($q, 0, SEARCH_MAX_QUERY, 'UTF-8');

    $out = [];
    $len = mb_strlen($q, 'UTF-8');
    $i   = 0;
    while ($i < $len) {
        $ch = mb_substr($q, $i, 1, 'UTF-8');
        if ($ch === ' ') { $i++; continue; }

        $not = false;
        if ($ch === '-') {
            $next = $i + 1 < $len ? mb_substr($q, $i + 1, 1, 'UTF-8') : '';
            // A lone "-" means nothing: skip it. One glued to what follows negates
            // it. (A hyphen INSIDE a word never gets here — only a token's first
            // character is examined, so pay-slip stays one word.)
            if ($next === '' || $next === ' ') { $i++; continue; }
            $not = true; $i++; $ch = $next;
        }

        $key = '';
        $val = '';

        if ($ch === '"') {
            [$val, $i] = _search_read_quoted($q, $i, $len);
        } else {
            // read up to whitespace or a colon
            $word = '';
            while ($i < $len) {
                $c = mb_substr($q, $i, 1, 'UTF-8');
                if ($c === ' ') break;
                if ($c === ':' && $word !== '' && preg_match('/^[a-z]+$/i', $word)) {
                    // key:value — the value may be quoted
                    $i++;
                    $key = strtolower($word);
                    $word = '';
                    if ($i < $len && mb_substr($q, $i, 1, 'UTF-8') === '"') {
                        [$val, $i] = _search_read_quoted($q, $i, $len);
                    } else {
                        while ($i < $len) {
                            $c2 = mb_substr($q, $i, 1, 'UTF-8');
                            if ($c2 === ' ') break;
                            $val .= $c2; $i++;
                        }
                    }
                    $word = null;
                    break;
                }
                $word .= $c; $i++;
            }
            if ($word !== null) $val = $word;
        }

        $val = trim($val);
        if ($key === '' && $val === '') continue;
        $out[] = ['key' => $key, 'value' => $val, 'not' => $not];
        if (count($out) >= SEARCH_MAX_TERMS) break;
    }
    return $out;
}

/** Read a "quoted string" starting at the opening quote; returns [text, index after]. */
function _search_read_quoted($q, $i, $len) {
    $i++; // skip the opening quote
    $val = '';
    while ($i < $len) {
        $c = mb_substr($q, $i, 1, 'UTF-8');
        if ($c === '"') { $i++; return [$val, $i]; }
        $val .= $c; $i++;
    }
    return [$val, $i]; // unterminated: take the rest
}

/**
 * Parse a query into its meaning.
 *
 * The result is a flat array with a fixed set of keys, so callers can rely on
 * every key existing. Lists hold strings; dates hold Y-m-d strings or null;
 * sizes hold byte counts or null. 'errors' lists anything that could not be
 * understood, in words a user can act on — an unknown operator is not fatal,
 * it is reported and the rest of the query still runs.
 */
function search_parse($q, $now = null) {
    $now = $now ?? time();
    $p = [
        'raw'      => trim((string)$q),
        'text'     => [], 'not_text'    => [],
        'from'     => [], 'not_from'    => [],
        'to'       => [], 'not_to'      => [],
        'subject'  => [], 'not_subject' => [],
        'is'       => [], 'not_is'      => [],
        'after'    => null, 'before'    => null, 'on' => null,
        'larger'   => null, 'smaller'   => null,
        'in'       => null,
        'has'      => null,
        'errors'   => [],
    ];

    foreach (search_tokenize($q) as $t) {
        $key = $t['key']; $v = $t['value']; $not = $t['not'];

        if ($key === '') {
            if ($v !== '') $p[$not ? 'not_text' : 'text'][] = $v;
            continue;
        }
        if ($v === '') { $p['errors'][] = "\"$key:\" needs a value"; continue; }

        switch ($key) {
            case 'from':    $p[$not ? 'not_from'    : 'from'][]    = $v; break;
            case 'to':
            case 'cc':      $p[$not ? 'not_to'      : 'to'][]      = $v; break;
            case 'subject': $p[$not ? 'not_subject' : 'subject'][] = $v; break;

            case 'is':
                $flag = _search_is_flag($v);
                if ($flag === null) { $p['errors'][] = "\"is:$v\" is not a state this search knows (try unread, read, flagged, answered)"; break; }
                // -is:unread means is:read, and so on: resolve the double negative.
                if ($not) $flag = _search_is_negate($flag);
                $p['is'][] = $flag;
                break;

            case 'has':
                $h = strtolower($v);
                if (in_array($h, ['attachment', 'attachments', 'file', 'files'], true)) {
                    $p['has'] = $not ? 'no-attachment' : 'attachment';
                } elseif (in_array($h, ['no-attachment', 'noattachment', 'no-attachments', 'nothing'], true)) {
                    $p['has'] = $not ? 'attachment' : 'no-attachment';
                } else {
                    $p['errors'][] = "\"has:$v\" is not something this search can check (try has:attachment)";
                }
                break;

            case 'in':
            case 'folder':
                $p['in'] = $v;   // resolved by the endpoint against the real folder list
                break;

            case 'after':
            case 'since':
            case 'newer':
            case 'newer_than':
                $d = _search_date($v, $now, -1);
                if ($d === null) { $p['errors'][] = "\"$key:$v\" is not a date (use 2026-07-01, or 7d / 2w / 3m / 1y)"; break; }
                $p['after'] = $d;
                break;

            case 'before':
            case 'until':
            case 'older':
            case 'older_than':
                $d = _search_date($v, $now, -1);
                if ($d === null) { $p['errors'][] = "\"$key:$v\" is not a date (use 2026-09-30, or 7d / 2w / 3m / 1y)"; break; }
                $p['before'] = $d;
                break;

            case 'on':
            case 'date':
                $d = _search_date($v, $now, -1);
                if ($d === null) { $p['errors'][] = "\"$key:$v\" is not a date (use 2026-08-14)"; break; }
                $p['on'] = $d;
                break;

            case 'larger':
            case 'bigger':
            case 'size':
                $n = _search_size($v);
                if ($n === null) { $p['errors'][] = "\"$key:$v\" is not a size (try 5M or 500K)"; break; }
                $p['larger'] = $n;
                break;

            case 'smaller':
                $n = _search_size($v);
                if ($n === null) { $p['errors'][] = "\"$key:$v\" is not a size (try 1M or 50K)"; break; }
                $p['smaller'] = $n;
                break;

            default:
                // Not an operator we know: treat "key:value" as the words it is,
                // so a search for a literal like "re:123" still finds it.
                $p[$not ? 'not_text' : 'text'][] = $key . ':' . $v;
        }
    }

    $p['is'] = array_values(array_unique($p['is']));
    return $p;
}

function _search_is_flag($v) {
    switch (strtolower($v)) {
        case 'unread': case 'unseen': case 'new':            return 'unread';
        case 'read': case 'seen':                             return 'read';
        case 'flagged': case 'starred': case 'star':          return 'flagged';
        case 'unflagged': case 'unstarred':                   return 'unflagged';
        case 'answered': case 'replied':                      return 'answered';
        case 'unanswered': case 'unreplied':                  return 'unanswered';
    }
    return null;
}

function _search_is_negate($flag) {
    static $opp = ['unread' => 'read', 'read' => 'unread', 'flagged' => 'unflagged', 'unflagged' => 'flagged',
                   'answered' => 'unanswered', 'unanswered' => 'answered'];
    return $opp[$flag] ?? $flag;
}

/**
 * A date as Y-m-d, from an absolute 2026-07-01 / 2026/7/1, or a relative
 * 7d / 2w / 3m / 1y counted back from $now. Returns null if neither.
 */
function _search_date($v, $now, $_unused = null) {
    $v = trim($v);
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $v, $m)) {
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (preg_match('/^(\d{1,4})\s*([dwmy])$/i', $v, $m)) {
        $n = (int)$m[1];
        if ($n <= 0) return null;
        $unit = strtolower($m[2]);
        $spec = ['d' => "-$n days", 'w' => "-$n weeks", 'm' => "-$n months", 'y' => "-$n years"][$unit];
        $ts = strtotime($spec, $now);
        return $ts === false ? null : date('Y-m-d', $ts);
    }
    return null;
}

/** A size in bytes from 5M / 500K / 2G / 1048576. Null if unreadable or zero. */
function _search_size($v) {
    $v = strtolower(trim($v));
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([kmg])?b?$/', $v, $m)) return null;
    $n = (float)$m[1];
    $mult = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][$m[2] ?? ''];
    $bytes = (int)round($n * $mult);
    return $bytes > 0 ? $bytes : null;
}

/* ---------- translating ---------- */

/** Escape a value for an IMAP quoted string: only backslash and quote matter. */
function search_imap_quote($v) {
    $v = str_replace(["\r", "\n"], ' ', (string)$v);
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

/** IMAP's date form: 1-Jul-2026. */
function search_imap_date($ymd) {
    $ts = strtotime($ymd . ' 00:00:00 UTC');
    return $ts === false ? null : gmdate('j-M-Y', $ts);
}

/**
 * The IMAP SEARCH criterion for a parsed query.
 *
 * IMAP ANDs every key you list; OR takes exactly two keys, so three options
 * nest as "OR OR a b c"; NOT takes one key, and an OR expression counts as one.
 * The criterion is built in a fixed order so that the same query always
 * produces the same string, which is what makes it testable and cacheable.
 *
 * `$fullText` adds BODY to each bare term. It is opt-in because a BODY search
 * makes a server without a full-text index read every message.
 */
function search_to_imap(array $p, $fullText = false) {
    $parts = [];

    $textKey = function ($t) use ($fullText) {
        $q = search_imap_quote($t);
        return $fullText
            ? "OR OR OR SUBJECT $q FROM $q TO $q BODY $q"
            : "OR OR SUBJECT $q FROM $q TO $q";
    };

    foreach ($p['text']     as $t) $parts[] = $textKey($t);
    foreach ($p['not_text'] as $t) $parts[] = 'NOT ' . $textKey($t);

    foreach ($p['from']     as $v) $parts[] = 'FROM ' . search_imap_quote($v);
    foreach ($p['not_from'] as $v) $parts[] = 'NOT FROM ' . search_imap_quote($v);

    foreach ($p['to']       as $v) { $q = search_imap_quote($v); $parts[] = "OR TO $q CC $q"; }
    foreach ($p['not_to']   as $v) { $q = search_imap_quote($v); $parts[] = "NOT OR TO $q CC $q"; }

    foreach ($p['subject']     as $v) $parts[] = 'SUBJECT ' . search_imap_quote($v);
    foreach ($p['not_subject'] as $v) $parts[] = 'NOT SUBJECT ' . search_imap_quote($v);

    static $flagKey = ['unread' => 'UNSEEN', 'read' => 'SEEN', 'flagged' => 'FLAGGED', 'unflagged' => 'UNFLAGGED',
                       'answered' => 'ANSWERED', 'unanswered' => 'UNANSWERED'];
    foreach ($p['is'] as $f) if (isset($flagKey[$f])) $parts[] = $flagKey[$f];

    if ($p['on'] !== null) {
        $parts[] = 'ON ' . search_imap_date($p['on']);
    } else {
        if ($p['after']  !== null) $parts[] = 'SINCE '  . search_imap_date($p['after']);
        if ($p['before'] !== null) $parts[] = 'BEFORE ' . search_imap_date($p['before']);
    }

    if ($p['larger']  !== null) $parts[] = 'LARGER '  . (int)$p['larger'];
    if ($p['smaller'] !== null) $parts[] = 'SMALLER ' . (int)$p['smaller'];

    return $parts ? implode(' ', $parts) : 'ALL';
}

/**
 * True when the query has nothing the server can search on — only `in:` or
 * `has:` — so the endpoint knows it is listing a folder, not searching it.
 */
function search_is_unconstrained(array $p) {
    return search_to_imap($p, false) === 'ALL';
}

/* ---------- describing ---------- */

/**
 * The query in words, one entry per constraint, for the filter chips and the
 * results header. Order is stable and matches how a person would read it.
 */
function search_describe(array $p) {
    $out = [];
    foreach ($p['text']     as $t) $out[] = ['key' => 'text',    'label' => '“' . $t . '”'];
    foreach ($p['not_text'] as $t) $out[] = ['key' => 'text',    'label' => 'not “' . $t . '”'];
    foreach ($p['from']     as $v) $out[] = ['key' => 'from',    'label' => 'from ' . $v];
    foreach ($p['not_from'] as $v) $out[] = ['key' => 'from',    'label' => 'not from ' . $v];
    foreach ($p['to']       as $v) $out[] = ['key' => 'to',      'label' => 'to ' . $v];
    foreach ($p['not_to']   as $v) $out[] = ['key' => 'to',      'label' => 'not to ' . $v];
    foreach ($p['subject']     as $v) $out[] = ['key' => 'subject', 'label' => 'subject ' . $v];
    foreach ($p['not_subject'] as $v) $out[] = ['key' => 'subject', 'label' => 'subject not ' . $v];
    foreach ($p['is'] as $f) $out[] = ['key' => 'is', 'label' => $f, 'value' => $f];
    if ($p['has'] === 'attachment')    $out[] = ['key' => 'has', 'label' => 'has attachment',  'value' => 'attachment'];
    if ($p['has'] === 'no-attachment') $out[] = ['key' => 'has', 'label' => 'no attachment',   'value' => 'no-attachment'];
    if ($p['on'] !== null)     $out[] = ['key' => 'on',     'label' => 'on ' . _search_nice_date($p['on'])];
    if ($p['after'] !== null)  $out[] = ['key' => 'after',  'label' => 'after ' . _search_nice_date($p['after'])];
    if ($p['before'] !== null) $out[] = ['key' => 'before', 'label' => 'before ' . _search_nice_date($p['before'])];
    if ($p['larger'] !== null)  $out[] = ['key' => 'larger',  'label' => 'larger than ' . _search_nice_size($p['larger'])];
    if ($p['smaller'] !== null) $out[] = ['key' => 'smaller', 'label' => 'smaller than ' . _search_nice_size($p['smaller'])];
    if ($p['in'] !== null) $out[] = ['key' => 'in', 'label' => 'in ' . $p['in'], 'value' => $p['in']];
    return $out;
}

function _search_nice_date($ymd) {
    $ts = strtotime($ymd . ' 00:00:00 UTC');
    return $ts === false ? $ymd : gmdate('j M Y', $ts);
}

function _search_nice_size($bytes) {
    if ($bytes >= 1073741824) return rtrim(rtrim(number_format($bytes / 1073741824, 1), '0'), '.') . ' GB';
    if ($bytes >= 1048576)    return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
    if ($bytes >= 1024)       return rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.') . ' KB';
    return $bytes . ' B';
}

/* ---------- folder scope ---------- */

/**
 * Which of the mailbox's folders an `in:` value names.
 *
 * Accepts a type word (inbox, sent, drafts, archive, trash, spam, junk), a
 * folder's display name, or the raw IMAP name, matched without case. Returns
 * the real folder name, 'anywhere' for all/anywhere/everywhere, or null when
 * nothing matches — the endpoint then says so rather than silently searching
 * the default set.
 */
function search_resolve_folder($in, array $folders) {
    $want = strtolower(trim((string)$in));
    if ($want === '') return null;
    if (in_array($want, ['anywhere', 'all', 'everywhere', '*'], true)) return 'anywhere';

    // exact (case-insensitive) match on the full name first
    foreach ($folders as $f) if (strtolower($f) === $want) return $f;

    // then on the last path segment, so in:archive finds INBOX.Archive
    foreach ($folders as $f) {
        $leaf = strtolower(preg_replace('#^.*[./]#', '', $f));
        if ($leaf === $want) return $f;
    }

    // type words map onto the usual spellings
    static $alias = [
        'inbox' => ['inbox'], 'sent' => ['sent', 'sent items', 'sent mail', 'sent messages'],
        'drafts' => ['drafts', 'draft'], 'archive' => ['archive', 'archives', 'all mail'],
        'trash' => ['trash', 'deleted', 'deleted items', 'deleted messages', 'bin'],
        'spam' => ['spam', 'junk', 'junk e-mail', 'junk email', 'bulk mail'], 'junk' => ['junk', 'spam'],
    ];
    // The word typed may be any spelling in a group ("deleted" for trash), and
    // the folder may use any other spelling in the same group ("Deleted Items").
    foreach ($alias as $group) {
        if (!in_array($want, $group, true)) continue;
        foreach ($folders as $f) {
            $leaf = strtolower(preg_replace('#^.*[./]#', '', $f));
            if (in_array($leaf, $group, true)) return $f;
        }
    }

    // finally a substring, so in:arch still finds Archive
    foreach ($folders as $f) if (strpos(strtolower($f), $want) !== false) return $f;
    return null;
}
