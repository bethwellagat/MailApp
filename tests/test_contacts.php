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
