# Tests

Dependency-free. No Composer, no framework, nothing to install.

```sh
php tests/run.php              # everything
php tests/run.php sanitizer    # only files matching "sanitizer"
VERBOSE=1 php tests/run.php    # also list passing assertions
```

Exits non-zero if anything fails, so it can gate a deploy. The updater excludes
`tests/`, so this never ships to an install.

## What is covered

| File | Covers |
| --- | --- |
| `test_sanitizer.php` | Inbound-mail and signature sanitizers: event-handler removal, script/URL-scheme stripping, and that ordinary mail is **not** mangled. Includes a performance guard on the hot path. |
| `test_css_defuser.php` | Inline-CSS defusing: a message must not escape its container and paint over the UI; ordinary styling must survive. |
| `test_util.php` | `ini_bytes`, atomic JSON writes, special-folder resolution, TLS policy, expunge scoping, the poll cache, and attribute-level handler stripping. |
| `test_updater.php` | Self-update is transactional: a mid-copy failure rolls back byte-for-byte, `data/` is never touched, and SMTP certificate errors are classified correctly. |

## Writing a test

Add `tests/test_<name>.php`. It is plain PHP — the runner requires it and counts
the assertions:

```php
require_once T_ROOT . '/lib/util.php';

t_group('what this section checks');
t_eq('description', actual(), 'expected');
t_ok('description', $condition, 'detail shown only on failure');
t_contains('description', $haystack, 'needle');
```

Helpers: `t_extract_fn($file, $fn, $alias)` lifts a single function out of a file
that cannot be included (endpoint files execute on include). `t_tmpdir()` gives a
scratch directory. `t_data_path()` returns a path under `data/` **only if it does
not already exist**, so a test can never destroy real user state.

## The one rule that matters

**A test must be shown to fail against the broken code before you trust it
passing.** Earlier work on this app produced a harness that silently tested the
wrong thing and still reported success. These tests were checked against the
actual pre-fix commits:

| Code under test | Result |
| --- | --- |
| Before the sanitizer fix (`c6f2754^`) | 22 failed |
| The broken intermediate (`c6f2754`) | 11 failed — and 5.0 s vs 0.14 s, catching the quadratic blowup |
| Before the CSS-defuser fix (`cf9d9b9^`) | 24 failed |
| Current | 186 passed, 0 failed |
