<?php
/**
 * The search query language and the attachment index.
 *
 * Both are pure, so every rule that decides what a search MEANS is pinned here
 * without a mail server: how the box is tokenised, what each operator does,
 * the exact IMAP criterion each query becomes, and how the attachment index
 * answers the one question IMAP cannot.
 */

require_once T_ROOT . '/lib/util.php';
require_once T_ROOT . '/lib/search.php';
require_once T_ROOT . '/lib/attachindex.php';

/* A fixed clock, so relative dates are the same on every run. */
$NOW = strtotime('2026-10-19 12:00:00 UTC');
$imap = fn($q, $full = false) => search_to_imap(search_parse($q, $NOW), $full);

t_group('tokenising the search box');
$t = search_tokenize('from:thuraya has:attachment "pay slip" -spam');
t_eq('four tokens', count($t), 4);
t_eq('key:value', [$t[0]['key'], $t[0]['value'], $t[0]['not']], ['from', 'thuraya', false]);
t_eq('quoted phrase keeps its space', [$t[2]['key'], $t[2]['value']], ['', 'pay slip']);
t_eq('leading minus negates', [$t[3]['value'], $t[3]['not']], ['spam', true]);
t_eq('key with quoted value', search_tokenize('subject:"Re: 123"')[0], ['key' => 'subject', 'value' => 'Re: 123', 'not' => false]);
t_eq('hyphen inside a word is not a negation', search_tokenize('pay-slip')[0]['value'], 'pay-slip');
t_eq('a lone minus is ignored', search_tokenize('- ticket'), [['key' => '', 'value' => 'ticket', 'not' => false]]);
t_eq('an unterminated quote runs to the end', search_tokenize('"half typed')[0]['value'], 'half typed');
t_eq('keys are case-insensitive', search_tokenize('FROM:x')[0]['key'], 'from');
t_eq('extra spaces are harmless', count(search_tokenize('   a    b   ')), 2);
t_eq('control characters are stripped', search_tokenize("a\x00b")[0]['value'], 'a');
t_ok('term count is capped', count(search_tokenize(str_repeat('w ', 50))) === SEARCH_MAX_TERMS);

t_group('every operator means one thing');
$p = search_parse('from:thuraya -from:noreply to:me subject:ticket -subject:spam', $NOW);
t_eq('from',         $p['from'],        ['thuraya']);
t_eq('not from',     $p['not_from'],    ['noreply']);
t_eq('to',           $p['to'],          ['me']);
t_eq('subject',      $p['subject'],     ['ticket']);
t_eq('not subject',  $p['not_subject'], ['spam']);
t_eq('cc is read as to', search_parse('cc:boss')['to'], ['boss']);

$p = search_parse('is:unread is:flagged is:answered', $NOW);
t_eq('states', $p['is'], ['unread', 'flagged', 'answered']);
t_eq('starred is flagged', search_parse('is:starred')['is'], ['flagged']);
t_eq('-is:read resolves to unread', search_parse('-is:read')['is'], ['unread']);
t_eq('-is:unread resolves to read', search_parse('-is:unread')['is'], ['read']);
t_eq('duplicates collapse', search_parse('is:unread is:unread')['is'], ['unread']);

t_eq('has:attachment',     search_parse('has:attachment')['has'], 'attachment');
t_eq('has:file is the same', search_parse('has:file')['has'], 'attachment');
t_eq('has:no-attachment',  search_parse('has:no-attachment')['has'], 'no-attachment');
t_eq('-has:attachment is no-attachment', search_parse('-has:attachment')['has'], 'no-attachment');
t_eq('in:', search_parse('in:archive')['in'], 'archive');
t_eq('folder: is an alias', search_parse('folder:Sent')['in'], 'Sent');

t_group('dates, absolute and relative');
t_eq('after Y-m-d',   search_parse('after:2026-07-01', $NOW)['after'], '2026-07-01');
t_eq('slashes work',  search_parse('after:2026/7/1', $NOW)['after'],   '2026-07-01');
t_eq('before',        search_parse('before:2026-09-30', $NOW)['before'], '2026-09-30');
t_eq('on',            search_parse('on:2026-08-14', $NOW)['on'],       '2026-08-14');
t_eq('newer:7d counts back from now',   search_parse('newer:7d', $NOW)['after'],  '2026-10-12');
t_eq('after:2w',                         search_parse('after:2w', $NOW)['after'],  '2026-10-05');
t_eq('older:3m',                         search_parse('older:3m', $NOW)['before'], '2026-07-19');
t_eq('older:1y',                         search_parse('older:1y', $NOW)['before'], '2025-10-19');
t_eq('since / until are aliases', [search_parse('since:2026-01-01')['after'], search_parse('until:2026-01-01')['before']], ['2026-01-01', '2026-01-01']);
t_ok('an impossible date is an error, not a silent guess',
     search_parse('after:2026-02-30')['after'] === null && count(search_parse('after:2026-02-30')['errors']) === 1);
t_ok('a word is not a date', search_parse('before:yesterday')['before'] === null);

t_group('sizes');
t_eq('5M',   search_parse('larger:5M')['larger'],   5242880);
t_eq('500K', search_parse('smaller:500K')['smaller'], 512000);
t_eq('2G',   search_parse('larger:2G')['larger'],   2147483648);
t_eq('1.5M', search_parse('larger:1.5M')['larger'], 1572864);
t_eq('bare bytes', search_parse('larger:4096')['larger'], 4096);
t_eq('MB suffix tolerated', search_parse('larger:5MB')['larger'], 5242880);
t_ok('nonsense is an error', search_parse('larger:big')['larger'] === null && search_parse('larger:big')['errors'] !== []);
t_ok('zero is an error', search_parse('larger:0')['larger'] === null);

t_group('what is not an operator stays searchable text');
t_eq('re:123 is a word, not an operator', search_parse('re:123')['text'], ['re:123']);
t_eq('a URL-ish token survives', search_parse('http://x.y')['text'], ['http://x.y']);
t_eq('is:bogus is reported', count(search_parse('is:bogus')['errors']), 1);
t_eq('…and the rest of the query still runs', search_parse('is:bogus ticket')['text'], ['ticket']);
t_eq('an operator with no value is reported', count(search_parse('from:')['errors']), 1);

t_group('the exact IMAP criterion');
/**
 * These strings are the contract with the server. IMAP ANDs listed keys, OR
 * takes exactly two, NOT takes one — so three alternatives are "OR OR a b c"
 * and a negated alternative is "NOT OR OR a b c". Built in a fixed order so
 * the same query is always the same string.
 */
t_eq('a bare word searches subject, from and to',
     $imap('ticket'), 'OR OR SUBJECT "ticket" FROM "ticket" TO "ticket"');
t_eq('full text adds BODY as a fourth alternative',
     $imap('ticket', true), 'OR OR OR SUBJECT "ticket" FROM "ticket" TO "ticket" BODY "ticket"');
t_eq('a phrase is one quoted value',
     $imap('"pay slip"'), 'OR OR SUBJECT "pay slip" FROM "pay slip" TO "pay slip"');
t_eq('two words are two ANDed keys',
     $imap('pay slip'), 'OR OR SUBJECT "pay" FROM "pay" TO "pay" OR OR SUBJECT "slip" FROM "slip" TO "slip"');
t_eq('negated word',
     $imap('-spam'), 'NOT OR OR SUBJECT "spam" FROM "spam" TO "spam"');
t_eq('from',          $imap('from:thuraya'),  'FROM "thuraya"');
t_eq('not from',      $imap('-from:noreply'), 'NOT FROM "noreply"');
t_eq('to covers cc',  $imap('to:me'),         'OR TO "me" CC "me"');
t_eq('not to',        $imap('-to:me'),        'NOT OR TO "me" CC "me"');
t_eq('subject',       $imap('subject:x'),     'SUBJECT "x"');
t_eq('states',        $imap('is:unread is:flagged is:answered'), 'UNSEEN FLAGGED ANSWERED');
t_eq('negated states', $imap('-is:read -is:flagged'), 'UNSEEN UNFLAGGED');
t_eq('date range',    $imap('after:2026-07-01 before:2026-09-30'), 'SINCE 1-Jul-2026 BEFORE 30-Sep-2026');
t_eq('on a day',      $imap('on:2026-08-14'), 'ON 14-Aug-2026');
t_eq('on overrides a range (they cannot both apply)', $imap('on:2026-08-14 after:2026-01-01'), 'ON 14-Aug-2026');
t_eq('relative date', $imap('newer:7d'), 'SINCE 12-Oct-2026');
t_eq('sizes',         $imap('larger:5M smaller:10M'), 'LARGER 5242880 SMALLER 10485760');
t_eq('everything together, in a fixed order',
     $imap('ticket from:thuraya is:unread after:2026-07-01 larger:1M'),
     'OR OR SUBJECT "ticket" FROM "ticket" TO "ticket" FROM "thuraya" UNSEEN SINCE 1-Jul-2026 LARGER 1048576');
t_eq('an empty query is ALL', $imap(''), 'ALL');
t_eq('has: and in: do not reach IMAP', $imap('has:attachment in:archive'), 'ALL');
t_ok('…and the parser knows that', search_is_unconstrained(search_parse('has:attachment in:archive')));
t_ok('while a real term is constrained', !search_is_unconstrained(search_parse('ticket')));

t_group('values are escaped for the IMAP quoted string');
/* Only backslash and the quote itself are special inside an IMAP quoted
   string. A value that broke out of the quotes would let a search box write
   arbitrary search keys — not a breach, but a bug a stranger could trigger. */
t_eq('a quote is escaped', search_imap_quote('say "hi"'), '"say \\"hi\\""');
t_eq('a backslash is escaped', search_imap_quote('a\\b'), '"a\\\\b"');
t_eq('newlines become spaces', search_imap_quote("a\nb"), '"a b"');
t_eq('via the parser', $imap('from:a"b'), 'FROM "a\\"b"');

t_group('the query described back in words');
$d = search_describe(search_parse('from:thuraya has:attachment after:2026-07-01 is:unread "pay slip" in:archive', $NOW));
t_eq('one chip per constraint', count($d), 6);
t_eq('labels read naturally', array_column($d, 'label'),
     ['“pay slip”', 'from thuraya', 'unread', 'has attachment', 'after 1 Jul 2026', 'in archive']);
t_eq('sizes are humanised', search_describe(search_parse('larger:5M'))[0]['label'], 'larger than 5 MB');
t_eq('no attachment', search_describe(search_parse('has:no-attachment'))[0]['label'], 'no attachment');

t_group('in: resolves against the real folder list');
$folders = ['INBOX', 'INBOX.Sent', 'INBOX.Archive', 'INBOX.Drafts', 'INBOX.Trash', 'INBOX.Junk', 'Spam', 'Clients/Thuraya'];
t_eq('type word → the usual folder', search_resolve_folder('archive', $folders), 'INBOX.Archive');
t_eq('sent',    search_resolve_folder('sent', $folders),    'INBOX.Sent');
t_eq('trash',   search_resolve_folder('trash', $folders),   'INBOX.Trash');
t_eq('deleted is an alias for trash', search_resolve_folder('deleted', $folders), 'INBOX.Trash');
t_eq('spam matches the top-level Spam first', search_resolve_folder('spam', $folders), 'Spam');
t_eq('junk', search_resolve_folder('junk', $folders), 'INBOX.Junk');
t_eq('a leaf name finds a nested folder', search_resolve_folder('thuraya', $folders), 'Clients/Thuraya');
t_eq('a full name, any case', search_resolve_folder('clients/thuraya', $folders), 'Clients/Thuraya');
t_eq('a prefix still finds it', search_resolve_folder('arch', $folders), 'INBOX.Archive');
t_eq('anywhere', search_resolve_folder('anywhere', $folders), 'anywhere');
t_eq('all', search_resolve_folder('all', $folders), 'anywhere');
t_ok('no match is null, not a guess', search_resolve_folder('nope', $folders) === null);

t_group('attachment index: files are named safely');
$p1 = attach_index_path('me@x.com', 'acct1', 'Clients/Thuraya & Co.');
$p2 = attach_index_path('me@x.com', 'acct1', 'INBOX');
$p3 = attach_index_path('ME@X.COM', 'acct1', 'Clients/Thuraya & Co.');
t_ok('the folder name never appears in the path', strpos($p1, 'Thuraya') === false && strpos($p1, '/Clients') === false);
t_ok('different folders, different files', $p1 !== $p2);
t_eq('the same mailbox, however the email is cased, is one file', $p1, $p3);
t_ok('it lives under data/attach-index', strpos($p1, '/data/attach-index/') !== false);

t_group('attachment index: load, filter, merge, save');
$tmp = sys_get_temp_dir() . '/mailapp-attach-' . uniqid();
@mkdir($tmp, 0700, true);
$path = $tmp . '/idx.json';

$idx = attach_index_load($path, 42);
t_eq('a missing file is an empty index at this uidvalidity', $idx, ['uidvalidity' => 42, 'flags' => []]);

[$m, $u] = attach_index_filter($idx, [5, 6, 7], 'attachment');
t_eq('with nothing known, everything is unknown', [$m, $u], [[], [5, 6, 7]]);

t_eq('merging records answers', attach_index_merge($idx, [5 => true, 6 => false, 7 => true]), 3);
t_eq('merging the same answers again changes nothing', attach_index_merge($idx, [5 => true, 6 => false]), 0);
t_eq('a changed answer counts', attach_index_merge($idx, [6 => true]), 1);
attach_index_merge($idx, [6 => false]);

[$m, $u] = attach_index_filter($idx, [5, 6, 7, 8], 'attachment');
t_eq('has:attachment keeps the yeses, drops the no, reports the unknown', [$m, $u], [[5, 7], [8]]);
[$m, $u] = attach_index_filter($idx, [5, 6, 7, 8], 'no-attachment');
t_eq('has:no-attachment is the inverse', [$m, $u], [[6], [8]]);
[$m, $u] = attach_index_filter($idx, [7, 5], 'attachment');
t_eq('input order is preserved', $m, [7, 5]);

t_ok('save writes the file', attach_index_save($path, $idx) && is_file($path));
$back = attach_index_load($path, 42);
t_eq('and it reads back the same', $back['flags'], [5 => 1, 6 => 0, 7 => 1]);
$reset = attach_index_load($path, 43);
t_eq('a new uidvalidity discards everything', $reset, ['uidvalidity' => 43, 'flags' => []]);

t_group('attachment index: the cap keeps the newest');
$big = attach_index_empty(1);
for ($i = 1; $i <= 30; $i++) $big['flags'][$i] = $i % 2;
attach_index_save($path, $big, 10);
$capped = attach_index_load($path, 1);
t_eq('ten entries survive', count($capped['flags']), 10);
t_eq('and they are the highest UIDs', min(array_keys($capped['flags'])), 21);

@unlink($path); @unlink($path . '.lock'); @rmdir($tmp);
