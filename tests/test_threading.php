<?php
/**
 * Conversation threading.
 *
 * The bug these guard: the inbox drew one conversation as four separate rows
 * because the list did not group at all, while the reading pane walked the
 * References graph and reported fourteen messages for the very same thread.
 * Every assertion here is about the two agreeing.
 */

require_once T_ROOT . '/lib/threading.php';

/** Compact helper: build an item the way the list does. */
function th_msg($uid, $msgId, $refs = '', $inReplyTo = '', $ts = 0, $extra = []) {
    return $extra + [
        'uid'         => $uid,
        'message_id'  => $msgId,
        'references'  => $refs,
        'in_reply_to' => $inReplyTo,
        'timestamp'   => $ts ?: (1000 + $uid),
        'seen'        => true,
        'flagged'     => false,
        'answered'    => false,
    ];
}

/** Group and return uid sets, so assertions read as "these belong together". */
function th_sets(array $items) {
    $out = [];
    foreach (thread_group($items) as $g) {
        $u = array_map(fn($m) => $m['uid'], $g);
        sort($u);
        $out[] = $u;
    }
    usort($out, fn($a, $b) => $a[0] - $b[0]);
    return $out;
}

t_group('an id is an id however the server spells it');
t_eq('angle brackets stripped', thread_norm_id('<a@b.com>'), 'a@b.com');
t_eq('bare id survives',        thread_norm_id('a@b.com'),   'a@b.com');
t_eq('case folded',             thread_norm_id('<A@B.CoM>'), 'a@b.com');
t_eq('whitespace trimmed',      thread_norm_id("  <a@b.com>\r\n"), 'a@b.com');
t_eq('empty stays empty',       thread_norm_id(''),          '');
t_eq('lone brackets are empty', thread_norm_id('<>'),        '');

t_group('reference lists are read whatever shape they arrive in');
t_eq('bracketed list', thread_ids_in('<a@x> <b@x> <c@x>'), ['a@x', 'b@x', 'c@x']);
t_eq('bare list',      thread_ids_in('a@x b@x'),           ['a@x', 'b@x']);
t_eq('folded list',    thread_ids_in("<a@x>\r\n\t<b@x>"),  ['a@x', 'b@x']);
t_eq('single id',      thread_ids_in('<a@x>'),             ['a@x']);
t_eq('empty',          thread_ids_in(''),                  []);
t_eq('whitespace only', thread_ids_in("  \r\n "),          []);
t_eq('array passthrough', thread_ids_in(['<a@x>', 'B@X']), ['a@x', 'b@x']);

t_group('a reply joins the message it answers');
/* The simplest real case, and the one the inbox was getting wrong. */
$items = [
    th_msg(1, '<root@x>'),
    th_msg(2, '<reply@x>', '<root@x>', '<root@x>'),
];
t_eq('two messages, one conversation', th_sets($items), [[1, 2]]);

t_group('a chain joins transitively');
/* C references only B, B only A — C must still land with A. This is the case a
   naive "same in-reply-to" grouping gets wrong, and it is what the reading
   pane's BFS already did. */
$items = [
    th_msg(1, '<a@x>'),
    th_msg(2, '<b@x>', '<a@x>', '<a@x>'),
    th_msg(3, '<c@x>', '<b@x>', '<b@x>'),
];
t_eq('three deep, one conversation', th_sets($items), [[1, 2, 3]]);

t_group('order of arrival cannot change the grouping');
/* The link that merges two components often arrives last — IMAP hands the
   window back oldest-first, the list renders newest-first, and a folder can be
   scanned in either direction. Grouping must be identical either way. */
$a = [ th_msg(1, '<a@x>'), th_msg(2, '<b@x>'), th_msg(3, '<c@x>', '<a@x> <b@x>') ];
$b = array_reverse($a);
t_eq('forward',  th_sets($a), [[1, 2, 3]]);
t_eq('reversed', th_sets($b), [[1, 2, 3]]);
t_ok('same grouping either way', th_sets($a) === th_sets($b));

t_group('unrelated mail stays apart');
$items = [
    th_msg(1, '<a@x>'),
    th_msg(2, '<b@x>'),
    th_msg(3, '<c@x>', '<b@x>'),
];
t_eq('two conversations', th_sets($items), [[1], [2, 3]]);

t_group('subject is not a threading signal');
/* Two strangers both writing "Re: hello" is not a conversation, and this is
   exactly the merge a subject-matching implementation makes. */
$items = [
    th_msg(1, '<a@x>', '', '', 0, ['subject' => 'Re: hello']),
    th_msg(2, '<b@y>', '', '', 0, ['subject' => 'Re: hello']),
];
t_eq('same subject, no reference — two conversations', th_sets($items), [[1], [2]]);

/* ...and the converse: a subject edited mid-thread must not split it. */
$items = [
    th_msg(1, '<a@x>', '', '', 0, ['subject' => 'Ticket #318531 opened']),
    th_msg(2, '<b@x>', '<a@x>', '<a@x>', 0, ['subject' => 'Re: CX Login - Details and Link']),
];
t_eq('subject changed mid-thread — still one conversation', th_sets($items), [[1, 2]]);

t_group('messages with nothing to thread on');
/* No Message-ID and no references: it cannot be shown to belong with anything,
   so it is a conversation of one — and two such messages must not collapse
   into each other just because they both have nothing. */
$items = [ th_msg(1, ''), th_msg(2, '') ];
t_eq('each stands alone', th_sets($items), [[1], [2]]);

t_group('the same message delivered twice');
/* A duplicate carries the same Message-ID. It belongs in one conversation
   rather than two identical-looking rows — which is precisely what the inbox
   screenshot showed at 01:09 PM. */
$items = [ th_msg(1, '<dup@x>'), th_msg(2, '<dup@x>') ];
t_eq('one conversation', th_sets($items), [[1, 2]]);

t_group('conversations come back newest first');
$items = [
    th_msg(1, '<a@x>', '', '', 5000),
    th_msg(2, '<b@x>', '', '', 9000),
    th_msg(3, '<c@x>', '<a@x>', '<a@x>', 7000),
];
$groups = thread_group($items);
t_eq('newest conversation first', $groups[0][0]['uid'], 2);
t_eq('the a/c thread is second',  $groups[1][0]['uid'], 3);   // 7000 is newer than 5000
t_eq('within a thread, newest first', array_map(fn($m) => $m['uid'], $groups[1]), [3, 1]);

t_group('ordering is deterministic when timestamps tie');
/* Equal timestamps are common — a ticket system firing several notifications in
   the same second. If the order is not total, pagination drops and repeats rows
   between pages. */
$items = [
    th_msg(1, '<a@x>', '', '', 4242),
    th_msg(2, '<b@x>', '', '', 4242),
    th_msg(3, '<c@x>', '', '', 4242),
];
$first  = array_map(fn($g) => $g[0]['uid'], thread_group($items));
$second = array_map(fn($g) => $g[0]['uid'], thread_group(array_reverse($items)));
t_eq('same order regardless of input order', $first, $second);

t_group('a conversation collapses to one row');
$group = [
    th_msg(9, '<new@x>', '<old@x>', '<old@x>', 9000, ['subject' => 'Re: Ticket', 'seen' => false]),
    th_msg(4, '<old@x>', '', '', 5000, ['subject' => 'Ticket', 'seen' => true, 'flagged' => true]),
];
$row = thread_row($group);
t_eq('newest message represents the row', $row['uid'], 9);
t_eq('its subject is the one shown',      $row['subject'], 'Re: Ticket');
t_eq('the count is the whole thread',     $row['thread_count'], 2);
t_eq('every member is addressable',       $row['thread_uids'], [9, 4]);
t_ok('one unread member makes the row unread', $row['seen'] === false);
t_ok('one flagged member makes the row flagged', $row['flagged'] === true);

t_group('a fully-read conversation reads as read');
$row = thread_row([
    th_msg(9, '<new@x>', '', '', 9000, ['seen' => true]),
    th_msg(4, '<old@x>', '', '', 5000, ['seen' => true]),
]);
t_ok('seen', $row['seen'] === true);
t_ok('not flagged', $row['flagged'] === false);

t_group('a flood is one conversation, not a hidden page');
/* Thousands of bounces all reference the one message that was sent. Grouping
   them is correct — the reason the list used to refuse to group at all was that
   it grouped WITHIN a page of raw messages, so a flood ate the page and hid
   everything else. Paging over conversations is what makes this safe. */
$items = [th_msg(1, '<sent@x>', '', '', 1)];
for ($i = 2; $i <= 300; $i++) $items[] = th_msg($i, "<bounce$i@x>", '<sent@x>', '<sent@x>', $i);
$groups = thread_group($items);
t_eq('the flood is a single conversation', count($groups), 1);
t_eq('and it reports its true size', thread_row($groups[0])['thread_count'], 300);

t_group('grouping never rewrites the ids it compares');
/* The local part of a Message-ID is case-sensitive, and the client builds a
   reply's In-Reply-To straight from this field. If grouping folded the case of
   the value it passes through, every reply this app sent would carry an id the
   recipient's client could not match, and threading would break on THEIR side —
   a fault invisible from here. */
$items = [
    th_msg(1, '<AbC.MiXeD@Example.COM>'),
    th_msg(2, '<reply@x>', '<abc.mixed@example.com>'),
];
$groups = thread_group($items);
t_eq('case-differing ids still group', count($groups), 1);
$byUid = [];
foreach ($groups[0] as $m) $byUid[$m['uid']] = $m;
t_eq('the id is passed through untouched', $byUid[1]['message_id'], '<AbC.MiXeD@Example.COM>');
t_eq('and so is the reference', $byUid[2]['references'], '<abc.mixed@example.com>');

t_group('a row keeps the representative message-id verbatim');
$row = thread_row([
    th_msg(9, '<NeW.MiXeD@Example.COM>', '', '', 9000),
    th_msg(4, '<old@x>', '', '', 5000),
]);
t_eq('reply target is unmangled', $row['message_id'], '<NeW.MiXeD@Example.COM>');

t_group('paging over conversations covers every row exactly once');
/**
 * The property that matters is not any single page — it is that walking pages
 * 1..N visits every row in order, with nothing repeated and nothing skipped.
 * A page boundary that drops a row makes mail unreachable; one that repeats a
 * row makes the same message appear twice, which is the very complaint this
 * change is fixing.
 *
 * Exhaustive over small shapes, because the failures are all off-by-one.
 */
$walkBad = [];
foreach ([0, 1, 2, 7, 25, 26, 40] as $conv) {
    foreach ([0, 1, 3, 25, 26, 60] as $older) {
        foreach ([1, 3, 25] as $per) {
            $rows  = $conv + $older;
            $pages = max(1, (int)ceil($rows / $per));
            $seen  = [];
            for ($p = 1; $p <= $pages; $p++) {
                $pl = thread_page_plan($conv, $older, $p, $per);
                if ($pl['pages'] !== $pages) { $walkBad[] = "pages $conv/$older/$per p$p"; break; }
                for ($i = 0; $i < $pl['conv_count']; $i++) $seen[] = 'c' . ($pl['conv_offset'] + $i);
                for ($i = 0; $i < $pl['tail_count']; $i++) $seen[] = 't' . ($pl['tail_from'] + $i);
            }
            $expect = [];
            for ($i = 0; $i < $conv;  $i++) $expect[] = 'c' . $i;
            for ($i = 0; $i < $older; $i++) $expect[] = 't' . $i;
            if ($seen !== $expect) {
                $walkBad[] = "conv=$conv older=$older per=$per got=" . count($seen) . " want=" . count($expect);
            }
        }
    }
}
t_ok('every shape walks cleanly', $walkBad === [], implode(' | ', array_slice($walkBad, 0, 5)));

t_group('page bounds are clamped, never trusted');
$p = thread_page_plan(10, 0, 999, 25);
t_eq('a page past the end clamps to the last', $p['page'], 1);
t_eq('and still returns the rows', $p['conv_count'], 10);
$p = thread_page_plan(10, 0, -5, 25);
t_eq('a negative page clamps to the first', $p['page'], 1);
$p = thread_page_plan(0, 0, 1, 25);
t_eq('an empty folder is one page', $p['pages'], 1);
t_eq('with no rows', $p['conv_count'] + $p['tail_count'], 0);
$p = thread_page_plan(10, 5, 1, 0);
t_ok('a zero page size cannot divide by zero', $p['pages'] >= 1);

t_group('a page straddling the window boundary');
/* 26 conversations then 10 older messages, 25 to a page: page 2 must take the
   last conversation and then the first 10 older messages — the one case where
   a page reads from both regions. */
$p = thread_page_plan(26, 10, 2, 25);
t_eq('one conversation left', $p['conv_count'], 1);
t_eq('taken from the end',    $p['conv_offset'], 25);
t_eq('then the tail starts at its newest', $p['tail_from'], 0);
t_eq('and fills the page',    $p['tail_count'], 10);
t_eq('two pages in total',    $p['pages'], 2);

t_group('a page entirely inside the older tail');
$p = thread_page_plan(5, 100, 3, 25);
t_eq('no conversations on this page', $p['conv_count'], 0);
t_eq('tail continues where page 2 stopped', $p['tail_from'], 45);
t_eq('a full page of them', $p['tail_count'], 25);

/* parse_thread_ids() lives in the fetch endpoint, which bootstraps a session on
   include — lift it out by tokenizer, the way the sanitizer tests do. */
t_extract_fn(T_ROOT . '/ajax/fetch.php', 'parse_thread_ids', 't_parse_ids');

t_group('the reader reads the same ids the list does');
$hdr = "Message-ID: <a@x>\r\nIn-Reply-To: <b@x>\r\nReferences: <c@x> <d@x>\r\n";
$p = t_parse_ids($hdr);
t_eq('message-id',  $p['msg_id'], 'a@x');
t_eq('in-reply-to', $p['in_reply_to'], 'b@x');
t_eq('references',  $p['references'], ['c@x', 'd@x']);

/* A folded References header is the common real-world shape. */
$p = t_parse_ids("Message-ID: <a@x>\r\nReferences: <c@x>\r\n\t<d@x>\r\n\t<e@x>\r\n");
t_eq('folded references are unfolded first', $p['references'], ['c@x', 'd@x', 'e@x']);

/* Bare ids used to be invisible to the reader while the list saw them, which is
   precisely the kind of drift that put one conversation on screen twice. */
$p = t_parse_ids("Message-ID: a@x\r\nIn-Reply-To: b@x\r\n");
t_eq('bare message-id is read',  $p['msg_id'], 'a@x');
t_eq('bare in-reply-to is read', $p['in_reply_to'], 'b@x');

t_group('the reader does not mangle ids it will send back out');
$p = t_parse_ids("Message-ID: <AbC.MiXeD@Example.COM>\r\nReferences: <Up.Per@X.CoM>\r\n");
t_eq('message-id case preserved', $p['msg_id'], 'AbC.MiXeD@Example.COM');
t_eq('references case preserved', $p['references'], ['Up.Per@X.CoM']);

t_group('missing headers are absent, not empty ids');
$p = t_parse_ids("Subject: no threading headers here\r\n");
t_eq('no message-id',  $p['msg_id'], '');
t_eq('no in-reply-to', $p['in_reply_to'], '');
t_eq('no references',  $p['references'], []);

t_group('the list and the reading pane agree on what a thread is');
/**
 * THE invariant behind this whole change. The list groups with union-find over
 * the id graph; the reading pane walks that same graph breadth-first from
 * whichever message was clicked. If those two ever disagree, the inbox shows a
 * conversation split across several rows while the reader shows it whole —
 * which is the bug that was reported.
 *
 * So: for every message, a BFS starting from it must reach exactly the members
 * of the group thread_group() put it in. Asserted from EVERY starting point,
 * because the reader is seeded by whatever the user happened to click.
 */
$mkCase = function ($items) {
    /* Reader-style BFS: collect ids, find every message mentioning any of them,
       absorb that message's ids, repeat until nothing new. */
    $bfs = function ($items, $startIdx) {
        $idsOf = function ($m) {
            $ids = [];
            foreach (thread_ids_in($m['message_id'])  as $i) $ids[$i] = true;
            foreach (thread_ids_in($m['in_reply_to']) as $i) $ids[$i] = true;
            foreach (thread_ids_in($m['references'])  as $i) $ids[$i] = true;
            return $ids;
        };
        $want  = $idsOf($items[$startIdx]);
        $found = [$startIdx => true];
        for ($guard = 0; $guard < 50; $guard++) {
            $grew = false;
            foreach ($items as $i => $m) {
                if (isset($found[$i])) continue;
                foreach ($idsOf($m) as $id => $_) {
                    if (isset($want[$id])) {
                        $found[$i] = true;
                        foreach ($idsOf($m) as $n => $__) {
                            if (!isset($want[$n])) { $want[$n] = true; $grew = true; }
                        }
                        $grew = true;
                        break;
                    }
                }
            }
            if (!$grew) break;
        }
        $uids = [];
        foreach ($found as $i => $_) $uids[] = $items[$i]['uid'];
        sort($uids);
        return $uids;
    };

    $bad = [];
    foreach (thread_group($items) as $g) {
        $want = array_map(fn($m) => $m['uid'], $g);
        sort($want);
        foreach ($items as $i => $m) {
            if (!in_array($m['uid'], $want, true)) continue;
            $got = $bfs($items, $i);
            if ($got !== $want) {
                $bad[] = 'from uid ' . $m['uid'] . ': [' . implode(',', $got) . '] vs [' . implode(',', $want) . ']';
            }
        }
    }
    return $bad;
};

/* A ticket thread of the shape that was reported: several notifications all
   chained onto one root, interleaved with a second unrelated thread. */
$items = [
    th_msg(1, '<root@ticket>'),
    th_msg(2, '<n1@ticket>',  '<root@ticket>', '<root@ticket>'),
    th_msg(3, '<n2@ticket>',  '<root@ticket> <n1@ticket>', '<n1@ticket>'),
    th_msg(4, '<n3@ticket>',  '<root@ticket> <n1@ticket> <n2@ticket>', '<n2@ticket>'),
    th_msg(5, '<other@x>'),
    th_msg(6, '<other2@x>',   '<other@x>', '<other@x>'),
];
t_ok('ticket thread: every starting point agrees', $mkCase($items) === [], implode(' | ', $mkCase($items)));

/* A reply chain where the middle message is missing from the folder — it lives
   in Sent. The remaining two still share ancestors, so both views must keep
   them together rather than the list splitting what the reader joins. */
$items = [
    th_msg(1, '<a@x>'),
    th_msg(3, '<c@x>', '<a@x> <b@x>', '<b@x>'),
];
t_ok('gapped chain: every starting point agrees', $mkCase($items) === [], implode(' | ', $mkCase($items)));

/* Two threads that were never linked must stay two, from either end. */
$items = [
    th_msg(1, '<a@x>'), th_msg(2, '<b@x>', '<a@x>'),
    th_msg(3, '<p@y>'), th_msg(4, '<q@y>', '<p@y>'),
];
t_ok('separate threads: every starting point agrees', $mkCase($items) === [], implode(' | ', $mkCase($items)));

t_group('ids are matched whole, never as substrings');
/**
 * The reading pane used to find thread members with stripos() — a SUBSTRING
 * test. "abc@x" then matched a message referencing "xxabc@x", so the reader
 * could pull in a message the list had placed in a different conversation, and
 * the two views disagreed about the very thing they were both describing.
 */
$items = [
    th_msg(1, '<abc@x>'),
    th_msg(2, '<other@x>', '<xxabc@x>', '<xxabc@x>'),   // merely CONTAINS "abc@x"
];
t_eq('a containing id does not join', th_sets($items), [[1], [2]]);

$items = [
    th_msg(1, '<abc@x>'),
    th_msg(2, '<other@x>', '<abc@x.long>', '<abc@x.long>'), // has "abc@x" as a prefix
];
t_eq('a prefixing id does not join', th_sets($items), [[1], [2]]);

$items = [ th_msg(1, '<abc@x>'), th_msg(2, '<r@x>', '<abc@x>') ];
t_eq('but the whole id still joins', th_sets($items), [[1, 2]]);

t_group('the reported inbox, end to end');
/**
 * The pipeline the list action runs — group, collapse each conversation to a
 * row, then page over the conversations — against the inbox from the report:
 * sixteen messages in which one ticket thread appeared as four separate rows.
 *
 * The IMAP call that feeds this cannot run in the test environment (no imap
 * extension), so what is pinned here is everything after it: the ordering, the
 * collapsing, the aggregation and the slicing.
 */
$mkOv = function ($uid, $subject, $ts, $msgId, $refs = '', $seen = true, $flagged = false) {
    return [
        'uid' => $uid, 'subject' => $subject, 'timestamp' => $ts,
        'message_id' => $msgId, 'in_reply_to' => '', 'references' => $refs,
        'seen' => $seen, 'flagged' => $flagged, 'answered' => false,
    ];
};
$T = '<ticket-318531@thuraya.com>';          // the ticket's root, referenced by all of them
$C = '<cx-login-root@thuraya.com>';
$inbox = [
    // the conversation that was spilling into four rows
    $mkOv(101, 'Your Ticket #318531 has been Re-Opened', 1700, '<a1@t>', $T, false),
    $mkOv(100, 'Your Ticket #318531 has been Re-Opened', 1699, '<a2@t>', $T, false),
    $mkOv(96,  'Your Ticket #318531 has been Re-Opened', 1200, '<a3@t>', $T),
    $mkOv(88,  'Your Ticket #318531 has been Re-Opened', 900,  '<a4@t>', $T),
    // a second conversation
    $mkOv(99, 'Re: CX Login - Details and Link', 1600, '<b1@t>', $C),
    $mkOv(97, 'Re: CX Login - Details and Link', 1100, '<b2@t>', $C),
    $mkOv(90, 'Re: CX Login - Details and Link', 800,  '<b3@t>', $C, true, true),
    // and one message that belongs with nothing
    $mkOv(60, 'Welcome to your new mailbox', 100, '<w1@t>'),
];
$rows = [];
foreach (thread_group($inbox) as $g) $rows[] = thread_row($g);

t_eq('eight messages become three rows', count($rows), 3);
t_eq('the ticket conversation leads',    $rows[0]['subject'], 'Your Ticket #318531 has been Re-Opened');
t_eq('and reports all four members',     $rows[0]['thread_count'], 4);
t_eq('every one of them addressable',    $rows[0]['thread_uids'], [101, 100, 96, 88]);
t_ok('unread, because two members are',  $rows[0]['seen'] === false);
t_eq('the CX Login conversation is next', $rows[1]['thread_count'], 3);
t_ok('flagged, because one member is',   $rows[1]['flagged'] === true);
t_eq('the loner stays a single row',     $rows[2]['thread_count'], 1);

/* The header still counts messages, so the two numbers must reconcile: the
   badges plus the ungrouped rows have to add back up to the folder's total. */
$sum = 0;
foreach ($rows as $r) $sum += $r['thread_count'];
t_eq('badges account for every message', $sum, count($inbox));

/* And paging over those conversations returns them whole, never half a thread. */
$plan = thread_page_plan(count($rows), 0, 1, 25);
t_eq('one page holds all three', $plan['conv_count'], 3);
t_eq('and there is only one page', $plan['pages'], 1);

/* With a page size of two, no conversation is split across the boundary — a row
   is atomic, which is the property that makes conversation paging safe. */
$p1 = thread_page_plan(count($rows), 0, 1, 2);
$p2 = thread_page_plan(count($rows), 0, 2, 2);
$page1 = array_slice($rows, $p1['conv_offset'], $p1['conv_count']);
$page2 = array_slice($rows, $p2['conv_offset'], $p2['conv_count']);
t_eq('page 1 has two whole conversations', count($page1), 2);
t_eq('page 2 has the third',               count($page2), 1);
$seenUids = [];
foreach (array_merge($page1, $page2) as $r) foreach ($r['thread_uids'] as $u) $seenUids[] = $u;
sort($seenUids);
$allUids = array_map(fn($m) => $m['uid'], $inbox);
sort($allUids);
t_eq('and between them every message is reachable', $seenUids, $allUids);

t_group('scanning back from the newest message');
/**
 * The list reads recent messages in chunks so a slow host can be noticed
 * between commands. A gap in that walk silently drops messages out of the
 * grouping; an overlap silently reads them twice. Both are invisible on screen,
 * so the arithmetic is asserted here instead.
 */
$flatten = function (array $chunks) {
    $seen = [];
    foreach ($chunks as [$lo, $hi]) for ($i = $lo; $i <= $hi; $i++) $seen[] = $i;
    return $seen;
};

$bad = [];
foreach ([0, 1, 5, 99, 100, 101, 400, 401, 5000] as $total) {
    foreach ([1, 100, 400] as $window) {
        foreach ([1, 7, 100] as $chunk) {
            $c    = thread_scan_chunks($total, $window, $chunk);
            $seen = $flatten($c);
            $want = min($window, $total);
            // exactly the $want NEWEST messages, each once, newest chunk first
            $expect = [];
            for ($i = $total; $i > $total - $want; $i--) $expect[] = $i;
            sort($expect);
            $got = $seen; sort($got);
            if ($got !== $expect)                       $bad[] = "coverage $total/$window/$chunk";
            if (count($seen) !== count(array_unique($seen))) $bad[] = "overlap $total/$window/$chunk";
            foreach ($c as [$lo, $hi]) {
                if ($lo < 1 || $hi > $total || $lo > $hi)     $bad[] = "range $total/$window/$chunk";
                if ($hi - $lo + 1 > $chunk)                  $bad[] = "oversized $total/$window/$chunk";
            }
        }
    }
}
t_ok('every shape scans the newest slice exactly once', $bad === [],
     implode(' | ', array_slice(array_unique($bad), 0, 5)));

t_group('the scan is capped and ordered');
$c = thread_scan_chunks(5000, 400, 100);
t_eq('a big mailbox reads only the window', count($flatten($c)), 400);
t_eq('in chunk-sized pieces', count($c), 4);
t_eq('starting at the newest message', $c[0][1], 5000);
t_ok('and working backwards', $c[0][0] > $c[1][0], json_encode(array_slice($c, 0, 2)));
t_eq('stopping at the window edge', $c[3][0], 4601);

t_group('a scan can stop early without breaking the tail arithmetic');
/* The action may abandon the walk on a slow host, so the window is whatever was
   actually read. Two chunks of 100 out of a 5000-message folder must leave the
   remaining 4800 addressable as the ungrouped tail. */
$c        = thread_scan_chunks(5000, 400, 100);
$scanned  = 0;
foreach (array_slice($c, 0, 2) as [$lo, $hi]) $scanned += $hi - $lo + 1;
t_eq('two chunks read 200', $scanned, 200);
$winStart = 5000 - $scanned + 1;
$older    = $winStart - 1;
t_eq('the window starts where the read stopped', $winStart, 4801);
t_eq('and everything older is still listed', $older, 4800);
$plan = thread_page_plan(50, $older, 1, 25);   // 50 conversations from those 200
t_eq('page 1 is still a full page', $plan['conv_count'] + $plan['tail_count'], 25);
t_ok('and the folder is still fully reachable',
     $plan['rows'] === 50 + 4800, 'rows=' . $plan['rows']);

t_group('an empty or tiny folder needs no special case');
t_eq('no messages, no chunks', thread_scan_chunks(0, 400, 100), []);
t_eq('one message, one chunk',  thread_scan_chunks(1, 400, 100), [[1, 1]]);
t_eq('a folder smaller than the window is read whole',
     thread_scan_chunks(5, 400, 100), [[1, 5]]);
t_ok('a zero chunk size cannot loop forever', count(thread_scan_chunks(10, 400, 0)) === 10);
