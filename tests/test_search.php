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

/* =========================================================================
   A sentence, understood: stemming, stop words, the ladder, the ranking.
   ========================================================================= */

t_group('search: the stem of a word');
foreach ([['quote','quot'],['quotes','quot'],['quoting','quot'],['quotation','quot'],['quotations','quot']] as [$w,$e]) {
    t_eq("$w → $e", search_stem($w), $e);
}
t_eq('a whole family meets at one stem', array_unique(array_map('search_stem', ['book','books','booking','booked','bookings'])), ['book']);
t_eq('…and another',                     array_unique(array_map('search_stem', ['invoice','invoices','invoicing'])), ['invoic']);
t_eq('plurals alone',                    array_unique(array_map('search_stem', ['safari','safaris'])), ['safari']);
t_eq('y plurals',                        search_stem('queries'), 'query');
t_ok('short words are left alone',       search_stem('car') === 'car' && search_stem('cars') === 'car' && search_stem('the') === 'the');
t_ok('a double s is not a plural',       search_stem('address') === 'address' && search_stem('press') === 'press');
t_ok('a stem is never shorter than three letters', !array_filter(['ties','uses','ages','eyes','axes'], fn($w) => mb_strlen(search_stem($w)) < 3));
t_eq('anything that is not a word is untouched', [search_stem('a1b2'), search_stem('x@y.com'), search_stem('')], ['a1b2', 'x@y.com', '']);

t_group('search: words that cannot narrow anything down');
t_ok('the usual suspects', search_is_stop('the') && search_is_stop('For') && search_is_stop('and') && search_is_stop('with'));
t_ok('…and real words are not', !search_is_stop('safari') && !search_is_stop('quote') && !search_is_stop('invoice'));

t_group('search: sorting the words of a sentence');
$t = search_terms(search_parse('quote for the safari package'));
t_eq('meaning-carrying words are stemmed and required', $t['required'], ['quot', 'safari', 'packag']);
t_eq('…the rest only help the ranking',                 $t['optional'], ['for', 'the']);
t_eq('…and the sentence is kept whole for the first attempt', $t['phrase'], 'quote for the safari package');
$t = search_terms(search_parse('"final invoice" urgent'));
t_eq('a quoted phrase is never broken up or stemmed', $t['exact'], ['final invoice']);
t_eq('…while the loose word still is',                $t['required'], ['urgent']);
$t = search_terms(search_parse('safari'));
t_eq('one word is not a phrase', $t['phrase'], '');
$t = search_terms(search_parse('the and for'));
t_ok('a query of nothing but stop words still means them', $t['required'] === [] && count($t['optional']) === 3);

t_group('search: one word, in the whole message');
t_eq('by default the body counts too', search_word_key('safari'), 'TEXT "safari"');
t_eq('headers only when asked',        search_word_key('safari', false), 'OR OR SUBJECT "safari" FROM "safari" TO "safari"');
t_eq('two alternatives',   search_or_keys(['A', 'B']), 'OR A B');
t_eq('three nest to the right', search_or_keys(['A', 'B', 'C']), 'OR A OR B C');
t_eq('one is itself',      search_or_keys(['A']), 'A');
t_eq('none is nothing',    search_or_keys([]), '');

t_group('search: the ladder of attempts');
$plan = search_plan(search_parse('quote for the safari package'));
t_eq('two rungs: every word, then most of them', array_column($plan['attempts'], 'mode'), ['all', 'any']);
t_eq('the phrase is a ranking signal, never a filter that hides the rest', $plan['phrase'], 'TEXT "quote for the safari package"');
t_eq('every word — as typed OR as its stem, so a word-indexed server and a substring one both match',
     $plan['attempts'][0]['criterion'], 'OR TEXT "quote" TEXT "quot" TEXT "safari" OR TEXT "package" TEXT "packag"');
t_eq('or any of them',          $plan['attempts'][1]['criterion'], 'OR OR TEXT "quote" TEXT "quot" OR TEXT "safari" OR TEXT "package" TEXT "packag"');
t_eq('a word that is its own stem is searched once', search_term_key($plan['terms'], 'safari'), 'TEXT "safari"');
$two = search_terms(search_parse('quote quotation'));
t_eq('two spellings of one stem are both kept', search_term_key($two, 'quot'), 'OR TEXT "quote" OR TEXT "quotation" TEXT "quot"');
t_eq('finding every word is the expected answer, so it says nothing', $plan['attempts'][0]['note'], '');
t_ok('…but giving up on some of them is a surprise, and says so', str_contains($plan['attempts'][1]['note'], 'closest matches'));
$quoted = search_plan(search_parse('"final invoice" urgent'));
t_ok('a phrase the user put in quotes is required as typed', str_contains($quoted['attempts'][0]['criterion'], 'TEXT "final invoice"') && $quoted['phrase'] === '');
$sc = search_terms(search_parse('safari package'));
t_ok('the sentence found in order outranks the same words scattered',
     search_score(['subject' => 'x', 'timestamp' => 5], $sc, ['safari', 'packag', SEARCH_PHRASE_HIT], 5)
   > search_score(['subject' => 'x', 'timestamp' => 5], $sc, ['safari', 'packag'], 5));
$plan = search_plan(search_parse('safari'));
t_eq('one word needs no ladder', array_column($plan['attempts'], 'mode'), ['all']);
t_eq('…and is searched whole',   $plan['attempts'][0]['criterion'], 'TEXT "safari"');

t_group('search: the operators are never relaxed');
$plan = search_plan(search_parse('from:thuraya quote safari package'));
foreach ($plan['attempts'] as $a) {
    t_ok('"' . $a['mode'] . '" still requires from:thuraya', str_contains($a['criterion'], 'FROM "thuraya"'));
}
$plan = search_plan(search_parse('quote safari is:unread has:attachment'));
t_ok('a flag survives every rung', !array_filter($plan['attempts'], fn($a) => !str_contains($a['criterion'], 'UNSEEN')));
$plan = search_plan(search_parse('quote -cancelled'));
t_ok('an excluded word stays excluded', str_contains($plan['attempts'][0]['criterion'], 'NOT TEXT "cancelled"'));
$plan = search_plan(search_parse('is:unread'));
t_eq('filters with no words at all still search', $plan['attempts'][0]['criterion'], 'UNSEEN');

t_group('search: which result is the best one');
$terms = search_terms(search_parse('safari package quote'));
$now = 1792400000;
$rows = [
    ['uid' => 1, 'subject' => 'Re: hello',                         'from_name' => 'Ada',  'from_addr' => 'a@x', 'timestamp' => $now - 86400],
    ['uid' => 2, 'subject' => 'Safari package quote for Thuraya',  'from_name' => 'Ops',  'from_addr' => 'o@x', 'timestamp' => $now - 400 * 86400],
    ['uid' => 3, 'subject' => 'Safari enquiry',                    'from_name' => 'Tour', 'from_addr' => 't@x', 'timestamp' => $now - 86400],
];
$matched = ['|1' => ['safari'], '|2' => ['safari', 'packag', 'quot'], '|3' => ['safari', 'quot']];
$ranked = search_rank($rows, $terms, $matched, $now);
t_eq('the one with every word in its subject wins, though it is a year old', $ranked[0]['uid'], 2);
t_eq('then the one with two of the words', $ranked[1]['uid'], 3);
t_eq('the passing mention is last',        $ranked[2]['uid'], 1);
t_ok('a subject match beats a body match', search_score(['subject' => 'safari', 'timestamp' => $now], $terms, [], $now)
                                         > search_score(['subject' => 'x', 'timestamp' => $now], $terms, ['safari'], $now));
t_ok('all the words beat some of them',    search_score(['subject' => 'safari packag quot', 'timestamp' => $now], $terms, [], $now)
                                         > search_score(['subject' => 'safari packag', 'timestamp' => $now], $terms, [], $now));
t_ok('recency decides a tie, and nothing more', search_score(['subject' => 'safari', 'timestamp' => $now], $terms, [], $now)
                                              - search_score(['subject' => 'safari', 'timestamp' => $now - 400 * 86400], $terms, [], $now) <= 9);

/* =========================================================================
   Showing the answer: charset, snippets, needles, top picks, arrangement.
   ========================================================================= */

t_group('search: a word that is not ASCII');
t_ok('plain English needs no charset',  !search_needs_charset('TEXT "safari"'));
t_ok('an accent does',                   search_needs_charset('TEXT "café"'));
t_ok('Arabic does',                      search_needs_charset('TEXT "عرض"'));
t_eq('non-Latin words are searched as typed, never stemmed', search_terms(search_parse('عرض سعر'))['required'], ['عرض', 'سعر']);

t_group('search: the same UID in two folders is two messages');
t_ok('keys differ by folder', search_row_key(['folder' => 'INBOX', 'uid' => 7]) !== search_row_key(['folder' => 'Sent', 'uid' => 7]));
$tw = search_terms(search_parse('safari quote'));
$rk = search_rank([['folder' => 'INBOX', 'uid' => 7, 'subject' => 'x', 'timestamp' => 5], ['folder' => 'Sent', 'uid' => 7, 'subject' => 'x', 'timestamp' => 5]],
                  $tw, ['Sent|7' => ['safari', 'quot']], 5);
t_eq('what one matched is not credited to the other', [$rk[0]['folder'], $rk[0]['score'] > $rk[1]['score']], ['Sent', true]);

t_group('search: what to mark on screen');
$n = search_needles(search_terms(search_parse('quote for the safari package')));
t_eq('the stems, not the filler',           $n['stems'], ['quot', 'safari', 'packag']);
t_eq('…and the sentence as a phrase',       $n['phrases'], ['quote for the safari package']);
$n = search_needles(search_terms(search_parse('"final invoice" urgent')));
t_eq('a quoted phrase is marked whole',     $n['phrases'], ['final invoice']);

t_group('search: a message as readable text');
t_eq('markup, styles and scripts go; words stay',
     search_plain_text('<style>p{color:red}</style><p>Dear&nbsp;Ada,</p><div>your <b>quotation</b><br>is attached.</div><script>x()</script>', true),
     'Dear Ada, your quotation is attached.');
t_eq('plain text only has its whitespace tidied', search_plain_text("Hello\r\n\r\n  there\tyou"), 'Hello there you');

t_group('search: the line that answers the question');
$body = 'Dear Thuraya, thank you for your enquiry about travel in August. ' . str_repeat('We are glad to help. ', 12)
      . 'Please find attached our quotation for the Masai Mara safari package for two guests, valid for 14 days. '
      . str_repeat('Kind regards to all. ', 10);
$sn = search_snippet($body, ['stems' => ['quot', 'safari', 'packag'], 'phrases' => []]);
t_ok('it is cut from where the words are, not from the top', str_contains($sn, 'quotation') && str_contains($sn, 'safari package') && !str_contains($sn, 'Dear Thuraya'));
t_ok('…marked as an excerpt at both ends', str_starts_with($sn, '…') && str_ends_with($sn, '…'));
t_ok('…and of a readable length', mb_strlen($sn) <= 175);
t_ok('…starting on a word, not inside one', preg_match('/^…[A-Za-z]/u', $sn) === 1);
$sn = search_snippet('aaa bbb. The final invoice is attached. Then the invoice total and final notes.', ['stems' => ['final', 'invoic'], 'phrases' => ['final invoice']]);
t_ok('a phrase wins over scattered words', str_contains($sn, 'final invoice is attached'));
$win = search_snippet('safari here. ' . str_repeat('filler words go on. ', 20) . 'the quotation for the safari package is ready.', ['stems' => ['quot', 'safari', 'packag'], 'phrases' => []]);
t_ok('the window with the most words wins over the first word seen', str_contains($win, 'quotation for the safari package'));
t_eq('no word in the text → the opening stands in', search_snippet('Hello there, this is the start of the message.', ['stems' => ['zebra'], 'phrases' => []]), 'Hello there, this is the start of the message.');
t_eq('nothing in, nothing out', search_snippet('', ['stems' => ['x'], 'phrases' => []]), '');
t_ok('a word is matched at its start, not in the middle of another', !str_contains(search_snippet(str_repeat('zz ', 80) . 'discard this. ' . str_repeat('yy ', 80) . 'the car is here.', ['stems' => ['car'], 'phrases' => []]), 'discard'));
t_ok('multibyte text is cut on characters, not bytes', mb_check_encoding(search_snippet(str_repeat('مرحبا بكم في رحلة السفاري ', 30), ['stems' => ['السفاري'], 'phrases' => []]), 'UTF-8'));

t_group('search: which answers stand out');
$mk = fn($uid, $score, $ts) => ['folder' => 'INBOX', 'uid' => $uid, 'score' => $score, 'timestamp' => $ts];
$rows = [$mk(1, 180, 100), $mk(2, 60, 900), $mk(3, 58, 800), $mk(4, 55, 700), $mk(5, 150, 50), $mk(6, 52, 600), $mk(7, 50, 500)];
t_eq('the ones that stand clear of the rest', search_pick_top($rows), ['INBOX|1', 'INBOX|5']);
t_eq('when every result is as good as the next, nothing is "top"', search_pick_top([$mk(1, 70, 1), $mk(2, 70, 2), $mk(3, 69, 3), $mk(4, 70, 4), $mk(5, 68, 5), $mk(6, 70, 6)]), []);
t_eq('a short list needs no summit', search_pick_top([$mk(1, 200, 1), $mk(2, 10, 2)]), []);
t_ok('never more than three', count(search_pick_top([$mk(1, 200, 1), $mk(2, 199, 2), $mk(3, 198, 3), $mk(4, 197, 4), $mk(5, 20, 5), $mk(6, 20, 6), $mk(7, 20, 7), $mk(8, 20, 8), $mk(9, 20, 9)])) === 3);

t_group('search: the order on screen');
$ar = search_arrange($rows, 'all');
t_eq('all the words → the standouts first, best of them leading', [$ar['rows'][0]['uid'], $ar['rows'][1]['uid']], [1, 5]);
t_eq('…then everything else newest first', array_column(array_slice($ar['rows'], 2), 'uid'), [2, 3, 4, 6, 7]);
t_ok('…and each row says which it is', $ar['rows'][0]['top'] === true && $ar['rows'][2]['top'] === false && $ar['order'] === 'date');
$ar = search_arrange(search_rank([['folder' => 'INBOX', 'uid' => 1, 'subject' => 'safari', 'timestamp' => 1], ['folder' => 'INBOX', 'uid' => 2, 'subject' => 'safari quote', 'timestamp' => 1]], $tw, [], 5), 'any');
t_eq('near-misses stay best-first, with no summit', [$ar['order'], $ar['rows'][0]['uid'], $ar['rows'][0]['top']], ['score', 2, false]);

t_group('search: nothing the mail library refuses is ever sent');
/* A pretend mailbox that behaves like PHP's IMAP library: a list of simple
   keys, ANDed. Anything with OR or NOT in it is refused — which, to the
   caller, is indistinguishable from "no messages". */
$MAIL = [
    1 => ['subject' => 'Revised quote for the safari', 'from' => 'wanjiru@tours.test', 'to' => 'amina@x.test', 'cc' => '', 'body' => 'the quote is attached'],
    2 => ['subject' => 'Lunch',                         'from' => 'grace@x.test',       'to' => 'amina@x.test', 'cc' => '', 'body' => 'we got two quotations for the safari trip, both revised'],
    3 => ['subject' => 'Invoices for October',          'from' => 'billing@x.test',     'to' => 'amina@x.test', 'cc' => 'james@x.test', 'body' => 'see the file James_Otieno_CV.pdf'],
    4 => ['subject' => 'Safari photos',                 'from' => 'james@x.test',       'to' => 'amina@x.test', 'cc' => '', 'body' => 'nothing about prices'],
];
$SENT = [];
$fake = function ($tokenServer = false) use ($MAIL, &$SENT) {
    return function ($crit) use ($MAIL, &$SENT, $tokenServer) {
        $SENT[] = $crit;
        if (preg_match('/\b(OR|NOT)\b/', $crit)) return false;             // refused, as the real library does
        if ($crit === 'ALL') return array_keys($MAIL);
        preg_match_all('/(TEXT|SUBJECT|FROM|TO|CC) "([^"]*)"/', $crit, $m, PREG_SET_ORDER);
        $hits = [];
        foreach ($MAIL as $uid => $msg) {
            $ok = true;
            foreach ($m as [$_, $key, $val]) {
                $hay = $key === 'TEXT' ? implode(' ', $msg) : $msg[strtolower($key)];
                $found = $tokenServer
                    ? in_array(mb_strtolower($val), preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($hay)), true)
                    : mb_stripos($hay, $val) !== false;
                if (!$found) { $ok = false; break; }
            }
            if ($ok) $hits[] = $uid;
        }
        return $hits;
    };
};
$go = function ($q, $rung = 0, $full = true, $token = false) use ($fake, &$SENT) {
    $SENT = [];
    $plan = search_plan(search_parse($q), $full);
    $st = [];
    [$uids, $matched] = search_exec_run($plan['attempts'][$rung]['exec'], function ($c) use ($fake, $token) { $r = $fake($token)($c); return is_array($r) ? $r : []; }, null, $st);
    sort($uids);
    return [$uids, $matched];
};
$noOr = function () use (&$SENT) { return !array_filter($SENT, fn($c) => preg_match('/\b(OR|NOT)\b/', $c)); };

[$u] = $go('quotes');
t_eq('a plural finds the singular and the longer form', $u, [1, 2]);
t_ok('…without an OR on the wire', $noOr() && count($SENT) >= 1);
[$u] = $go('revised safari quote');
t_eq('three words, all required, each by typed form or stem', $u, [1, 2]);
t_ok('…still no OR', $noOr());
[$u] = $go('james');
t_eq('a name that stems (james → jam) is still found, in a filename and a header', $u, [3, 4]);
[$u] = $go('otieno');
t_eq('a name that appears only inside the message — in an attached file\'s name — is found', $u, [3]);
[$u, $mt] = $go('safari invoices', 1);
t_eq('most of the words: the union', $u, [1, 2, 3, 4]);
t_eq('…and it knows which word each one has', [$mt[3], $mt[4]], [['invoic'], ['safari']]);
[$u] = $go('safari -revised');
t_eq('an excluded word is taken away here, not with NOT', $u, [4]);
t_ok('…no NOT on the wire', $noOr());
[$u] = $go('to:james');
t_eq('to: means To or Cc, as two searches', $u, [3]);
t_ok('…no OR on the wire', $noOr());
[$u] = $go('safari -from:james');
t_eq('an excluded sender is taken away', $u, [1, 2]);
[$u] = $go('quotes safari', 0, false);
t_eq('subjects-and-senders only: each word in any of the three headers', $u, [1]);
t_ok('…as plain SUBJECT / FROM / TO searches', $noOr() && !array_filter($SENT, fn($c) => str_contains($c, 'TEXT')));
[$u] = $go('quotes', 0, true, true);
t_eq('a whole-word server finds the word as typed', $u, []);   // only "quote" and "quotations" exist as whole words
[$u] = $go('quotations revised', 0, true, true);
t_eq('…and the typed form is what rescues it there', $u, [2]);

$plan = search_plan(search_parse('safari from:james'), true);
t_eq('a query with one way to be true is one criterion — the fast, sortable path', search_exec_simple($plan['attempts'][0]['exec']), 'TEXT "safari" FROM "james"');
t_eq('…a word with two spellings is not', search_exec_simple(search_plan(search_parse('quotes'), true)['attempts'][0]['exec']), null);
t_eq('…nor is anything excluded', search_exec_simple(search_plan(search_parse('safari -lunch'), true)['attempts'][0]['exec']), null);
t_eq('filters alone stay one criterion', search_exec_simple(search_plan(search_parse('is:unread'), true)['attempts'][0]['exec']), 'UNSEEN');
t_ok('the simple keys never carry OR or NOT', !preg_match('/\b(OR|NOT)\b/', search_plan(search_parse('a -b to:c -to:d -from:e -subject:f quotes'), true)['attempts'][0]['exec']['simple']));
t_ok('the phrase used for ranking is one plain key', search_plan(search_parse('revised safari quote'), true)['phrase'] === 'TEXT "revised safari quote"' && search_plan(search_parse('revised safari quote'), false)['phrase'] === 'SUBJECT "revised safari quote"');

// A substring server is recognised once, and the longer spellings are skipped after that.
$SENT = []; $st = [];
$ex = search_plan(search_parse('invoices quotes'), true)['attempts'][1]['exec'];
search_exec_run($ex, function ($c) use ($fake) { $r = $fake(false)($c); return is_array($r) ? $r : []; }, null, $st);
t_ok('a server that matches substrings is not asked twice per word', ($st['substring'] ?? null) === true && count($SENT) === 3);

