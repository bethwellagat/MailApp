<?php
/**
 * Filter rules: richer conditions, translated to IMAP SEARCH — pure.
 */
require_once T_ROOT . '/lib/rules.php';
$NOW = 1792400000;   // 2026-10-19

t_group('rules: sizes and ages');
t_eq('5M', rule_size_bytes('5M'), 5242880);
t_eq('500K', rule_size_bytes('500K'), 512000);
t_eq('plain bytes', rule_size_bytes('1024'), 1024);
t_eq('an int passes through', rule_size_bytes(2048), 2048);
t_eq('nonsense is unset', rule_size_bytes('big'), 0);
t_eq('back to text', [rule_size_text(5242880), rule_size_text(51200), rule_size_text(1500), rule_size_text(0)], ['5M', '50K', '1500', '']);
t_eq('days are clamped', [rule_days(-3), rule_days(30), rule_days(99999)], [0, 30, 3650]);

t_group('rules: sanitising');
$r = sanitize_rule(['match' => ['from' => 'boss@x', 'cc' => 'me@x', 'larger' => '2M', 'older_than' => '45', 'mode' => 'any', 'query' => ' from:a   is:unread '], 'actions' => ['star' => true]]);
t_eq('every new field is kept, tidy', [$r['match']['mode'], $r['match']['cc'], $r['match']['larger'], $r['match']['older_than'], $r['match']['query']], ['any', 'me@x', 2097152, 45, 'from:a is:unread']);
t_eq('an unknown mode is "all"', sanitize_rule(['match' => ['from' => 'x', 'mode' => 'some'], 'actions' => ['star' => true]])['match']['mode'], 'all');
t_ok('a size alone is a condition', sanitize_rule(['match' => ['smaller' => '10K'], 'actions' => ['star' => true]]) !== null);
t_ok('an age alone is a condition', sanitize_rule(['match' => ['older_than' => 30], 'actions' => ['delete' => true]]) !== null);
t_ok('a query alone is a condition', sanitize_rule(['match' => ['query' => 'is:unread'], 'actions' => ['mark_read' => true]]) !== null);
t_ok('has:attachment in the query ticks the box', sanitize_rule(['match' => ['query' => 'from:x has:attachment'], 'actions' => ['star' => true]])['match']['has_attachment'] === true);
t_eq('a query the parser rejects is reported', rule_query_errors('after:yesterday-ish') !== [], true);
t_eq('a good query has no errors', rule_query_errors('from:a -subject:b larger:5M'), []);

t_group('rules: the match as work to do');
/* A rule is never one expression. PHP's IMAP library has no OR and no NOT, so
   each condition is its own plain search (see lib/rules.php). */
$m = ['mode' => 'all', 'from' => 'a@x', 'cc' => 'c@x', 'subject' => 'Report', 'larger' => 1048576, 'smaller' => 0, 'older_than' => 0, 'newer_than' => 7, 'query' => ''];
$crits = fn($plan) => array_column(array_filter($plan['conds'], fn($c) => isset($c['crit'])), 'crit');
t_eq('every box is its own plain key', $crits(rule_exec_plan($m, $NOW)),
     ['FROM "a@x"', 'CC "c@x"', 'SUBJECT "Report"', 'LARGER 1048576', 'SINCE ' . search_imap_date(date('Y-m-d', $NOW - 7 * 86400))]);
t_eq('the mode is carried, not baked into an expression', [rule_exec_plan($m, $NOW)['mode'], rule_exec_plan(['mode' => 'any', 'from' => 'a@x'], $NOW)['mode']], ['all', 'any']);
t_eq('older than 30 days', $crits(rule_exec_plan(['older_than' => 30], $NOW)), ['BEFORE ' . search_imap_date(date('Y-m-d', $NOW - 30 * 86400))]);
t_eq('a quote in a value is escaped', $crits(rule_exec_plan(['from' => 'a"b'], $NOW)), ['FROM "a\\"b"']);
t_eq('nothing to match asks nothing', rule_exec_plan(['mode' => 'any'], $NOW), ['mode' => 'any', 'conds' => [], 'nots' => []]);
t_eq('"doesn\'t have the words" is taken away, never sent as NOT', rule_exec_plan(['not_words' => 'spam'], $NOW)['nots'], [['TEXT "spam"']]);

$q = rule_exec_plan(['query' => 'from:boss is:unread -subject:newsletter'], $NOW)['conds'][0]['exec'];
t_eq('the query speaks the search box\'s language', $q['simple'], 'FROM "boss" UNSEEN');
t_eq('…and what it excludes is a group to take away', $q['nots'], [['SUBJECT "newsletter"']]);
t_eq('a query that asks nothing is not a condition', rule_exec_plan(['from' => 'a@x', 'query' => 'in:Archive'], $NOW)['conds'], [['crit' => 'FROM "a@x"']]);
$lit = rule_exec_plan(['query' => 'quotes -cancelled'], $NOW)['conds'][0]['exec'];
t_eq('a rule takes its words literally — no stem, so it acts on what it was told',
     [$lit['words'][0]['variants'], $lit['nots']],
     [[['SUBJECT "quotes"', 'FROM "quotes"', 'TO "quotes"']], [['SUBJECT "cancelled"', 'FROM "cancelled"', 'TO "cancelled"']]]);

t_group('rules: nothing the mail library refuses is ever sent');
/* The same pretend mailbox the search tests use: a list of simple keys, ANDed.
   Anything with OR or NOT in it is refused — which, to the caller, is
   indistinguishable from "no message matched". */
$MAIL = [
    1 => ['subject' => 'Revised quote',  'from' => 'boss@x.test',    'to' => 'me@x.test', 'cc' => '',           'body' => 'the quote is attached'],
    2 => ['subject' => 'Lunch',          'from' => 'grace@x.test',   'to' => 'me@x.test', 'cc' => '',           'body' => 'nothing much'],
    3 => ['subject' => 'Invoice 42',     'from' => 'billing@x.test', 'to' => 'ap@x.test', 'cc' => 'me@x.test',  'body' => 'payment due'],
    4 => ['subject' => 'Newsletter',     'from' => 'boss@x.test',    'to' => 'all@x.test','cc' => '',           'body' => 'unsubscribe here'],
];
$SENT = [];
$run = function ($crit) use ($MAIL, &$SENT) {
    $SENT[] = $crit;
    if (preg_match('/\b(OR|NOT)\b/', $crit)) return [];          // refused, as the real library is
    if ($crit === 'ALL') return array_keys($MAIL);
    preg_match_all('/(TEXT|SUBJECT|FROM|TO|CC) "([^"]*)"/', $crit, $mm, PREG_SET_ORDER);
    $hits = [];
    foreach ($MAIL as $uid => $msg) {
        $ok = true;
        foreach ($mm as [$_, $key, $val]) {
            $hay = $key === 'TEXT' ? implode(' ', $msg) : $msg[strtolower($key)];
            if (mb_stripos($hay, $val) === false) { $ok = false; break; }
        }
        if ($ok) $hits[] = $uid;
    }
    return $hits;
};
$go = function ($match) use ($run, &$SENT, $NOW) {
    $SENT = [];
    return rule_exec_run(rule_exec_plan($match, $NOW), $run);
};
$clean = fn() => !array_filter($SENT, fn($c) => preg_match('/\b(OR|NOT)\b/', $c));

t_eq('match all: one search, as it always was', $go(['from' => 'boss', 'subject' => 'quote']), [1]);
t_eq('…and it was one', count($SENT), 1);
t_eq('match any finds either side', $go(['mode' => 'any', 'from' => 'grace', 'subject' => 'Invoice']), [2, 3]);
t_ok('…without an OR on the wire', $clean() && count($SENT) === 2);
t_eq('an any-rule of one condition is still that condition', $go(['mode' => 'any', 'from' => 'boss']), [1, 4]);
t_eq('"doesn\'t have the words" takes mail away', $go(['from' => 'boss', 'not_words' => 'unsubscribe']), [1]);
t_ok('…with no NOT on the wire', $clean());
t_eq('under any, "doesn\'t have" is everything without them', $go(['mode' => 'any', 'from' => 'nobody', 'not_words' => 'quote']), [2, 3, 4]);
t_eq('a bare word in the query matches across the headers', $go(['query' => 'invoice']), [3]);
t_ok('…and the alternatives went one at a time', $clean());
t_eq('to: in a query is To or Cc', $go(['query' => 'to:me@x.test']), [1, 2, 3]);
t_eq('-from: in a query excludes', $go(['query' => 'invoice -from:billing']), []);
t_eq('the query and the boxes are ANDed under all', $go(['from' => 'boss', 'query' => 'newsletter']), [4]);
t_ok('…with the boxes riding along on the query\'s searches, not a search of their own',
     count($SENT) === 3 && !array_filter($SENT, fn($c) => strpos($c, 'FROM "boss"') === false));
t_eq('…and ORed under any', $go(['mode' => 'any', 'subject' => 'Lunch', 'query' => 'from:billing']), [2, 3]);
t_eq('nothing to match is the whole folder', $go(['mode' => 'any']), [1, 2, 3, 4]);
t_ok('no criterion the library refuses was sent, in any of it', $clean());
