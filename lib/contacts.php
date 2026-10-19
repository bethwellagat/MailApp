<?php
/**
 * Per-user contact address book. Keyed by sha256(email), stored as JSON in
 * data/contacts/. The data/ directory's .htaccess denies all web access.
 *
 * The book is harvested passively from mail the user sends and threads they
 * open, then surfaced as compose autocomplete. It is a convenience cache of
 * addresses the user has interacted with, not authoritative contact data.
 *
 * Stored shape (JSON object keyed by lowercased email):
 *   { "a@b.com": {"email":"a@b.com","name":"A B","count":3,"last_seen":1700000000}, ... }
 */

define('CONTACTS_MAX_ENTRIES', 2000);

function _contacts_dir() {
    return __DIR__ . '/../data/contacts';
}

function _contacts_file($email) {
    $dir = _contacts_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/' . hash('sha256', strtolower(trim($email))) . '.json';
}

function load_contacts($email) {
    if (!$email) return [];
    $file = _contacts_file($email);
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false) return [];
    $data = @json_decode($raw, true);
    if (!is_array($data)) return [];
    return $data;
}

require_once __DIR__ . '/util.php'; // atomic_write_json()

function save_contacts($email, $book) {
    if (!$email || !is_array($book)) return false;
    return atomic_write_json(_contacts_file($email), $book);
}

/** Tidy a display name: MIME-decode encoded words, collapse whitespace, strip quotes. */
function _contacts_clean_name($name) {
    if ($name === '' || $name === null) return '';
    $name = (string)$name;
    if (strpos($name, '=?') !== false && function_exists('mb_decode_mimeheader')) {
        $decoded = @mb_decode_mimeheader($name);
        if (is_string($decoded) && $decoded !== '') $name = $decoded;
    }
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name);
    $name = trim($name, "\"'");
    return trim($name);
}

/**
 * Parse a raw address-list header (e.g. a To/Cc field or user compose input)
 * into a list of ['email'=>, 'name'=>]. Self-contained (no IMAP dependency) so
 * it works anywhere the app is dropped. Splits on , or ; while respecting
 * double-quoted display names and angle-bracketed addresses, so a quoted name
 * containing a comma ("Doe, Jane" <jane@x.com>) stays intact. Returns [] on
 * empty input.
 */
function contacts_parse_list($headerStr) {
    $out = [];
    if (!is_string($headerStr) || trim($headerStr) === '') return $out;

    // Tokenise on separators that are outside quotes and angle brackets.
    $tokens  = [];
    $buf     = '';
    $inQuote = false;
    $inAngle = false;
    $len     = strlen($headerStr);
    for ($i = 0; $i < $len; $i++) {
        $ch = $headerStr[$i];
        if ($ch === '"' && !$inAngle)              { $inQuote = !$inQuote; $buf .= $ch; continue; }
        if ($ch === '<' && !$inQuote)              { $inAngle = true;      $buf .= $ch; continue; }
        if ($ch === '>' && !$inQuote)              { $inAngle = false;     $buf .= $ch; continue; }
        if (($ch === ',' || $ch === ';') && !$inQuote && !$inAngle) { $tokens[] = $buf; $buf = ''; continue; }
        $buf .= $ch;
    }
    $tokens[] = $buf;

    foreach ($tokens as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;
        $name = '';
        if (preg_match('/^(.*)<([^>]+)>\s*$/s', $tok, $m)) {
            $name  = trim($m[1]);
            $email = trim($m[2]);
        } else {
            $email = $tok;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $out[] = ['email' => $email, 'name' => _contacts_clean_name($name)];
    }
    return $out;
}

/**
 * Upsert a batch of ['email'=>, 'name'=>] entries into the owner's book.
 * Bumps an interaction count and last_seen per entry; fills in a display name
 * when a better one becomes available. Skips the owner's own address. Caps the
 * book at CONTACTS_MAX_ENTRIES, evicting the least-used / oldest first.
 * Best-effort: returns the save result, never throws.
 */
function contacts_record($ownerEmail, $entries) {
    if (!$ownerEmail || !is_array($entries) || empty($entries)) return false;
    $ownerKey = strtolower(trim($ownerEmail));

    // Serialize the load→merge→save: contacts are harvested on every send and
    // every thread open, so concurrent requests are common and an unlocked
    // read-modify-write would drop entries. Hold an exclusive lock across the
    // whole critical section (same discipline as lib/prefs.php).
    $lock = @fopen(_contacts_file($ownerEmail) . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $book    = load_contacts($ownerEmail);
    $now     = time();
    $changed = false;

    foreach ($entries as $e) {
        if (!is_array($e)) continue;
        $email = isset($e['email']) ? strtolower(trim((string)$e['email'])) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if ($email === $ownerKey) continue; // never store the user's own address

        $name = isset($e['name']) ? _contacts_clean_name($e['name']) : '';
        if (strcasecmp($name, $email) === 0) $name = ''; // a name that is just the address adds nothing

        // An address merged into another contact credits that contact instead of
        // coming back as a second entry.
        if (!isset($book[$email])) { $alias = contacts_alias_index($book); if (isset($alias[$email])) $email = $alias[$email]; }

        if (isset($book[$email]) && is_array($book[$email])) {
            $book[$email]['count']     = (int)($book[$email]['count'] ?? 0) + 1;
            $book[$email]['last_seen'] = $now;
            $book[$email]['email']     = $email;
            if ($name !== '' && trim((string)($book[$email]['name'] ?? '')) === '') {
                $book[$email]['name'] = $name;
            }
        } else {
            $book[$email] = [
                'email'     => $email,
                'name'      => $name,
                'count'     => 1,
                'last_seen' => $now,
            ];
        }
        $changed = true;
    }

    if (!$changed) { if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); } return false; }

    if (count($book) > CONTACTS_MAX_ENTRIES) {
        uasort($book, function ($a, $b) {
            $ca = (int)($a['count'] ?? 0); $cb = (int)($b['count'] ?? 0);
            if ($ca !== $cb) return $cb - $ca;
            return (int)($b['last_seen'] ?? 0) - (int)($a['last_seen'] ?? 0);
        });
        $book = array_slice($book, 0, CONTACTS_MAX_ENTRIES, true);
    }

    $ok = save_contacts($ownerEmail, $book);
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
    return $ok;
}

/**
 * Convenience harvester for thread/message arrays produced by fetch.php's
 * build_thread_msg(): records the sender plus every To/Cc recipient.
 */
function contacts_harvest_messages($ownerEmail, $messages) {
    if (!$ownerEmail || !is_array($messages)) return false;
    $entries = [];
    foreach ($messages as $m) {
        if (!is_array($m)) continue;
        if (!empty($m['from_addr'])) {
            $entries[] = ['email' => $m['from_addr'], 'name' => $m['from_name'] ?? ''];
        }
        if (!empty($m['to'])) {
            foreach (contacts_parse_list($m['to']) as $e) $entries[] = $e;
        }
        if (!empty($m['cc'])) {
            foreach (contacts_parse_list($m['cc']) as $e) $entries[] = $e;
        }
    }
    if (empty($entries)) return false;
    return contacts_record($ownerEmail, $entries);
}

/**
 * Rank the book against a typed query for compose autocomplete.
 * Ranking: prefix match (email start, name start, or any name-word start)
 * beats substring match; ties broken by interaction count then recency.
 * Empty query returns the most-used / most-recent contacts.
 * Returns a list of ['email'=>, 'name'=>].
 */
function contacts_search($ownerEmail, $query, $limit = 8) {
    $book = load_contacts($ownerEmail);
    if (empty($book)) return [];

    $q     = strtolower(trim((string)$query));
    $limit = max(1, min(50, (int)$limit));

    $scored = [];
    foreach ($book as $key => $rec) {
        if (!is_array($rec)) continue;
        $email = strtolower((string)($rec['email'] ?? $key));
        $name  = strtolower(trim((string)($rec['name'] ?? '')));
        $cnt   = (int)($rec['count'] ?? 0);
        $seen  = (int)($rec['last_seen'] ?? 0);

        if ($q === '') {
            $rank = 0;
        } else {
            $emailPos   = strpos($email, $q);
            $namePos    = $name !== '' ? strpos($name, $q) : false;
            $wordPrefix = false;
            if ($name !== '') {
                foreach (preg_split('/\s+/', $name) as $w) {
                    if ($w !== '' && strpos($w, $q) === 0) { $wordPrefix = true; break; }
                }
            }
            if ($emailPos === 0 || $namePos === 0 || $wordPrefix) {
                $rank = 0; // prefix (strongest)
            } elseif ($emailPos !== false || $namePos !== false) {
                $rank = 1; // substring
            } else {
                continue;  // no match
            }
        }

        $scored[] = [
            'rank'  => $rank,
            'count' => $cnt,
            'seen'  => $seen,
            'email' => (string)($rec['email'] ?? $key),
            'name'  => (string)($rec['name'] ?? ''),
        ];
    }

    usort($scored, function ($a, $b) {
        if ($a['rank']  !== $b['rank'])  return $a['rank'] - $b['rank'];
        if ($a['count'] !== $b['count']) return $b['count'] - $a['count'];
        return $b['seen'] - $a['seen'];
    });

    $out = [];
    // A group whose name matches comes first: one token that becomes every member.
    if ($q !== '') {
        $g = 0;
        foreach (contacts_groups($ownerEmail, $book) as $grp) {
            if ($g >= 3) break;
            if (stripos($grp['name'], $q) === false) continue;
            $out[] = ['group' => $grp['name'], 'count' => count($grp['emails']), 'emails' => $grp['emails'], 'email' => '', 'name' => $grp['name']];
            $g++;
        }
    }
    foreach (array_slice($scored, 0, $limit) as $s) {
        $out[] = ['email' => $s['email'], 'name' => $s['name']];
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * Manual contact management.
 *
 * The book is harvested passively from mail the user sends and threads they
 * open, which is convenient but leaves no way to fix a wrong name, add someone
 * before ever writing to them, or remove an address that should not be
 * suggested. These give the user control of their own address book.
 *
 * Same lock discipline as contacts_record(): harvesting runs on every send and
 * every thread open, so an unlocked read-modify-write here would drop entries.
 * ------------------------------------------------------------------------- */

/** Every contact, newest-interaction first, as a list the UI can render. */
function contacts_all($ownerEmail) {
    $book = load_contacts($ownerEmail);
    $out  = [];
    foreach ($book as $email => $e) {
        if (!is_array($e)) continue;
        $out[] = [
            'email'     => (string)($e['email'] ?? $email),
            'name'      => (string)($e['name'] ?? ''),
            'count'     => (int)($e['count'] ?? 0),
            'last_seen' => (int)($e['last_seen'] ?? 0),
        ] + contacts_clean_fields($e) + ['aliases' => array_values((array)($e['aliases'] ?? []))];
    }
    usort($out, function ($a, $b) {
        if ($a['last_seen'] !== $b['last_seen']) return $b['last_seen'] - $a['last_seen'];
        return strcasecmp($a['name'] !== '' ? $a['name'] : $a['email'],
                          $b['name'] !== '' ? $b['name'] : $b['email']);
    });
    return $out;
}

/**
 * Create or update one contact. Returns [ok, error].
 * $originalEmail lets an edit change the address itself — the book is keyed by
 * address, so that is a delete plus an insert rather than a field update.
 */
function contacts_upsert($ownerEmail, $email, $name, $originalEmail = '', $fields = null) {
    if (!$ownerEmail) return [false, 'Not authenticated'];
    $email = strtolower(trim((string)$email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return [false, 'Enter a valid email address.'];
    if ($email === strtolower(trim($ownerEmail)))                     return [false, 'That is your own address.'];

    $name = _contacts_clean_name((string)$name);
    if (strcasecmp($name, $email) === 0) $name = '';
    if (mb_strlen($name) > 120) $name = mb_substr($name, 0, 120);

    $lock = @fopen(_contacts_file($ownerEmail) . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $book = load_contacts($ownerEmail);

    $original = strtolower(trim((string)$originalEmail));
    if ($original !== '' && $original !== $email && isset($book[$original])) {
        // Carry the interaction history across an address change so a corrected
        // contact doesn't drop to the bottom of autocomplete.
        $book[$email] = $book[$original];
        unset($book[$original]);
    }

    if (!isset($book[$email]) || !is_array($book[$email])) {
        if (count($book) >= CONTACTS_MAX_ENTRIES) {
            if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
            return [false, 'Your address book is full (' . CONTACTS_MAX_ENTRIES . ' contacts).'];
        }
        $book[$email] = ['email' => $email, 'count' => 0, 'last_seen' => time()];
    }
    $book[$email]['email'] = $email;
    $book[$email]['name']  = $name;   // a manual edit is authoritative, including clearing it
    if (is_array($fields)) {
        foreach (contacts_clean_fields($fields) as $k => $v) $book[$email][$k] = $v;   // also authoritative: empty clears
    }

    $ok = save_contacts($ownerEmail, $book);
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
    return [$ok, $ok ? '' : 'Could not save the contact.'];
}

/** Remove one contact. Returns true if it existed and was removed. */
function contacts_delete($ownerEmail, $email) {
    if (!$ownerEmail) return false;
    $email = strtolower(trim((string)$email));
    if ($email === '') return false;
    $lock = @fopen(_contacts_file($ownerEmail) . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $book = load_contacts($ownerEmail);
    $existed = isset($book[$email]);
    if ($existed) {
        unset($book[$email]);
        save_contacts($ownerEmail, $book);
    }
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
    return $existed;
}

/* ---------------------------------------------------------------------------
 * Richer contacts: phone, company, role, notes, groups; vCard and CSV in and
 * out; duplicates and merging. Groups are not a registry — a group exists
 * while a contact carries its name — so there is nothing to keep in sync.
 * ------------------------------------------------------------------------- */

const CONTACT_FIELD_LIMITS   = ['phone' => 40, 'company' => 80, 'role' => 80, 'notes' => 1000];
const CONTACT_GROUPS_MAX     = 10;
const CONTACT_GROUP_NAME_MAX = 40;

/** The optional fields, trimmed and bounded; groups as a tidy, deduplicated list. */
function contacts_clean_fields(array $in) {
    $out = [];
    foreach (CONTACT_FIELD_LIMITS as $k => $max) {
        $v = trim(preg_replace($k === 'notes' ? '/\r\n?/' : '/\s+/', $k === 'notes' ? "\n" : ' ', (string)($in[$k] ?? '')));
        $out[$k] = function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
    }
    $raw = $in['groups'] ?? [];
    if (is_string($raw)) $raw = preg_split('/[,;]/', $raw);
    $groups = []; $seen = [];
    foreach ((array)$raw as $g) {
        $g = trim(preg_replace('/\s+/', ' ', (string)$g));
        if ($g === '') continue;
        $g = function_exists('mb_substr') ? mb_substr($g, 0, CONTACT_GROUP_NAME_MAX) : substr($g, 0, CONTACT_GROUP_NAME_MAX);
        $key = function_exists('mb_strtolower') ? mb_strtolower($g) : strtolower($g);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $groups[] = $g;
        if (count($groups) >= CONTACT_GROUPS_MAX) break;
    }
    $out['groups'] = $groups;
    return $out;
}

/** alias address → the address of the contact it was merged into. */
function contacts_alias_index(array $book) {
    $idx = [];
    foreach ($book as $email => $rec) {
        foreach ((array)($rec['aliases'] ?? []) as $a) $idx[strtolower((string)$a)] = (string)$email;
    }
    return $idx;
}

/** Every group in use: name, members. Sorted by name; the name's case is the first seen. */
function contacts_groups($ownerEmail, $book = null) {
    $book = $book ?? load_contacts($ownerEmail);
    $groups = [];
    foreach ($book as $email => $rec) {
        foreach ((array)($rec['groups'] ?? []) as $g) {
            $key = function_exists('mb_strtolower') ? mb_strtolower((string)$g) : strtolower((string)$g);
            if (!isset($groups[$key])) $groups[$key] = ['name' => (string)$g, 'emails' => []];
            $groups[$key]['emails'][] = (string)($rec['email'] ?? $email);
        }
    }
    $out = array_values($groups);
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    foreach ($out as &$g) { sort($g['emails']); $g['count'] = count($g['emails']); }
    return $out;
}

/* ---------- vCard ---------- */

function _vcard_escape($v) { return str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\\,', '\\n'], (string)$v); }
function _vcard_unescape($v) {
    return preg_replace_callback('/\\\\(.)/', fn($m) => ['n' => "\n", 'N' => "\n", ',' => ',', ';' => ';', '\\' => "\\"][$m[1]] ?? $m[0], (string)$v);
}
/** Fold at 75 octets without splitting a UTF-8 character (RFC 6350 §3.2). */
function _vcard_fold($line) {
    if (strlen($line) <= 75) return $line;
    $out = ''; $cur = '';
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (strlen($cur) + strlen($ch) > ($out === '' ? 75 : 74)) { $out .= ($out === '' ? '' : "\r\n ") . $cur; $cur = ''; }
        $cur .= $ch;
    }
    return $out . ($out === '' ? '' : "\r\n ") . $cur;
}
/** "First Last" → N:Last;First;;; — the common case; a single word is a given name. */
function _vcard_n($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    if (count($parts) <= 1) return _vcard_escape($parts[0] ?? '') . ';;;;';
    $last = array_pop($parts);
    return _vcard_escape($last) . ';' . _vcard_escape(implode(' ', $parts)) . ';;;';
}

function contacts_export_vcard($ownerEmail) {
    $out = '';
    foreach (contacts_all($ownerEmail) as $c) {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
        $lines[] = 'FN:' . _vcard_escape($c['name'] !== '' ? $c['name'] : $c['email']);
        $lines[] = 'N:' . ($c['name'] !== '' ? _vcard_n($c['name']) : ';;;;');
        $lines[] = 'EMAIL;TYPE=INTERNET:' . _vcard_escape($c['email']);
        foreach ($c['aliases'] as $a) $lines[] = 'EMAIL;TYPE=INTERNET:' . _vcard_escape($a);
        if ($c['phone'] !== '')   $lines[] = 'TEL:' . _vcard_escape($c['phone']);
        if ($c['company'] !== '') $lines[] = 'ORG:' . _vcard_escape($c['company']);
        if ($c['role'] !== '')    $lines[] = 'TITLE:' . _vcard_escape($c['role']);
        if ($c['notes'] !== '')   $lines[] = 'NOTE:' . _vcard_escape($c['notes']);
        if ($c['groups'])         $lines[] = 'CATEGORIES:' . implode(',', array_map('_vcard_escape', $c['groups']));
        $lines[] = 'END:VCARD';
        $out .= implode("\r\n", array_map('_vcard_fold', $lines)) . "\r\n";
    }
    return $out;
}

/**
 * Parse vCards (2.1, 3.0, 4.0 as far as these fields go). Returns entries
 * [name, email, phone, company, role, notes, groups, aliases]; a card with no
 * usable email is skipped. Tolerates folded lines, parameters, quoted-
 * printable (2.1) and a BOM.
 */
function contacts_parse_vcard($text) {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', (string)$text);
    $text = preg_replace("/\r\n?/", "\n", $text);
    $text = preg_replace("/\n[ \t]/", '', $text);   // unfold
    $entries = []; $cur = null;
    foreach (explode("\n", $text) as $line) {
        if (trim($line) === '') continue;
        if (!preg_match('/^([^:]+):(.*)$/s', $line, $m)) continue;
        $head = $m[1]; $value = $m[2];
        $parts = explode(';', $head);
        $prop  = strtoupper(trim($parts[0]));
        if (strpos($prop, '.') !== false) $prop = substr($prop, strpos($prop, '.') + 1);   // item1.EMAIL
        $params = strtoupper(implode(';', array_slice($parts, 1)));
        if (strpos($params, 'QUOTED-PRINTABLE') !== false) $value = quoted_printable_decode($value);
        if ($prop === 'BEGIN' && strtoupper(trim($value)) === 'VCARD') { $cur = ['name' => '', 'n' => '', 'email' => '', 'aliases' => [], 'phone' => '', 'company' => '', 'role' => '', 'notes' => '', 'groups' => []]; continue; }
        if ($cur === null) continue;
        if ($prop === 'END') {
            if ($cur['name'] === '' && $cur['n'] !== '') $cur['name'] = $cur['n'];
            unset($cur['n']);
            if ($cur['email'] !== '') $entries[] = $cur;
            $cur = null; continue;
        }
        switch ($prop) {
            case 'FN':    $cur['name'] = _contacts_clean_name(_vcard_unescape($value)); break;
            case 'N':
                $f = array_map(fn($x) => trim(_vcard_unescape($x)), explode(';', $value));
                $cur['n'] = trim(($f[1] ?? '') . ' ' . ($f[0] ?? ''));
                break;
            case 'EMAIL':
                $e = strtolower(trim(_vcard_unescape($value)));
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) { if ($cur['email'] === '') $cur['email'] = $e; elseif ($e !== $cur['email']) $cur['aliases'][] = $e; }
                break;
            case 'TEL':   if ($cur['phone'] === '') $cur['phone'] = trim(_vcard_unescape(preg_replace('/^tel:/i', '', $value))); break;
            case 'ORG':   $cur['company'] = trim(_vcard_unescape(explode(';', $value)[0])); break;
            case 'TITLE': $cur['role'] = trim(_vcard_unescape($value)); break;
            case 'NOTE':  $cur['notes'] = trim(_vcard_unescape($value)); break;
            case 'CATEGORIES':
                foreach (preg_split('/(?<!\\\\),/', $value) as $g) { $g = trim(_vcard_unescape($g)); if ($g !== '') $cur['groups'][] = $g; }
                break;
        }
    }
    return $entries;
}

/* ---------- CSV ---------- */

/** RFC 4180 rows: quoted fields may hold commas, quotes ("") and newlines. */
function contacts_csv_rows($text) {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', (string)$text);
    $rows = []; $row = []; $field = ''; $inQ = false; $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ch = $text[$i];
        if ($inQ) {
            if ($ch === '"') { if ($i + 1 < $len && $text[$i + 1] === '"') { $field .= '"'; $i++; } else $inQ = false; }
            else $field .= $ch;
            continue;
        }
        if ($ch === '"')       { $inQ = true; continue; }
        if ($ch === ',')       { $row[] = $field; $field = ''; continue; }
        if ($ch === "\r")      { continue; }
        if ($ch === "\n")      { $row[] = $field; $field = ''; if (count($row) > 1 || trim($row[0]) !== '') $rows[] = $row; $row = []; continue; }
        $field .= $ch;
    }
    $row[] = $field;
    if (count($row) > 1 || trim($row[0]) !== '') $rows[] = $row;
    return $rows;
}

function _csv_cell($v) {
    $v = (string)$v;
    return (preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v);
}

function contacts_export_csv($ownerEmail) {
    $out = "Name,Email,Phone,Company,Role,Groups,Notes\r\n";
    foreach (contacts_all($ownerEmail) as $c) {
        $out .= implode(',', array_map('_csv_cell', [$c['name'], $c['email'], $c['phone'], $c['company'], $c['role'], implode('; ', $c['groups']), $c['notes']])) . "\r\n";
    }
    return $out;
}

/** Which column holds what, from a header row: ours, Google's, Outlook's, Apple's. */
function contacts_csv_columns(array $header) {
    $norm = fn($h) => preg_replace('/[^a-z0-9]+/', '', strtolower((string)$h));
    $cols = ['name' => null, 'first' => null, 'last' => null, 'email' => null, 'phone' => null, 'company' => null, 'role' => null, 'notes' => null, 'groups' => null];
    $want = [
        'name'    => ['name', 'fullname', 'displayname', 'contactname'],
        'first'   => ['firstname', 'givenname', 'first'],
        'last'    => ['lastname', 'familyname', 'surname', 'last'],
        'email'   => ['email', 'emailaddress', 'email1value', 'email1', 'emailaddress1', 'primaryemail', 'email1address'],
        'phone'   => ['phone', 'phonenumber', 'mobile', 'mobilephone', 'phone1value', 'primaryphone', 'cellphone', 'telephone'],
        'company' => ['company', 'organization', 'organisation', 'organization1name', 'companyname', 'org'],
        'role'    => ['role', 'title', 'jobtitle', 'organization1title', 'position'],
        'notes'   => ['notes', 'note', 'comments'],
        'groups'  => ['groups', 'group', 'categories', 'groupmembership', 'labels', 'tags'],
    ];
    foreach ($header as $i => $h) {
        $n = $norm($h);
        foreach ($want as $field => $names) {
            if ($cols[$field] === null && in_array($n, $names, true)) { $cols[$field] = $i; break; }
        }
    }
    return $cols;
}

function contacts_parse_csv($text) {
    $rows = contacts_csv_rows($text);
    if (count($rows) < 2) return [];
    $cols = contacts_csv_columns(array_shift($rows));
    if ($cols['email'] === null) return [];
    $entries = [];
    foreach ($rows as $r) {
        $get = fn($k) => ($cols[$k] !== null && isset($r[$cols[$k]])) ? trim((string)$r[$cols[$k]]) : '';
        $email = strtolower($get('email'));
        if (preg_match('/<([^>]+)>/', $email, $m)) $email = trim($m[1]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $name = $get('name');
        if ($name === '') $name = trim($get('first') . ' ' . $get('last'));
        $groups = preg_split('/\s*(?::::|;|,)\s*/', $get('groups'));
        $groups = array_values(array_filter(array_map(fn($g) => trim(preg_replace('/^\*\s*/', '', $g)), $groups), fn($g) => $g !== '' && strcasecmp($g, 'myContacts') !== 0 && strcasecmp($g, 'starred') !== 0));
        $entries[] = ['name' => _contacts_clean_name($name), 'email' => $email, 'aliases' => [], 'phone' => $get('phone'), 'company' => $get('company'), 'role' => $get('role'), 'notes' => $get('notes'), 'groups' => $groups];
    }
    return $entries;
}

/** vCard or CSV, by the content's own first line. */
function contacts_parse_any($text) {
    return (stripos(ltrim((string)$text, "\xEF\xBB\xBF \t\r\n"), 'BEGIN:VCARD') === 0) ? contacts_parse_vcard($text) : contacts_parse_csv($text);
}

/**
 * Merge parsed entries into the book: a new address is added; an existing one
 * keeps what it has and gains only what it lacked (imports are never allowed
 * to overwrite a correction the user made). Returns the counts.
 */
function contacts_import($ownerEmail, array $entries) {
    $r = ['added' => 0, 'updated' => 0, 'skipped' => 0];
    if (!$ownerEmail || !$entries) return $r;
    $ownerKey = strtolower(trim($ownerEmail));
    $lock = @fopen(_contacts_file($ownerEmail) . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $book = load_contacts($ownerEmail);
    $alias = contacts_alias_index($book);
    foreach ($entries as $e) {
        $email = strtolower(trim((string)($e['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $email === $ownerKey) { $r['skipped']++; continue; }
        if (isset($alias[$email])) $email = $alias[$email];
        $fields = contacts_clean_fields($e);
        $name   = _contacts_clean_name((string)($e['name'] ?? ''));
        if (strcasecmp($name, $email) === 0) $name = '';
        $aliases = array_values(array_unique(array_filter(array_map('strtolower', (array)($e['aliases'] ?? [])), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL) && $a !== $email)));
        if (isset($book[$email]) && is_array($book[$email])) {
            $rec = $book[$email]; $changed = false;
            if (trim((string)($rec['name'] ?? '')) === '' && $name !== '') { $rec['name'] = $name; $changed = true; }
            foreach (['phone', 'company', 'role', 'notes'] as $k) {
                if (trim((string)($rec[$k] ?? '')) === '' && $fields[$k] !== '') { $rec[$k] = $fields[$k]; $changed = true; }
            }
            $merged = contacts_clean_fields(['groups' => array_merge((array)($rec['groups'] ?? []), $fields['groups'])])['groups'];
            if ($merged !== array_values((array)($rec['groups'] ?? []))) { $rec['groups'] = $merged; $changed = true; }
            $al = array_values(array_unique(array_merge((array)($rec['aliases'] ?? []), $aliases)));
            if ($al !== array_values((array)($rec['aliases'] ?? []))) { $rec['aliases'] = $al; $changed = true; }
            $book[$email] = $rec;
            $r[$changed ? 'updated' : 'skipped']++;
        } else {
            if (count($book) >= CONTACTS_MAX_ENTRIES) { $r['skipped']++; continue; }
            $book[$email] = ['email' => $email, 'name' => $name, 'count' => 0, 'last_seen' => time()] + $fields + ['aliases' => $aliases];
            foreach ($aliases as $a) $alias[$a] = $email;
            $r['added']++;
        }
    }
    save_contacts($ownerEmail, $book);
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
    return $r;
}

/* ---------- duplicates ---------- */

/** gmail-style canonical form: dots in the local part and +tags do not make a different mailbox. */
function contacts_canonical_email($email) {
    $email = strtolower(trim((string)$email));
    if (!preg_match('/^([^@]+)@(.+)$/', $email, $m)) return $email;
    [$_, $local, $domain] = $m;
    $local = preg_replace('/\+.*$/', '', $local);
    if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) { $local = str_replace('.', '', $local); $domain = 'gmail.com'; }
    return $local . '@' . $domain;
}

/**
 * Contacts that look like the same person: the same name on different
 * addresses, or addresses that are one mailbox written two ways. Each group
 * suggests which to keep (most used, then most recent). Pure over the book.
 */
function contacts_find_duplicates(array $book) {
    $byName = []; $byCanon = [];
    foreach ($book as $email => $rec) {
        if (!is_array($rec)) continue;
        $email = (string)($rec['email'] ?? $email);
        $name  = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($rec['name'] ?? ''))) : strtolower(trim((string)($rec['name'] ?? '')));
        if ($name !== '' && mb_strlen($name) >= 3 && strpos($name, '@') === false) $byName[$name][] = $email;
        $byCanon[contacts_canonical_email($email)][] = $email;
    }
    $groups = []; $seen = [];
    $pick = function (array $emails) use ($book) {
        usort($emails, function ($a, $b) use ($book) {
            $ca = (int)($book[$a]['count'] ?? 0); $cb = (int)($book[$b]['count'] ?? 0);
            if ($ca !== $cb) return $cb - $ca;
            return (int)($book[$b]['last_seen'] ?? 0) - (int)($book[$a]['last_seen'] ?? 0);
        });
        return $emails;
    };
    foreach ([['same address written differently', $byCanon], ['same name', $byName]] as [$reason, $map]) {
        foreach ($map as $emails) {
            $emails = array_values(array_unique($emails));
            if (count($emails) < 2) continue;
            $key = implode('|', $emails); sort($emails); $key = implode('|', $emails);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $ordered = $pick($emails);
            $groups[] = ['reason' => $reason, 'keep' => $ordered[0], 'contacts' => $ordered];
        }
    }
    return $groups;
}

function contacts_duplicates($ownerEmail) { return contacts_find_duplicates(load_contacts($ownerEmail)); }

/** Fold $others into $keep: what keep lacks it gains, groups and aliases unite, counts add. The others are removed and remembered as aliases. */
function contacts_merge($ownerEmail, $keep, array $others) {
    if (!$ownerEmail) return [false, 'Not authenticated'];
    $keep = strtolower(trim((string)$keep));
    $lock = @fopen(_contacts_file($ownerEmail) . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    $book = load_contacts($ownerEmail);
    if (!isset($book[$keep])) { if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); } return [false, 'The contact to keep no longer exists.']; }
    $rec = $book[$keep]; $n = 0;
    foreach ($others as $o) {
        $o = strtolower(trim((string)$o));
        if ($o === '' || $o === $keep || !isset($book[$o])) continue;
        $src = $book[$o];
        if (trim((string)($rec['name'] ?? '')) === '') $rec['name'] = (string)($src['name'] ?? '');
        foreach (['phone', 'company', 'role', 'notes'] as $k) if (trim((string)($rec[$k] ?? '')) === '') $rec[$k] = (string)($src[$k] ?? '');
        $rec['groups']    = contacts_clean_fields(['groups' => array_merge((array)($rec['groups'] ?? []), (array)($src['groups'] ?? []))])['groups'];
        $rec['aliases']   = array_values(array_unique(array_merge((array)($rec['aliases'] ?? []), (array)($src['aliases'] ?? []), [$o])));
        $rec['count']     = (int)($rec['count'] ?? 0) + (int)($src['count'] ?? 0);
        $rec['last_seen'] = max((int)($rec['last_seen'] ?? 0), (int)($src['last_seen'] ?? 0));
        unset($book[$o]); $n++;
    }
    $book[$keep] = $rec;
    $ok = save_contacts($ownerEmail, $book);
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
    return [$ok && $n > 0, $ok ? ($n > 0 ? '' : 'Nothing to merge.') : 'Could not save.'];
}
