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

t_group('rules: IMAP translation');
$m = ['mode' => 'all', 'from' => 'a@x', 'cc' => 'c@x', 'subject' => 'Report', 'larger' => 1048576, 'smaller' => 0, 'older_than' => 0, 'newer_than' => 7, 'query' => ''];
t_eq('all: one list, which IMAP ANDs', rule_to_imap_search($m, $NOW), 'FROM "a@x" CC "c@x" SUBJECT "Report" LARGER 1048576 SINCE ' . search_imap_date(date('Y-m-d', $NOW - 7 * 86400)));
$m['mode'] = 'any';
t_eq('any: nested OR', rule_to_imap_search($m, $NOW), 'OR (FROM "a@x") (OR (CC "c@x") (OR (SUBJECT "Report") (OR (LARGER 1048576) (SINCE ' . search_imap_date(date('Y-m-d', $NOW - 7 * 86400)) . '))))');
t_eq('any with one condition needs no OR', rule_to_imap_search(['mode' => 'any', 'from' => 'a@x'], $NOW), 'FROM "a@x"');
t_eq('older than 30 days', rule_to_imap_search(['older_than' => 30], $NOW), 'BEFORE ' . search_imap_date(date('Y-m-d', $NOW - 30 * 86400)));
t_eq('the query speaks the search box\'s language', rule_to_imap_search(['query' => 'from:boss is:unread -subject:newsletter'], $NOW), 'FROM "boss" NOT SUBJECT "newsletter" UNSEEN');
t_eq('query plus boxes, any-mode', rule_to_imap_search(['mode' => 'any', 'subject' => 'Invoice', 'query' => 'from:accounts'], $NOW), 'OR (SUBJECT "Invoice") (FROM "accounts")');
t_eq('nothing is ALL', rule_to_imap_search(['mode' => 'any'], $NOW), 'ALL');
t_eq('a quote in a value is escaped', rule_to_imap_search(['from' => 'a"b'], $NOW), 'FROM "a\\"b"');
t_eq('OR join of three', rule_or_join(['A', 'B', 'C']), 'OR (A) (OR (B) (C))');
t_eq('OR join of two', rule_or_join(['A', 'B']), 'OR (A) (B)');
t_eq('OR join of one', rule_or_join(['A']), 'A');
