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
        'label'    => [], 'not_label' => [],
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
            case 'label':   $p[$not ? 'not_label'   : 'label'][]   = $v; break;

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
/**
 * The IMAP keyword a label is stored under: a fixed prefix plus the label's
 * slug, so a label named "Forwarded" can never collide with the $Forwarded
 * keyword other clients set. Lowercase, [a-z0-9_], at most 32 characters.
 */
function search_label_slug($name) {
    $slug = mb_strtolower(trim((string)$name), 'UTF-8');
    // Common Latin accents fold the same way on every host; iconv's
    // transliteration differs by platform (macOS writes Caf'e), so it is only
    // the fallback for what the table does not cover.
    static $fold = ['à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
                    'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','œ'=>'oe',
                    'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','ß'=>'ss'];
    $slug = strtr($slug, $fold);
    if (function_exists('iconv') && preg_match('/[^\x00-\x7F]/', $slug)) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug); if ($t !== false) $slug = strtolower($t); }
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return substr($slug, 0, 32);
}
function search_label_keyword($nameOrSlug) { return '$lbl_' . search_label_slug($nameOrSlug); }

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

    foreach ($p['label']     ?? [] as $l) if (search_label_slug($l) !== '') $parts[] = 'KEYWORD '   . search_label_keyword($l);
    foreach ($p['not_label'] ?? [] as $l) if (search_label_slug($l) !== '') $parts[] = 'UNKEYWORD ' . search_label_keyword($l);

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

/* ===========================================================================
 * Understanding a sentence
 *
 * Typing three words from a message you half-remember used to find nothing.
 * Each word had to appear in Subject, From or To — not the body — and all of
 * them had to match, so "quote for safari" failed on "for" alone, and on
 * "quote" against a subject reading "Quotation".
 *
 * So: words are stemmed, words that carry no meaning are not required, the
 * whole sentence is tried as a phrase first, and when nothing has every word
 * the search falls back to the messages that have most of them, best first,
 * and says so. Everything here is a pure function over the parsed query.
 * ======================================================================== */

/** Words that are in every message and so cannot narrow anything down. */
function search_is_stop($w) {
    static $stop = ['a'=>1,'an'=>1,'and'=>1,'any'=>1,'are'=>1,'as'=>1,'at'=>1,'be'=>1,'been'=>1,'but'=>1,'by'=>1,
        'can'=>1,'did'=>1,'do'=>1,'does'=>1,'for'=>1,'from'=>1,'had'=>1,'has'=>1,'have'=>1,'he'=>1,'her'=>1,'his'=>1,
        'how'=>1,'i'=>1,'if'=>1,'in'=>1,'is'=>1,'it'=>1,'its'=>1,'me'=>1,'my'=>1,'no'=>1,'not'=>1,'of'=>1,'on'=>1,
        'or'=>1,'our'=>1,'out'=>1,'re'=>1,'she'=>1,'so'=>1,'that'=>1,'the'=>1,'their'=>1,'them'=>1,'then'=>1,
        'there'=>1,'these'=>1,'they'=>1,'this'=>1,'to'=>1,'too'=>1,'up'=>1,'us'=>1,'was'=>1,'we'=>1,'were'=>1,
        'what'=>1,'when'=>1,'where'=>1,'which'=>1,'who'=>1,'why'=>1,'will'=>1,'with'=>1,'you'=>1,'your'=>1];
    return isset($stop[mb_strtolower(trim((string)$w))]);
}

/**
 * The shortest form of a word that still means it.
 *
 * IMAP matches substrings, so searching the stem finds every ending: "quot"
 * finds quote, quotes, quoting AND quotation, which is the match people
 * expected and never got. Short words are left alone — trimming "car" to "ca"
 * would match everything.
 */
function search_stem($w) {
    $w = mb_strtolower(trim((string)$w));
    if ($w === '' || mb_strlen($w) < 4 || !preg_match('/^[a-z]+$/', $w)) return $w;

    $strip = function ($word, array $rules) {
        foreach ($rules as $suffix => $replace) {
            $len = mb_strlen($suffix);
            if (mb_strlen($word) - $len + mb_strlen($replace) < 3) continue;   // never leave less than a word
            if (mb_substr($word, -$len) === $suffix) return mb_substr($word, 0, mb_strlen($word) - $len) . $replace;
        }
        return $word;
    };

    // Plural first, so "quotations" becomes "quotation" and can then lose its
    // ending too — one pass left it half-stemmed and missing "quote".
    if (mb_substr($w, -2) !== 'ss') $w = $strip($w, ['ies' => 'y', 'sses' => 'ss', 'es' => '', 's' => '']);
    $w = $strip($w, ['ational' => '', 'ization' => '', 'fulness' => '', 'iveness' => '',
                     'ation' => '', 'ement' => '', 'ness' => '', 'ment' => '', 'tion' => '', 'sion' => '',
                     'ance' => '', 'ence' => '', 'ing' => '', 'ity' => '', 'ive' => '', 'ful' => '', 'ous' => '',
                     'ion' => '', 'est' => '', 'ly' => '', 'ed' => '', 'er' => '']);
    if (mb_substr($w, -1) === 'e') $w = $strip($w, ['e' => '']);
    return $w;
}

/**
 * The bare words of a query, sorted into the ones that must match and the
 * ones that only help rank. A quoted "phrase" is never broken up or stemmed:
 * someone who typed quotes meant those words in that order.
 */
function search_terms(array $p) {
    $required = []; $optional = []; $exact = [];
    foreach ($p['text'] as $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        if (preg_match('/\s/', $t)) { $exact[] = $t; continue; }   // a quoted phrase, kept whole
        if (search_is_stop($t)) { $optional[] = $t; continue; }
        $stem = search_stem($t);
        if ($stem !== '' && !in_array($stem, $required, true)) $required[] = $stem;
    }
    // The whole thing as one phrase, for the first and best attempt.
    $phrase = '';
    $words  = array_filter($p['text'], fn($t) => trim((string)$t) !== '');
    if (count($words) > 1 && !$exact) $phrase = implode(' ', $words);
    elseif (count($exact) === 1 && !$words) $phrase = $exact[0];
    return ['required' => $required, 'optional' => $optional, 'exact' => $exact, 'phrase' => $phrase,
            'words' => array_values($words)];
}

/** One IMAP key for a word: the whole message by default, headers when asked. */
function search_word_key($word, $fullText = true) {
    $q = search_imap_quote($word);
    return $fullText ? "TEXT $q" : "OR OR SUBJECT $q FROM $q TO $q";
}

/** The OR of several keys, nested as IMAP requires (OR takes exactly two). */
function search_or_keys(array $keys) {
    $keys = array_values(array_filter($keys, fn($k) => $k !== ''));
    if (!$keys) return '';
    if (count($keys) === 1) return $keys[0];
    $expr = array_pop($keys);
    while ($keys) $expr = 'OR ' . array_pop($keys) . ' ' . $expr;
    return $expr;
}

/**
 * What the text part of the query becomes, at one rung of the ladder:
 *   phrase — the sentence, in that order
 *   all    — every meaningful word, stemmed
 *   any    — at least one of them (the ranking sorts out which is best)
 */
function search_text_criterion(array $terms, $mode, $fullText = true) {
    if ($mode === 'phrase') {
        return $terms['phrase'] === '' ? '' : search_word_key($terms['phrase'], $fullText);
    }
    $words = array_merge($terms['required'], $terms['exact']);
    if (!$words) $words = $terms['optional'];           // a query of nothing but stop words still means them
    if (!$words) return '';
    if ($mode === 'any') return search_or_keys(array_map(fn($w) => search_word_key($w, $fullText), $words));
    return implode(' ', array_map(fn($w) => search_word_key($w, $fullText), $words));   // a list is an AND
}

/**
 * The attempts to make, best first. Each carries the IMAP criterion and the
 * sentence the page shows when that rung is the one that found something.
 * Operators (from:, is:, has:, after: …) are not relaxed — only the words are.
 */
function search_plan(array $p, $fullText = true) {
    $terms = search_terms($p);
    $rest  = search_to_imap(array_merge($p, ['text' => [], 'not_text' => $p['not_text']]), false);
    $rest  = ($rest === 'ALL') ? '' : $rest;
    $nots  = [];
    foreach ($p['not_text'] as $t) $nots[] = 'NOT ' . search_word_key($t, $fullText);
    $fixed = trim($rest . ' ' . implode(' ', $nots));

    $join = function ($text) use ($fixed) {
        $c = trim(($text === '' ? '' : $text) . ' ' . $fixed);
        return $c === '' ? 'ALL' : $c;
    };
    $plan  = [];
    $multi = count($terms['words']) > 1 || $terms['exact'];
    if ($terms['phrase'] !== '') {
        $plan[] = ['mode' => 'phrase', 'criterion' => $join(search_text_criterion($terms, 'phrase', $fullText)),
                   'note' => 'Showing messages containing that exact wording.'];
    }
    $all = search_text_criterion($terms, 'all', $fullText);
    if ($all !== '') {
        // Only say something when it would otherwise be a surprise. Falling
        // from "the whole sentence" to "all of these words" is what everyone
        // means anyway; saying so on every search is noise. Falling back from
        // a phrase the user put in quotes is not — they asked for those words
        // in that order.
        $plan[] = ['mode' => 'all', 'criterion' => $join($all),
                   'note' => $terms['exact'] ? 'Nothing has that exact phrase — showing messages with all of those words.' : ''];
    }
    if ($multi && count(array_merge($terms['required'], $terms['exact'])) > 1) {
        $plan[] = ['mode' => 'any', 'criterion' => $join(search_text_criterion($terms, 'any', $fullText)),
                   'note' => 'No message has all of those words — showing the closest matches, best first.'];
    }
    if (!$plan) $plan[] = ['mode' => 'filters', 'criterion' => $join(''), 'note' => ''];
    return ['terms' => $terms, 'attempts' => $plan];
}

/**
 * How well one message answers the query. Pure: given the row and which of
 * the words the server said it matched.
 *
 * A word in the subject is worth more than the same word in the body, because
 * that is what people mean by "the one about X"; a sender match counts too;
 * and more of the words beats fewer of them, which is what makes the "closest
 * matches" rung useful rather than a pile.
 */
function search_score(array $row, array $terms, array $matched = [], $now = null) {
    $now     = $now ?? time();
    $subject = mb_strtolower((string)($row['subject'] ?? ''));
    $from    = mb_strtolower((string)($row['from_name'] ?? '') . ' ' . (string)($row['from_addr'] ?? ''));
    $words   = array_merge($terms['required'], $terms['exact']);
    $score   = 0;

    if ($terms['phrase'] !== '' && $subject !== '' && mb_strpos($subject, mb_strtolower($terms['phrase'])) !== false) $score += 120;
    foreach ($words as $w) {
        $w = mb_strtolower($w);
        if ($w === '') continue;
        $inSubject = $subject !== '' && mb_strpos($subject, $w) !== false;
        $inFrom    = $from    !== '' && mb_strpos($from, $w) !== false;
        $inBody    = in_array($w, $matched, true);
        if ($inSubject) $score += 30;
        if ($inFrom)    $score += 12;
        if ($inBody && !$inSubject && !$inFrom) $score += 8;    // it is in there somewhere
    }
    // Every word present beats most of them, whatever the fields.
    $have = 0;
    foreach ($words as $w) {
        $w = mb_strtolower($w);
        if (($subject !== '' && mb_strpos($subject, $w) !== false) || ($from !== '' && mb_strpos($from, $w) !== false) || in_array($w, $matched, true)) $have++;
    }
    if ($words && $have === count($words)) $score += 40;
    $score += $have * 10;
    // A nudge for recency, never enough to beat a better match: at most 9.
    $age = max(0, $now - (int)($row['timestamp'] ?? 0));
    $score += max(0, 9 - (int)floor($age / (30 * 86400)));
    return $score;
}

/** Best first, then newest. Pure. */
function search_rank(array $rows, array $terms, array $matchedByUid = [], $now = null) {
    foreach ($rows as $i => $r) {
        $uid = (string)($r['uid'] ?? '');
        $rows[$i]['score'] = search_score($r, $terms, $matchedByUid[$uid] ?? [], $now);
    }
    usort($rows, function ($a, $b) {
        if ($a['score'] !== $b['score']) return $b['score'] - $a['score'];
        return (int)($b['timestamp'] ?? 0) - (int)($a['timestamp'] ?? 0);
    });
    return $rows;
}

