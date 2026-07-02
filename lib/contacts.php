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

function save_contacts($email, $book) {
    if (!$email || !is_array($book)) return false;
    $file = _contacts_file($email);
    $tmp  = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($book, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    return @rename($tmp, $file);
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
    foreach (array_slice($scored, 0, $limit) as $s) {
        $out[] = ['email' => $s['email'], 'name' => $s['name']];
    }
    return $out;
}
