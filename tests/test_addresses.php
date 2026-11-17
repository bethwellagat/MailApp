<?php
/**
 * Address-list headers (To/Cc/Bcc) and Date restamping for queued sends.
 * Regression: autocomplete sent `To: Lagat, Bethwel <b@x.com>,` — unquoted
 * comma plus a trailing one — which Gmail read as two recipients and which
 * looked like spam.
 */

require_once T_ROOT . '/lib/mailer.php';

t_group('address lists');
$f = addr_list_format('Lagat, Bethwel <bethwel@example.com>, ');
t_eq('unquoted comma name folded back and quoted', $f['header'], '"Lagat, Bethwel" <bethwel@example.com>');
t_eq('one recipient, not two', $f['emails'], ['bethwel@example.com']);
t_eq('nothing reported bad', $f['bad'], []);

$f = addr_list_format('"Lagat, Bethwel" <b@example.com>; jane@example.com,');
t_eq('already-quoted name kept, separators normalised', $f['header'], '"Lagat, Bethwel" <b@example.com>, jane@example.com');

$f = addr_list_format('Jane Doe <jane@example.com>');
t_eq('plain name left unquoted', $f['header'], 'Jane Doe <jane@example.com>');

$f = addr_list_format('Dr. Who <who@example.com>');
t_eq('dot in name gets quoted', $f['header'], '"Dr. Who" <who@example.com>');

$f = addr_list_format('Zoë <zoe@example.com>');
t_eq('non-ASCII name MIME-encoded', $f['header'], '=?UTF-8?B?' . base64_encode('Zoë') . '?= <zoe@example.com>');

$f = addr_list_format('a@example.com, A@Example.com');
t_eq('duplicate addresses collapsed', $f['emails'], ['a@example.com']);

$f = addr_list_format('jane@example, bob@example.com');
t_eq('a bad piece is reported', $f['bad'], ['jane@example']);
t_eq('the good one still parsed', $f['emails'], ['bob@example.com']);

$f = addr_list_format("x@example.com\r\nBcc: evil@example.com");
t_ok('no CR/LF can reach the header', strpos($f['header'] . implode('', $f['bad']), "\n") === false, json_encode($f));

$f = addr_list_format('  ,; ');
t_eq('empty field gives empty header', $f['header'], '');

t_group('date restamp');
$msg = "To: a@example.com\r\nDate: Tue, 17 Nov 2026 11:35:00 +0000\r\nSubject: x\r\n\r\nbody\r\nDate: keep me\r\n";
$out = message_restamp_date($msg);
t_ok('header Date replaced', strpos($out, 'Date: Tue, 17 Nov 2026 11:35:00 +0000') === false);
t_ok('body untouched', substr($out, -21) === "body\r\nDate: keep me\r\n");
t_eq('only one header Date', substr_count(substr($out, 0, strpos($out, "\r\n\r\n")), 'Date: '), 1);

t_group('the sender is a valid address too');
t_eq('a comma in the display name is quoted — one sender, not two', addr_format('Lagat, Bethwel', 'b@example.com'), '"Lagat, Bethwel" <b@example.com>');
t_eq('a dot is quoted', addr_format('Prolance Africa Ltd.', 'md@example.com'), '"Prolance Africa Ltd." <md@example.com>');
t_eq('a plain name is left alone', addr_format('lilian', 'l@example.com'), 'lilian <l@example.com>');
t_eq('a bar is not special', addr_format('MD | Prolance Africa Ltd', 'md@example.com'), 'MD | Prolance Africa Ltd <md@example.com>');
t_eq('an already-quoted name is not quoted twice', addr_format('"Lagat, Bethwel"', 'b@example.com'), '"Lagat, Bethwel" <b@example.com>');
t_eq('a quote inside the name is escaped', addr_format('Bob "the builder" K', 'b@example.com'), '"Bob \\"the builder\\" K" <b@example.com>');
t_eq('no name is just the address', addr_format('', 'b@example.com'), 'b@example.com');
t_ok('a line break in the name cannot start a new header', strpos(addr_format("Eve\r\nBcc: x@example.com", 'e@example.com'), "\n") === false);
foreach (['ajax/send.php', 'ajax/draft.php', 'ajax/calendar.php', 'lib/out_of_office.php'] as $_f) {
    $_src = (string)file_get_contents(T_ROOT . '/' . $_f);
    t_ok($_f . ' builds From with addr_format', strpos($_src, 'addr_format(') !== false && !preg_match('/mime_header_encode\(\$from_?[Nn]ame\)/', $_src));
}

t_group('no header line is too long');
$long = 'To: ' . implode(', ', array_map(fn($i) => "Person Number $i <person$i@example.com>", range(1, 40)));
$fold = header_fold($long);
t_ok('forty recipients fold under 78 a line', max(array_map('strlen', explode("\r\n", $fold))) <= 78);
t_ok('every continuation starts with a space', !array_filter(array_slice(explode("\r\n", $fold), 1), fn($l) => $l === '' || $l[0] !== ' '));
t_eq('unfolding gives back exactly what went in', preg_replace('/\r\n(?= )/', '', $fold), $long);
t_eq('a short header is untouched', header_fold('To: a@example.com'), 'To: a@example.com');
t_eq('a value with no space cannot be folded and is not broken', header_fold('X: ' . str_repeat('a', 200)), 'X: ' . str_repeat('a', 200));
$subj = str_repeat('عرض سعر منقح ', 10);
$enc  = mime_header_encode($subj);
t_ok('a long non-English subject is several encoded words, none over 75', max(array_map('strlen', explode("\r\n ", $enc))) <= 75 && substr_count($enc, '=?UTF-8?B?') > 1);
$dec = '';
foreach (explode("\r\n ", $enc) as $w) $dec .= base64_decode(substr($w, 10, -2));
t_eq('…cut on whole characters, so it decodes to the same text', $dec, $subj);
t_ok('…and is not folded a second time', header_fold('Subject: ' . $enc) === 'Subject: ' . $enc);
t_eq('a short one is a single word', mime_header_encode('Zoë'), '=?UTF-8?B?' . base64_encode('Zoë') . '?=');
t_ok('a filename stays one word — a fold cannot go inside quotes', strpos(mime_header_encode($subj . '.pdf', false), "\r\n") === false);
t_eq('ASCII is never encoded', mime_header_encode('Quarterly report'), 'Quarterly report');

t_group('bodies and attachments');
t_eq('every line ending becomes CRLF', mail_crlf("a\nb\r\nc\rd"), "a\r\nb\r\nc\r\nd");
t_ok('a plain-text part has real line breaks, not =0A', strpos(quoted_printable_encode(mail_crlf("line one\nline two")), '=0A') === false);
t_eq('a quote or slash cannot break out of the filename', attachment_safe_name('re"port\\.pdf'), 're_port_.pdf');
t_eq('a path is reduced to the name', attachment_safe_name('/etc/passwd'), 'passwd');
t_eq('an empty name gets one', attachment_safe_name(''), 'attachment');
t_eq('a normal type is kept', attachment_safe_type('Application/PDF'), 'application/pdf');
t_eq('anything not shaped like a type is not trusted', attachment_safe_type("text/plain\r\nX-Evil: 1"), 'application/octet-stream');
t_eq('…nor parameters smuggled in with it', attachment_safe_type('text/plain; name="x"'), 'application/octet-stream');
$_send = (string)file_get_contents(T_ROOT . '/ajax/send.php');
t_ok('the send path uses all of it', strpos($_send, "header_fold('To: ' . \$to)") !== false && strpos($_send, 'attachment_safe_type(') !== false
     && strpos($_send, 'attachment_safe_name(') !== false && strpos($_send, 'quoted_printable_encode(mail_crlf($plain))') !== false);
t_ok('the mail() fallback sets the envelope sender, then tries without', strpos($_send, "'-f' . \$from_addr") !== false && strpos($_send, 'if (!$sent) $sent = @mail($mailTo, $mailSubj, $body_mime, $mailHdr);') !== false);

