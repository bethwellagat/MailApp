<?php
/**
 * Lists: sort order, "all in folder", folder rename — the pure parts.
 */
require_once T_ROOT . '/lib/util.php';

t_group('list order');
t_eq('the default is newest first, with conversations', list_sort_params('date', 'desc'), ['sort' => 'date', 'dir' => 'desc', 'flag' => 0, 'reverse' => 1, 'default' => true]);
t_eq('oldest first is not the default view', list_sort_params('date', 'asc')['default'], false);
t_eq('sender A–Z', [list_sort_params('from', 'asc')['flag'], list_sort_params('from', 'asc')['reverse']], [2, 0]);
t_eq('subject and size map to the IMAP SORT keys', [list_sort_params('subject', 'desc')['flag'], list_sort_params('size', 'desc')['flag']], [3, 6]);
t_eq('an unknown field falls back to date', list_sort_params('colour', 'asc')['sort'], 'date');
t_eq('an unknown direction falls back to desc', list_sort_params('from', 'sideways')['dir'], 'desc');

t_group('all in folder');
[$take, $rest] = bulk_uid_window([5, 1, 3, 3, '9', 2], 4);
t_eq('newest first, deduplicated, capped', $take, [9, 5, 3, 2]);
t_eq('…and the remainder counted (the duplicate was never a message)', $rest, 1);
[$take, $rest] = bulk_uid_window([1, 2], 5000);
t_eq('a small folder is taken whole', [$take, $rest], [[2, 1], 0]);
[$take, $rest] = bulk_uid_window([], 5000);
t_eq('an empty folder is nothing', [$take, $rest], [[], 0]);

t_group('folder rename');
t_eq('the leaf changes, the parent stays',   folder_renamed_name('INBOX.Clients.Acme', 'Acme Ltd', '.'), 'INBOX.Clients.Acme Ltd');
t_eq('a top-level folder',                   folder_renamed_name('Projects', 'Work', '.'), 'Work');
t_eq('a slash-delimited server',             folder_renamed_name('INBOX/Old', 'Archive 2025', '/'), 'INBOX/Archive 2025');
t_eq('a delimiter typed into the new name cannot create depth', folder_renamed_name('INBOX.A', 'B.C', '.'), 'INBOX.B C');
t_eq('whitespace is trimmed',                folder_renamed_name('INBOX.A', '  B  ', '.'), 'INBOX.B');
