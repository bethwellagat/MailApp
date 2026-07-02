# WebMail — Deployable webmail client

Self-hosted Outlook-style webmail. Pure PHP + vanilla JS. No database, no Composer, no build step. Designed to drop onto any client subdomain on shared hosting (cPanel / DirectAdmin / Plesk).

## Features

**Mail**
- IMAP read + SMTP send over the user's existing mail account (session-based auth)
- Three-pane layout (folders / list / reading) with conversation threading
- Multi-select with bulk actions (Delete, Archive, Move, Flag, Mark Read)
- Right-click context menu on messages (Reply / Reply All / Forward / Mark / Flag / Snooze / Filter like this / Archive / Delete)
- Drag-and-drop messages onto folders to move them
- Keyboard shortcuts (`?` for the cheat sheet)
- Filter chips: All / Unread / Flagged / Attachments
- Tree-style subfolders with create / delete (standard folders protected)
- Folder unread counts (Drafts shows total)
- Auto-refresh on new mail (no banner — appears with a brief blue pulse)
- HTML body sanitization on render

**Compose**
- Bold / italic / underline / lists, image drag-drop, attachments
- **Undo Send** — 10-second window with toast + Undo button
- **Schedule Send** — pick a future date/time
- Per-user signature with toggles for new vs replies

**Productivity**
- **Snooze** messages (Later today / Tomorrow / Weekend / Next week / Custom) — auto-wakes when due
- **Filters / rules** (Gmail-style) — From / To / Subject / Has-words / Doesn't-have / Has-attachment, with actions Skip Inbox / Mark Read / Star / Move to / Delete; runs on poll cycle, plus "apply to existing" on save
- **Vacation responder / out-of-office** — auto-replies during a date range, skips mailing lists, no-reply senders, and known auto-responders. Per-sender cooldown to avoid loops.
- **Sidebar calendar** — local events plus ICS feed import (Google Calendar / Outlook / iCloud secret URLs, read-only)
- **Density toggle** — Compact (default) / Cozy / Comfortable
- **Office docs preview** — Excel (.xls/.xlsx/.csv) renders as tables, Word (.docx) renders as a styled page, PDFs inline

**Multi-tenant / branding**
- Auto-derives brand name from the host (`mail.acmecorp.com` → "Acmecorp")
- Optional per-deployment override in `data/brand.json`
- Browser tab title, login card, and footer all use the resolved brand name

**Mobile**
- Sidebar becomes a slide-in drawer below 820 px
- Single-column list/reading swap below 720 px with a "Back" button
- Full-screen compose on phones

**URLs**
- `.htaccess` rewrites `/inbox`, `/settings`, `/logout` to the corresponding `.php` files (extension-less URLs). Old `.php` URLs 301-redirect to the clean form.

## Requirements

- PHP **8.0+**
- Extensions: `imap`, `mbstring`, `openssl`, `curl` (all default on cPanel/DirectAdmin)
- Apache with `mod_rewrite` enabled (default on shared hosting)
- The user's mail server must speak IMAP/IMAPS and SMTP (any common provider works)

## File layout

```
webmail/
├── .htaccess              URL rewrites, hide .php extension
├── README.md              this file
├── index.php              login page
├── inbox.php              main app shell
├── settings.php           settings page
├── logout.php             session destroy
├── lib/                   server-side libraries
│   ├── brand.php          domain → brand name resolver
│   ├── prefs.php          per-user preferences
│   ├── calendar.php       calendar storage
│   ├── ics_parser.php     iCalendar parser (RFC 5545 subset)
│   ├── rules.php          filter / rule storage + matching
│   ├── snooze.php         snooze tracking
│   ├── out_of_office.php  vacation responder storage
│   ├── outbox.php         scheduled / undo-send queue
│   └── mailer.php         shared SMTP helpers
├── ajax/                  JSON endpoints
│   ├── fetch.php          IMAP read + folder ops
│   ├── send.php           SMTP send (also queues to outbox)
│   ├── prefs.php          GET/POST per-user prefs
│   ├── calendar.php       events + feeds
│   ├── snooze.php         add/cancel/wake
│   ├── rules.php          filter CRUD + run
│   ├── out_of_office.php  config + process
│   └── outbox.php         queue management + send-now/cancel
├── assets/
│   ├── style.css          all styles
│   ├── app.js             all frontend logic
│   └── favicon.svg
└── data/                  per-user state — created at runtime
    ├── .htaccess          web access denied
    └── brand.json.example brand override template
```

## Deploying to a new client domain

1. **Create the subdomain** in the host's control panel (e.g. `mail.clientdomain.com`).
2. **Upload all files** to the subdomain's document root, preserving directory structure. SFTP, FTP, or cPanel File Manager all work.
3. **Verify the IMAP extension** is enabled — create a temporary `phpinfo.php` containing `<?php phpinfo();`, search for "imap" in the output, then **delete the file**.
4. **Permissions on `data/`** — should be `0700` and writable by the PHP user. Most cPanel installs set this correctly when the directory is created.
5. **(Optional) Override the brand name** — copy `data/brand.json.example` to `data/brand.json` and edit:
   ```json
   {
     "name": "Acme Corporation",
     "tagline": "Secure WorkSpace"
   }
   ```
   If you skip this, the brand name auto-derives from the subdomain SLD (e.g. `mail.acmecorp.com` → "Acmecorp").
6. **Visit the subdomain** — you should see the login page.
7. **Sign in** with any valid email account on that domain. The app auto-detects:
   - **IMAP host:** `mail.<domain>`, port 993, SSL on
   - **SMTP host:** same as IMAP, tries port 465 (SSL), falls back to 587 (STARTTLS), then PHP `mail()`
   - Click **Advanced settings** on the login form to override.

No database setup. No `.env` file. No Composer. No build step.

## In-app configuration

Most settings are in **Settings**:
- Account (display density, identity)
- Signature (with toggles for new vs replies)
- Workspace logo
- Out of office (vacation responder)
- Filters (rules)
- Calendars (ICS feed URLs)
- Mail server (read-only display of the active session)

Per-deployment overrides:
- `data/brand.json` — optional brand name + tagline override

## Software updates (Settings → Software update)

The app can update its own **code** from a git repository — no `git` CLI or shell
access required (it downloads the GitHub zipball with `curl` and installs it with
`ZipArchive`). The update copies program files over the app and **never touches
`data/`**, so every user's signature, workspace logo, brand override, filters,
contacts, snoozes and outbox survive untouched.

Enable it by creating **`data/update.json`** (copy `data/update.json.example`):

```json
{
  "repo": "your-github-user/your-repo",
  "branch": "main",
  "token": "github_pat_… (only needed for a PRIVATE repo)",
  "admin_email": "you@yourdomain.com (optional — restricts who can update)"
}
```

- Use a **fine-grained, read-only, single-repo** token for private repos. It lives
  only under `data/` (git-ignored, web-denied) — never in the code or the repo.
- Set `admin_email` to limit the **Check / Update now** buttons to one account;
  leave it blank to let any signed-in user update.
- The feature stays hidden/disabled until `data/update.json` names a valid repo.
- The deployed commit is recorded in `data/version.json`; "Check for updates"
  compares it to the branch tip. Updates apply per-file atomically and reload the app.

Alternatively, if the deploy directory is itself a git clone and the host allows it,
a plain `git pull` works too — `data/` is git-ignored, so it's left alone.

## Data directory growth

Per-user JSON state lives under `data/<feature>/<sha256(email)>.json`, mode 0600, web-denied via `.htaccess`. Realistic per-user sizes:

| Feature | Typical size | Notes |
|---|---|---|
| Prefs | < 1.5 MB | Hard limits: signature 1 MB, logo 512 KB |
| Calendar (incl. ICS feed cache) | 100 KB – 5 MB | Grows with subscribed feeds |
| Snoozes | < 100 KB | Bounded by usage |
| Rules | < 50 KB | Bounded |
| Out-of-office | grows with unique senders during OOO | Replied-log not currently auto-pruned |
| Outbox | 0 most of the time, up to 25 MB per queued message | Deleted on send success |

For typical use, **5–10 MB per user**. A 100-user deployment ≈ 1 GB. Well within typical cPanel quotas.

Email content itself is **never stored on disk** — it's read live from IMAP every time. Credentials live only in `$_SESSION` and are cleared on logout.

## Background processing

A few features process asynchronously on the polling cycle (every ≈ 60 s while a user is signed in):
- **Snooze wake-up** — moves due messages from "Later" back to Inbox marked unread
- **Outbox flush** — sends scheduled / undo-send messages whose time has come
- **Vacation auto-replies** — replies to new inbox mail while OOO is active
- **Filter rules** — applies enabled rules to mail received since the last run

**Important caveat:** these fire only while *somebody* is signed in to that account and polling. If a scheduled send is due at 9 AM but nobody opens the app until 10 AM, it goes out at 10 AM. For true server-side processing (independent of login), wire a cPanel cron job to hit `ajax/snooze.php?action=wake`, `ajax/outbox.php?action=process`, etc. — or use Sieve scripts for filters.

## Session lifetime

Inherits PHP's default (`session.gc_maxlifetime`, usually 24 minutes idle). To extend, edit the root `.htaccess` and add:

```apache
php_value session.gc_maxlifetime 7200
php_flag display_errors Off
```

## SSL

Before going live, point an SSL certificate at the subdomain via the host's "SSL Certificates" / "AutoSSL" panel. Cookies are session-only and the password is held in `$_SESSION` — running this over plain HTTP exposes it on the wire.

## Troubleshooting

**"PHP IMAP extension is not enabled"** — Ask the host to enable the `imap` PHP extension. On DirectAdmin/cPanel this is usually a one-click toggle in the PHP version selector.

**"Could not reach IMAP server at mail.\<domain\>:993"** — The auto-detected host is wrong. Open Advanced settings, enter the correct hostname (often `mail.<domain>`, sometimes `imap.<domain>` or the server's hostname like `srv12.host.com`).

**"Incorrect email or password"** — Standard auth failure. Double-check credentials in the host's webmail-direct (cPanel hosts usually have one at `webmail.<domain>` on port 2096).

**"Send failed: ... starttls/587: ..."** — SMTP send couldn't reach the server. Usually the host blocks outbound port 25/465/587 from PHP. The code falls back to PHP's `mail()` automatically, but if that's also disabled the error surfaces. Contact the host.

**Sent message doesn't appear in Sent folder** — The folder auto-detect looks for any IMAP folder containing "sent" (case-insensitive). On hosts with non-English folder names, manually create or rename a "Sent" folder via webmail-direct.

**Page redirects back to login on every action** — Session cookie isn't sticking. Check that `session.save_path` (in `phpinfo.php`) points to a writable directory.

**Calendar feed sync says "HTTP 0" or "connect"** — `allow_url_fopen` is off and `curl` isn't installed. The fetcher tries curl first then falls back. Enable one of them.

**Subfolders don't appear indented** — IMAP delimiter is auto-detected from `imap_getmailboxes`. If folders look flat, the server may use a non-standard delimiter — inspect the `delimiter` field in the `/ajax/fetch.php?action=folders` JSON response.

## Security notes

- Credentials live only in `$_SESSION`. Never written to cookies, hidden fields, log lines, or files.
- HTML email bodies sanitized server-side: `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`, `<link>`, `<meta>`, `<form>` stripped; all `on*` handlers and `javascript:` URLs removed; surviving links get `rel="noopener noreferrer"`.
- All user-supplied values are escaped with `htmlspecialchars()` on output.
- Session ID is regenerated on successful login.
- All AJAX endpoints reject unauthenticated requests with HTTP 401.
- `data/` denies all web access via `.htaccess`.
- File uploads (logo, signature image) validated by MIME type + size limit.

## License

Self-hosted deployment. Brand per domain via `data/brand.json` (see "Deploying to a new client domain").
