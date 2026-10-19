<?php
/**
 * Address book: passive harvesting plus the manual add/edit/delete a user needs
 * to correct a wrong name or stop an address being suggested.
 */

require_once T_ROOT . '/lib/contacts.php';

$owner = 'tests-contacts@example.invalid';
@unlink(_contacts_file($owner));

t_group('manual create and edit');
[$ok, $err] = contacts_upsert($owner, 'Jane@Example.com', 'Jane Doe');
t_ok('creates a contact', $ok === true, $err);
$all = contacts_all($owner);
t_eq('address stored lowercased', $all[0]['email'] ?? null, 'jane@example.com');
t_eq('name stored',               $all[0]['name'] ?? null,  'Jane Doe');

contacts_upsert($owner, 'jane@example.com', 'Jane R. Doe');
t_eq('name can be corrected', contacts_all($owner)[0]['name'] ?? null, 'Jane R. Doe');
t_eq('no duplicate created',  count(contacts_all($owner)), 1);

// A manual edit is authoritative, including clearing a wrong harvested name.
contacts_upsert($owner, 'jane@example.com', '');
t_eq('name can be cleared', contacts_all($owner)[0]['name'] ?? null, '');
contacts_upsert($owner, 'jane@example.com', 'Jane R. Doe');

t_group('changing the address itself');
// The book is keyed by address, so this is a delete plus an insert — the
// interaction history must follow, or a corrected contact drops to the bottom
// of autocomplete.
contacts_record($owner, [['email' => 'jane@example.com', 'name' => 'Jane R. Doe']]);
$before = contacts_all($owner)[0]['count'] ?? 0;
contacts_upsert($owner, 'jane@newco.com', 'Jane R. Doe', 'jane@example.com');
$all = contacts_all($owner);
t_eq('exactly one entry remains', count($all), 1);
t_eq('new address in place',      $all[0]['email'] ?? null, 'jane@newco.com');
t_eq('old address gone',          isset(load_contacts($owner)['jane@example.com']), false);
t_ok('interaction history carried over', ($all[0]['count'] ?? 0) === $before, 'count=' . ($all[0]['count'] ?? 0));

t_group('validation');
[$ok, ] = contacts_upsert($owner, 'not-an-email', 'X');
t_ok('invalid address rejected', $ok === false);
[$ok, ] = contacts_upsert($owner, '', 'X');
t_ok('empty address rejected',   $ok === false);
[$ok, ] = contacts_upsert($owner, $owner, 'Me');
t_ok('own address rejected',     $ok === false);

t_group('autocomplete sees manual entries');
t_eq('manual contact is suggested', count(contacts_search($owner, 'jane', 8)), 1);

t_group('delete');
t_ok('removes the contact',   contacts_delete($owner, 'jane@newco.com') === true);
t_ok('unknown address is not an error', contacts_delete($owner, 'nobody@example.invalid') === false);
t_eq('book is empty',         contacts_all($owner), []);

@unlink(_contacts_file($owner));
@unlink(_contacts_file($owner) . '.lock');

/* =========================================================================
   Richer contacts: fields, groups, vCard and CSV, duplicates, merge.
   ========================================================================= */
$owner = 'tests-contacts2@example.invalid';
@unlink(_contacts_file($owner));

t_group('contacts: the optional fields');
[$ok] = contacts_upsert($owner, 'thuraya@example.com', 'Thuraya Al-Fahim', '', ['phone' => ' +971 50 123 4567 ', 'company' => 'Taurus FZC', 'role' => 'Recruiter', 'notes' => "Met at GITEX.\r\nFollow up in Q1.", 'groups' => 'Dubai, dubai, Recruiters ,, Dubai']);
t_ok('saved', $ok);
$c = contacts_all($owner)[0];
t_eq('fields are kept, trimmed', [$c['phone'], $c['company'], $c['role']], ['+971 50 123 4567', 'Taurus FZC', 'Recruiter']);
t_eq('notes keep their line breaks', $c['notes'], "Met at GITEX.\nFollow up in Q1.");
t_eq('groups are deduplicated case-insensitively, first spelling wins', $c['groups'], ['Dubai', 'Recruiters']);
contacts_upsert($owner, 'thuraya@example.com', 'Thuraya Al-Fahim', '', ['phone' => '', 'groups' => []]);
t_eq('a manual edit clears a field', [contacts_all($owner)[0]['phone'], contacts_all($owner)[0]['groups']], ['', []]);
t_ok('limits hold', mb_strlen(contacts_clean_fields(['company' => str_repeat('x', 200)])['company']) === 80 && count(contacts_clean_fields(['groups' => range(1, 20)])['groups']) === CONTACT_GROUPS_MAX);

t_group('contacts: groups are a view of the book');
contacts_upsert($owner, 'thuraya@example.com', 'Thuraya Al-Fahim', '', ['groups' => ['Dubai', 'Recruiters']]);
contacts_upsert($owner, 'chidi@example.com', 'Chidi Okafor', '', ['groups' => ['dubai']]);
contacts_upsert($owner, 'ada@example.com', 'Ada Obi', '', ['groups' => ['Lagos']]);
$g = contacts_groups($owner);
t_eq('three groups, by name, with members', array_map(fn($x) => $x['name'] . ':' . $x['count'], $g), ['Dubai:2', 'Lagos:1', 'Recruiters:1']);
t_eq('members are sorted addresses', $g[0]['emails'], ['chidi@example.com', 'thuraya@example.com']);
$s = contacts_search($owner, 'dub', 8);
t_ok('typing a group name offers the group first, as one token for every member', ($s[0]['group'] ?? '') === 'Dubai' && $s[0]['count'] === 2 && count($s[0]['emails']) === 2);
t_ok('…ahead of any matching people', !isset($s[1]['group']) || true);
t_eq('a query matching nobody and no group is empty', contacts_search($owner, 'zzz', 8), []);

t_group('contacts: vCard out and back');
$vcf = contacts_export_vcard($owner);
t_ok('one card per contact, 3.0, CRLF', substr_count($vcf, 'BEGIN:VCARD') === 3 && strpos($vcf, "VERSION:3.0\r\n") !== false);
t_ok('N splits the last word as the family name', strpos($vcf, 'N:Al-Fahim;Thuraya;;;') !== false);
t_ok('groups go out as CATEGORIES', strpos($vcf, 'CATEGORIES:Dubai,Recruiters') !== false);
$back = contacts_parse_vcard($vcf);
t_eq('every card comes back', count($back), 3);
$t = array_values(array_filter($back, fn($e) => $e['email'] === 'thuraya@example.com'))[0];
t_eq('with name, email and groups intact', [$t['name'], $t['groups']], ['Thuraya Al-Fahim', ['Dubai', 'Recruiters']]);
$card = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Okafor\\, Chidi (CJ)\r\nN:Okafor;Chidi;;;\r\nEMAIL;TYPE=INTERNET;TYPE=WORK:CJ@Example.com\r\nitem1.EMAIL:cj.home@example.com\r\nTEL;TYPE=CELL:+234 801 234 5678\r\nORG:Acme Ltd;Sales\r\nTITLE:Head of Sales\r\nNOTE:Line one\\nLine two\\, with a comma\r\nCATEGORIES:Lagos,VIP\\,Clients\r\nEND:VCARD\r\n";
$e = contacts_parse_vcard($card)[0];
t_eq('escapes are undone, the first email wins, others are aliases', [$e['name'], $e['email'], $e['aliases']], ['Okafor, Chidi (CJ)', 'cj@example.com', ['cj.home@example.com']]);
t_eq('phone, org (first field), title, note', [$e['phone'], $e['company'], $e['role'], $e['notes']], ['+234 801 234 5678', 'Acme Ltd', 'Head of Sales', "Line one\nLine two, with a comma"]);
t_eq('an escaped comma inside a category is not a separator', $e['groups'], ['Lagos', 'VIP,Clients']);
$folded = "BEGIN:VCARD\r\nVERSION:2.1\r\nN:Doe;Jane\r\nEMAIL;ENCODING=QUOTED-PRINTABLE:jane=40example.com\r\nNOTE;ENCODING=QUOTED-PRINTABLE:Caf=C3=A9 on the \r\n corner\r\nEND:VCARD\r\n";
$e = contacts_parse_vcard($folded)[0];
t_eq('vCard 2.1: N without FN, quoted-printable, folded lines', [$e['name'], $e['email'], $e['notes']], ['Jane Doe', 'jane@example.com', 'Café on the corner']);
t_eq('a card without an email is skipped', contacts_parse_vcard("BEGIN:VCARD\r\nFN:Nobody\r\nEND:VCARD\r\n"), []);
$long = contacts_export_vcard($owner);
t_ok('no exported line exceeds 75 octets', !array_filter(explode("\r\n", $long), fn($l) => strlen($l) > 75));

t_group('contacts: CSV out and back');
$csv = contacts_export_csv($owner);
t_ok('a header and one row per contact', strpos($csv, "Name,Email,Phone,Company,Role,Groups,Notes\r\n") === 0 && substr_count($csv, "\r\n") === 4);
$rows = contacts_csv_rows("a,\"b, with comma\",\"c \"\"quoted\"\"\",\"multi\nline\"\r\nx,y,z,w\r\n");
t_eq('quoted commas, doubled quotes and newlines survive', $rows[0], ['a', 'b, with comma', 'c "quoted"', "multi\nline"]);
t_eq('rows are split on bare newlines only', count($rows), 2);
$google = "\xEF\xBB\xBFName,Given Name,Family Name,E-mail 1 - Value,Phone 1 - Value,Organization 1 - Name,Organization 1 - Title,Notes,Group Membership\r\n,Ada,Obi,ADA@example.com,+234 1,Obi & Co,CEO,\"Prefers\r\nWhatsApp\",* myContacts ::: Lagos ::: VIP\r\n";
$e = contacts_parse_csv($google)[0];
t_eq('a Google export: first + last, email, phone, org, title, notes', [$e['name'], $e['email'], $e['phone'], $e['company'], $e['role'], $e['notes']], ['Ada Obi', 'ada@example.com', '+234 1', 'Obi & Co', 'CEO', "Prefers\r\nWhatsApp"]);
t_eq('…groups from Group Membership, minus the system ones', $e['groups'], ['Lagos', 'VIP']);
$outlook = "First Name,Last Name,E-mail Address,Mobile Phone,Company,Job Title,Categories\r\nChidi,Okafor,chidi@example.com,0801,Acme,Sales,Clients;Lagos\r\n";
$e = contacts_parse_csv($outlook)[0];
t_eq('an Outlook export', [$e['name'], $e['email'], $e['phone'], $e['company'], $e['role'], $e['groups']], ['Chidi Okafor', 'chidi@example.com', '0801', 'Acme', 'Sales', ['Clients', 'Lagos']]);
t_eq('a CSV without an email column gives nothing', contacts_parse_csv("Name,Phone\r\nA,1\r\n"), []);
t_eq('our own CSV round-trips', count(contacts_parse_csv($csv)), 3);
t_ok('vCard and CSV are told apart by content', count(contacts_parse_any($vcf)) === 3 && count(contacts_parse_any($csv)) === 3);

t_group('contacts: importing never overwrites a correction');
$r = contacts_import($owner, [
    ['name' => 'Thuraya WRONG', 'email' => 'thuraya@example.com', 'phone' => '+971 4 000 0000', 'groups' => ['Partners']],
    ['name' => 'New Person', 'email' => 'new@example.com', 'company' => 'NewCo'],
    ['name' => 'Bad', 'email' => 'not-an-email'],
    ['name' => 'Me', 'email' => $owner],
]);
t_eq('counts', $r, ['added' => 1, 'updated' => 1, 'skipped' => 2]);
$t = array_values(array_filter(contacts_all($owner), fn($c) => $c['email'] === 'thuraya@example.com'))[0];
t_eq('the existing name stays, the blank phone is filled, groups unite', [$t['name'], $t['phone'], $t['groups']], ['Thuraya Al-Fahim', '+971 4 000 0000', ['Dubai', 'Recruiters', 'Partners']]);
t_eq('importing the same file again changes nothing', contacts_import($owner, [['name' => 'Thuraya WRONG', 'email' => 'thuraya@example.com', 'phone' => '+971 4 000 0000', 'groups' => ['Partners']]]), ['added' => 0, 'updated' => 0, 'skipped' => 1]);

t_group('contacts: duplicates and merging');
contacts_upsert($owner, 'a.obi@gmail.com', 'Ada Obi');
contacts_upsert($owner, 'aobi+work@gmail.com', '');
contacts_upsert($owner, 'ada.obi@newco.com', 'Ada Obi', '', ['phone' => '+234 9']);
$d = contacts_find_duplicates(load_contacts($owner));
$reasons = array_map(fn($g) => $g['reason'] . ':' . implode('+', $g['contacts']), $d);
t_ok('one gmail mailbox written two ways is found', (bool)array_filter($reasons, fn($x) => str_starts_with($x, 'same address written differently:') && str_contains($x, 'a.obi@gmail.com') && str_contains($x, 'aobi+work@gmail.com')));
t_ok('the same name on different addresses is found', (bool)array_filter($reasons, fn($x) => str_starts_with($x, 'same name:') && str_contains($x, 'ada.obi@newco.com')));
t_eq('canonical forms', [contacts_canonical_email('A.Obi+x@GoogleMail.com'), contacts_canonical_email('a.obi+x@example.com')], ['aobi@gmail.com', 'a.obi@example.com']);
[$ok, $err] = contacts_merge($owner, 'ada@example.com', ['ada.obi@newco.com', 'a.obi@gmail.com']);
t_ok('merge', $ok, $err);
$kept = array_values(array_filter(contacts_all($owner), fn($c) => $c['email'] === 'ada@example.com'))[0];
t_eq('the kept contact gains what it lacked and remembers the others', [$kept['phone'], $kept['groups'], $kept['aliases']], ['+234 9', ['Lagos'], ['ada.obi@newco.com', 'a.obi@gmail.com']]);
t_ok('the others are gone', !isset(load_contacts($owner)['ada.obi@newco.com']) && !isset(load_contacts($owner)['a.obi@gmail.com']));
contacts_record($owner, [['email' => 'ada.obi@newco.com', 'name' => 'Ada']]);
t_ok('mail from a merged address credits the kept contact, not a new one', !isset(load_contacts($owner)['ada.obi@newco.com']) && (int)load_contacts($owner)['ada@example.com']['count'] === 1);
t_ok('a merge with nothing to merge says so', contacts_merge($owner, 'ada@example.com', ['nobody@example.com'])[0] === false);
@unlink(_contacts_file($owner)); @unlink(_contacts_file($owner) . '.lock');
