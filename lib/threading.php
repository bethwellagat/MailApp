<?php
/**
 * Conversation threading — the single definition of "same conversation".
 *
 * The app had two different answers to "which messages belong together?". The
 * reading pane walked the References graph and found, say, fourteen messages;
 * the message list did not group at all and drew each of them as its own row.
 * So one conversation appeared in the inbox four times over, and the count the
 * reader printed matched nothing on screen. Both paths now come here.
 *
 * The rule is RFC 5322's: a message belongs with everything its Message-ID,
 * In-Reply-To and References chain reaches, transitively. Subjects are
 * deliberately NOT part of it — matching on subject merges two strangers who
 * both wrote "Re: hello", and splitting on it breaks a thread the moment
 * someone edits the subject line mid-conversation.
 */

/**
 * One Message-ID, comparable: no angle brackets, no surrounding space, lowercase.
 *
 * CASE IS FOLDED FOR COMPARISON ONLY. The local part of a Message-ID is
 * case-sensitive per RFC 5322, and the client builds the In-Reply-To of a reply
 * straight from the message_id this app hands it — so a normalised id must
 * never be written back onto a message or sent to the client. thread_group()
 * therefore reads ids out of its items and never writes them back; the reader's
 * own parse_thread_ids() preserves case for the same reason. Keep it that way.
 */
function thread_norm_id($id) {
    $id = trim((string)$id);
    if ($id === '') return '';
    $id = trim($id, "<> \t\r\n");
    return strtolower($id);
}

/**
 * Every message-id in a header value.
 *
 * Servers are inconsistent about this: imap_fetch_overview hands back "<a@b>"
 * from one mailbox and a bare "a@b" from another, and References is a list
 * that some mailers emit without brackets at all. Prefer the bracketed ids
 * when the value has any — that is the well-formed case and the only one where
 * an id containing spaces could be read correctly — and fall back to splitting
 * on whitespace when it has none.
 */
function thread_ids_raw($value) {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $v) {
            $v = trim((string)$v, "<> \t\r\n");
            if ($v !== '') $out[] = $v;
        }
        return $out;
    }
    $value = (string)$value;
    if (trim($value) === '') return [];
    $out = [];
    if (preg_match_all('/<([^<>\s]+)>/', $value, $m)) {
        foreach ($m[1] as $id) {
            $id = trim($id);
            if ($id !== '') $out[] = $id;
        }
        return $out;
    }
    foreach (preg_split('/\s+/', trim($value)) as $tok) {
        $tok = trim($tok, "<> \t\r\n");
        if ($tok !== '') $out[] = $tok;
    }
    return $out;
}

/**
 * The same ids, folded for comparison. Use this to decide what belongs with
 * what; use thread_ids_raw() for anything that will be written back out, such
 * as the References chain of a reply.
 */
function thread_ids_in($value) {
    $out = [];
    foreach (thread_ids_raw($value) as $id) {
        $id = thread_norm_id($id);
        if ($id !== '') $out[] = $id;
    }
    return $out;
}

/* ---------- union-find over message-ids ----------
   A conversation is a connected component of the id graph, so the natural
   structure is a disjoint set. Path-halving keeps find() near-constant, which
   matters because this runs over every message in the scan window on each
   folder open. */

function thread_uf_root(array &$parent, $x) {
    if (!isset($parent[$x])) { $parent[$x] = $x; return $x; }
    while ($parent[$x] !== $x) {
        $parent[$x] = $parent[$parent[$x]];
        $x = $parent[$x];
    }
    return $x;
}

function thread_uf_join(array &$parent, $a, $b) {
    $ra = thread_uf_root($parent, $a);
    $rb = thread_uf_root($parent, $b);
    if ($ra !== $rb) $parent[$rb] = $ra;
}

/**
 * Group message descriptors into conversations.
 *
 * $items is a list of arrays carrying at least:
 *   'message_id', 'in_reply_to', 'references' (string or array), 'timestamp'
 * Anything else on an item is passed through untouched.
 *
 * Returns a list of groups. Each group is a list of the original items ordered
 * newest first; the groups themselves are ordered by their newest member, so
 * the caller can render them straight into a newest-first list.
 */
function thread_group(array $items) {
    $parent = [];
    $ids    = [];

    // Pass 1 — union. Every id a single message names is, by its own
    // declaration, part of one chain, so they all join together. This has to
    // finish for ALL messages before any root is read: a message that arrives
    // later can be the link that merges two components seen earlier.
    foreach ($items as $i => $it) {
        $own  = thread_norm_id($it['message_id'] ?? '');
        $mine = [];
        if ($own !== '') $mine[] = $own;
        foreach (thread_ids_in($it['in_reply_to'] ?? '') as $id) $mine[] = $id;
        foreach (thread_ids_in($it['references']  ?? '') as $id) $mine[] = $id;
        $mine = array_values(array_unique($mine));
        $ids[$i] = ['own' => $own, 'all' => $mine];

        $n = count($mine);
        for ($k = 1; $k < $n; $k++) thread_uf_join($parent, $mine[0], $mine[$k]);
        if ($n > 0) thread_uf_root($parent, $mine[0]);
    }

    // Pass 2 — read each message's component.
    $groups = [];
    foreach ($items as $i => $it) {
        $own = $ids[$i]['own'];
        $all = $ids[$i]['all'];
        if ($own !== '') {
            $root = thread_uf_root($parent, $own);
        } elseif (!empty($all)) {
            $root = thread_uf_root($parent, $all[0]);
        } else {
            // No Message-ID and nothing referenced: it can never be shown to
            // belong with anything, so it is a conversation of one. Keyed by
            // position so two such messages never collapse into each other.
            $root = "\0solo\0" . $i;
        }
        $groups[$root][] = $it;
    }

    // Newest first, within a group and between groups. Ties break on the
    // message-id so the order is deterministic — an unstable list order makes
    // pagination drop and repeat rows between pages.
    $tie = function ($a, $b) {
        $at = (int)($a['timestamp'] ?? 0);
        $bt = (int)($b['timestamp'] ?? 0);
        if ($at !== $bt) return $bt - $at;
        return strcmp((string)($b['message_id'] ?? ''), (string)($a['message_id'] ?? ''));
    };

    $out = [];
    foreach ($groups as $g) {
        usort($g, $tie);
        $out[] = $g;
    }
    usort($out, function ($a, $b) use ($tie) { return $tie($a[0], $b[0]); });
    return $out;
}

/**
 * Collapse one conversation into the single row the list draws for it.
 *
 * The representative is the newest message — that is the one whose subject and
 * sender describe what just happened. The read/flag state is aggregated
 * instead, because a conversation with one unread message is unread, and the
 * unread filter would otherwise hide a thread that has something new in it.
 */
function thread_row(array $group) {
    $head = $group[0];
    $uids = [];
    $anyUnread = false;
    $anyFlagged = false;
    $anyAnswered = false;
    foreach ($group as $m) {
        if (isset($m['uid'])) $uids[] = (int)$m['uid'];
        if (empty($m['seen']))      $anyUnread   = true;
        if (!empty($m['flagged']))  $anyFlagged  = true;
        if (!empty($m['answered'])) $anyAnswered = true;
    }
    $head['seen']         = !$anyUnread;
    $head['flagged']      = $anyFlagged;
    $head['answered']     = $anyAnswered;
    $head['thread_count'] = count($group);
    $head['thread_uids']  = $uids;
    return $head;
}

/**
 * Which rows one page of a conversation list covers.
 *
 * A folder shows its most recent messages grouped into conversations, followed
 * — only in a mailbox bigger than the scan window — by the messages behind that
 * window, ungrouped. Every windowed message is newer than every message behind
 * it, so the two regions simply concatenate and never need comparing.
 *
 * Kept separate from the IMAP code because it is the part that is easy to get
 * wrong by one and impossible to see wrong: an off-by-one here silently repeats
 * or skips a row at a page boundary.
 *
 * Returns the clamped page, the page count, and the slice to take from each
 * region: conv_offset/conv_count into the conversation rows, and tail_from/
 * tail_count as an index into the older messages counting back from the newest.
 */
function thread_page_plan($convCount, $olderCount, $page, $perPage) {
    $convCount  = max(0, (int)$convCount);
    $olderCount = max(0, (int)$olderCount);
    $perPage    = max(1, (int)$perPage);

    $rowTotal = $convCount + $olderCount;
    $pages    = max(1, (int)ceil($rowTotal / $perPage));
    $page     = max(1, min((int)$page, $pages));
    $offset   = ($page - 1) * $perPage;

    $convOffset = min($offset, $convCount);
    $convTake   = max(0, min($perPage, $convCount - $convOffset));

    $need     = $perPage - $convTake;
    $tailFrom = max(0, $offset - $convCount);
    $tailTake = max(0, min($need, $olderCount - $tailFrom));

    return [
        'page'        => $page,
        'pages'       => $pages,
        'rows'        => $rowTotal,
        'conv_offset' => $convOffset,
        'conv_count'  => $convTake,
        'tail_from'   => $tailFrom,
        'tail_count'  => $tailTake,
    ];
}

/**
 * The sequence ranges to read when scanning back from the newest message.
 *
 * Returns [[lo, hi], …] newest chunk first, covering at most $window messages
 * in pieces of $chunk. Split into pieces so a slow host can be noticed between
 * commands and the scan abandoned early — asking one IMAP server for 1200
 * envelopes in a single command is what took a mailbox off the air.
 *
 * Pure, because the arithmetic is the part that is easy to get wrong by one and
 * impossible to see wrong: a gap silently drops messages out of the grouping,
 * an overlap silently double-counts them.
 */
function thread_scan_chunks($total, $window, $chunk) {
    $total  = max(0, (int)$total);
    $window = max(0, (int)$window);
    $chunk  = max(1, (int)$chunk);
    $want   = min($window, $total);

    $out    = [];
    $cursor = $total;
    $got    = 0;
    while ($got < $want && $cursor >= 1) {
        $take = min($chunk, $want - $got);
        $lo   = max(1, $cursor - $take + 1);
        $out[] = [$lo, $cursor];
        $got  += ($cursor - $lo + 1);
        $cursor = $lo - 1;
    }
    return $out;
}
