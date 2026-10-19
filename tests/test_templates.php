<?php
/**
 * Templates and canned replies — the store and the rendering, pure.
 */
$GLOBALS['MAILAPP_DATA_DIR'] = t_tmpdir() . '/data';
require_once T_ROOT . '/lib/templates.php';

t_group('templates: a store per account');
$me = 'recruiter@x.com';
t_eq('nothing yet', templates_list($me), []);
$a = template_save($me, ['name' => '  Interview   invite ', 'subject' => "Interview: {subject}\r\n", 'body' => '<p>Hi {first_name|there},</p><script>x()</script><p>Thanks.</p>']);
t_ok('saved with an id', !empty($a['id']) && empty($a['error']));
t_eq('the name is tidied', $a['name'], 'Interview invite');
t_eq('the subject loses its line breaks', $a['subject'], 'Interview: {subject}');
t_ok('the body is sanitised like a signature', strpos($a['body'], '<script') === false && strpos($a['body'], '{first_name|there}') !== false);
$b = template_save($me, ['name' => 'Decline', 'body' => '<p>Sorry.</p>']);
t_eq('two templates, by name', array_column(templates_list($me), 'name'), ['Decline', 'Interview invite']);
$a2 = template_save($me, ['id' => $a['id'], 'name' => 'Interview invite', 'subject' => 'Changed', 'body' => '<p>Changed.</p>']);
t_eq('saving with the same id updates in place', count(templates_list($me)), 2);
t_eq('…and the change took', template_get($me, $a['id'])['subject'], 'Changed');
t_ok('a nameless template is refused', !empty(template_save($me, ['name' => '   ', 'body' => 'x'])['error']));
t_ok('a huge body is refused', !empty(template_save($me, ['name' => 'Big', 'body' => str_repeat('a', TEMPLATE_BODY_MAX + 1)])['error']));
t_ok('a bad id is replaced, not trusted', template_save($me, ['id' => '../../x', 'name' => 'Odd', 'body' => ''])['id'] !== '../../x');
t_ok('delete', template_delete($me, $b['id']) && template_get($me, $b['id']) === null);
t_ok('deleting twice says so', template_delete($me, $b['id']) === false);
t_eq('another account sees nothing', templates_list('other@x.com'), []);
$fill = 'cap@x.com';
for ($i = 0; $i < TEMPLATES_MAX; $i++) template_save($fill, ['name' => "T$i", 'body' => '']);
t_ok('the cap holds', !empty(template_save($fill, ['name' => 'one more', 'body' => ''])['error']));

t_group('templates: the recipient\'s details');
$v = template_vars_for('Thuraya Al-Fahim <thuraya@example.com>, other@example.com', 'Re: Dubai role', 'Beth Agat', 'beth@taurusfzc.com', 1792400000);
t_eq('name and first name from the first recipient', [$v['name'], $v['first_name'], $v['email']], ['Thuraya Al-Fahim', 'Thuraya', 'thuraya@example.com']);
t_eq('subject and sender', [$v['subject'], $v['my_name'], $v['my_email']], ['Re: Dubai role', 'Beth Agat', 'beth@taurusfzc.com']);
t_eq('a readable date', $v['date'], date('j F Y', 1792400000));
$v = template_vars_for('"Okafor, Chidi" <c@x.com>', '');
t_eq('a quoted display name', [$v['name'], $v['first_name']], ['Okafor, Chidi', 'Okafor,']);
$v = template_vars_for('bare@x.com', '');
t_eq('a bare address has no name', [$v['name'], $v['first_name'], $v['email']], ['', '', 'bare@x.com']);
$v = template_vars_for('', '');
t_eq('no recipient yet', [$v['name'], $v['email']], ['', '']);

t_group('templates: rendering');
$vars = ['first_name' => 'Thuraya', 'name' => 'Thuraya Al-Fahim', 'email' => 't@x.com', 'subject' => 'Re: Dubai', 'date' => '19 October 2026', 'my_name' => 'Beth', 'my_email' => 'b@x.com'];
t_eq('every variable', template_render('<p>Hi {first_name} ({name}, {email}) re {subject} on {date} — {my_name} / {my_email}</p>', $vars),
     '<p>Hi Thuraya (Thuraya Al-Fahim, t@x.com) re Re: Dubai on 19 October 2026 — Beth / b@x.com</p>');
t_eq('a fallback is used only when the value is empty', template_render('Hi {first_name|there}', ['first_name' => '']), 'Hi there');
t_eq('…and ignored otherwise', template_render('Hi {first_name|there}', ['first_name' => 'Ada']), 'Hi Ada');
t_eq('case does not matter', template_render('{First_Name}', ['first_name' => 'Ada']), 'Ada');
t_eq('an unknown placeholder is left alone', template_render('Ticket {ticket} for {name}', ['name' => 'Ada']), 'Ticket {ticket} for Ada');
t_eq('a value is escaped into HTML', template_render('<p>{name}</p>', ['name' => 'Bobby <b>"T"</b> & co']), '<p>Bobby &lt;b&gt;&quot;T&quot;&lt;/b&gt; &amp; co</p>');
t_eq('the subject is plain text, not escaped', template_render_text('Re: {subject} & {name}', ['subject' => 'A & B', 'name' => '<x>']), 'Re: A & B & <x>');
t_eq('a fallback with spaces', template_render('{first_name|dear candidate}', ['first_name' => '']), 'dear candidate');
t_eq('braces that are not placeholders survive', template_render('{ "json": 1 } {123}', []), '{ "json": 1 } {123}');
