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
