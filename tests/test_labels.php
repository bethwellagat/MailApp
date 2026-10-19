<?php
/**
 * Labels on IMAP keywords — the registry, the keyword, the search operator.
 */
$GLOBALS['MAILAPP_DATA_DIR'] = t_tmpdir() . '/data';
require_once T_ROOT . '/lib/labels.php';

t_group('labels: the keyword');
t_eq('a slug is lowercase, ascii, underscored', search_label_slug('  Dubai Office / 2026 '), 'dubai_office_2026');
t_eq('accents fold the same way on every host', [search_label_slug('Café'), search_label_slug('Zürich Büro'), search_label_slug('Straße')], ['cafe', 'zurich_buro', 'strasse']);
t_eq('the keyword has a prefix other clients never use', search_label_keyword('Forwarded'), '$lbl_forwarded');
t_eq('…so a label named like a system keyword cannot collide', search_label_keyword('Junk') !== '$Junk', true);
t_ok('a slug is at most 32 characters', strlen(search_label_slug(str_repeat('abcdefghij', 5))) === 32);
t_eq('a unique slug gets a suffix', label_unique_slug('Dubai', ['dubai', 'dubai_2']), 'dubai_3');
t_eq('an unnameable label is still a label', label_unique_slug('???', []), 'label');

t_group('labels: the registry');
$me = 'r@x.com';
t_eq('nothing yet', labels_list($me), []);
$a = label_save($me, ['name' => ' Interviewed ', 'color' => 3]);
t_ok('saved with id, slug and keyword', !empty($a['id']) && $a['slug'] === 'interviewed' && $a['keyword'] === '$lbl_interviewed' && $a['color'] === 3);
$b = label_save($me, ['name' => 'Dubai', 'color' => 99]);
t_eq('the colour is clamped to the palette', $b['color'], LABEL_COLORS - 1);
t_ok('the same name twice is refused', !empty(label_save($me, ['name' => 'dubai'])['error']));
$a2 = label_save($me, ['id' => $a['id'], 'name' => 'Interview done', 'color' => 5]);
t_eq('renaming keeps the slug — the keyword on messages does not change', [$a2['slug'], $a2['name'], $a2['color']], ['interviewed', 'Interview done', 5]);
t_ok('renaming onto another label\'s name is refused', !empty(label_save($me, ['id' => $a['id'], 'name' => 'Dubai'])['error']));
t_eq('listed by name', array_column(labels_list($me), 'name'), ['Dubai', 'Interview done']);
$map = labels_keyword_map($me);
t_eq('the keyword map answers by name and by slug', [$map['dubai'], $map['interview done'], $map['interviewed']], ['$lbl_dubai', '$lbl_interviewed', '$lbl_interviewed']);
t_ok('a nameless label is refused', !empty(label_save($me, ['name' => '  '])['error']));
t_ok('delete', label_delete($me, $b['id']) && label_get($me, $b['id']) === null);
t_ok('deleting twice says so', label_delete($me, $b['id']) === false);
t_eq('another account sees nothing', labels_list('o@x.com'), []);
$fill = 'cap@x.com';
for ($i = 0; $i < LABELS_MAX; $i++) label_save($fill, ['name' => "L$i"]);
t_ok('the cap holds', !empty(label_save($fill, ['name' => 'one more'])['error']));

t_group('labels: reading them back');
$flags = labels_intersect(['lblA' => [5, 9, 12], 'lblB' => [9, 40]], [5, 9, 12, 13]);
t_eq('each asked-about UID gets its labels; others are ignored', $flags, ['5' => ['lblA'], '9' => ['lblA', 'lblB'], '12' => ['lblA']]);
t_eq('nothing labelled, nothing said', labels_intersect(['lblA' => []], [1, 2]), []);

t_group('labels: the search operator');
$p = search_parse('label:Dubai -label:"Interview done" from:x');
t_eq('label: and -label: are parsed', [$p['label'], $p['not_label']], [['Dubai'], ['Interview done']]);
t_eq('…and translated to KEYWORD / UNKEYWORD on the slug', search_to_imap($p), 'FROM "x" KEYWORD $lbl_dubai UNKEYWORD $lbl_interview_done');
t_eq('an empty label name is ignored', search_to_imap(search_parse('label:"???"')), 'ALL');

t_group('labels: a rule can add one');
require_once T_ROOT . '/lib/rules.php';
$r = sanitize_rule(['match' => ['from' => 'x'], 'actions' => ['label' => 'abcdefgh-1234']]);
t_ok('"add label" alone is an action', $r !== null && $r['actions']['label'] === 'abcdefgh-1234');
t_eq('a malformed label id is dropped', sanitize_rule(['match' => ['from' => 'x'], 'actions' => ['label' => '../x', 'star' => true]])['actions']['label'], '');
