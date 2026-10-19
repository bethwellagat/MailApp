<?php
require_once __DIR__ . '/lib/session.php'; session_boot();
require_once __DIR__ . '/lib/accounts.php';
accounts_boot();
csp_header();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['email']) || empty($_SESSION['imap_host'])) {
    header('Location: index');
    exit;
}

require_once __DIR__ . '/lib/prefs.php';
require_once __DIR__ . '/lib/brand.php';
require_once __DIR__ . '/lib/calendar.php';
require_once __DIR__ . '/lib/rules.php';
require_once __DIR__ . '/lib/out_of_office.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/updater.php'; // update_installed_label() for the version row

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// Safe JSON for embedding in a <script> block — escapes <, >, &, ' and ".
function js($v) { return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

$display_name = $_SESSION['display_name'] ?? $_SESSION['email'];
$email        = $_SESSION['email'];
$initial      = strtoupper(mb_substr($display_name, 0, 1, 'UTF-8'));

$brand        = resolve_brand();
$brandDomain  = $brand['domain'];
$brandLabel   = $brand['name'] . ' WorkSpace';

$prefs    = load_prefs($email);
$calendar = load_calendar($email);
$rulesData = load_rules($email);
$ooo      = load_ooo($email);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script nonce="<?= csp_nonce() ?>">
/* Resolve the saved theme before first paint so there is no flash of light. */
(function(){try{
  var t = <?= js($prefs['theme']) ?>;
  var dark = (t === 'dark') || (t === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.classList.add(dark ? 'theme-dark' : 'theme-light');
}catch(e){}})();
</script>
<title>Settings · <?= h($brandLabel) ?></title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg?v=<?= @filemtime(__DIR__.'/assets/favicon.svg') ?>">
<link rel="manifest" href="manifest?v=<?= @filemtime(__DIR__.'/manifest.php') ?>">
<meta name="theme-color" content="<?= h($brand['color']) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= h($brand['name']) ?>">
<link rel="apple-touch-icon" href="assets/apple-touch-icon-180.png?v=<?= @filemtime(__DIR__.'/assets/apple-touch-icon-180.png') ?>">
<!-- Inter is self-hosted via @font-face in assets/style.css (no external font CDN). -->
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__.'/assets/style.css') ?>">
<script nonce="<?= csp_nonce() ?>">
/* Register the service worker here too (this page doesn't load app.js).
   Registering an already-active worker is a no-op, so this is safe to repeat. */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.js').catch(function () {});
  });
}
</script>
</head>
<body class="settings-page">
<script nonce="<?= csp_nonce() ?>">window.__CSRF__ = <?= js(csrf_token()) ?>;</script>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs>
    <!-- Referenced by the Add-contact button and the filter dialog's close
         button. Both were referencing symbols this page never defined, so the
         glyphs rendered as nothing — and the close button has no text label,
         which left a blank clickable square. -->
    <symbol id="ic-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
    </symbol>
    <symbol id="ic-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
    </symbol>
    <symbol id="ic-logout" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
    </symbol>
    <symbol id="ic-back" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
    </symbol>
    <symbol id="ic-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
    </symbol>
    <symbol id="ic-pen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/>
    </symbol>
    <symbol id="ic-server" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
    </symbol>
    <symbol id="ic-link" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
    </symbol>
    <symbol id="ic-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="20 6 9 17 4 12"/>
    </symbol>
    <symbol id="ic-image" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
      <circle cx="8.5" cy="8.5" r="1.5"/>
      <polyline points="21 15 16 10 5 21"/>
    </symbol>
    <symbol id="ic-calendar-s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
      <line x1="16" y1="2" x2="16" y2="6"/>
      <line x1="8" y1="2" x2="8" y2="6"/>
      <line x1="3" y1="10" x2="21" y2="10"/>
    </symbol>
    <symbol id="ic-trash-s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
    </symbol>
    <symbol id="ic-refresh-s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
    </symbol>
    <symbol id="ic-tag-s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></symbol>
    <symbol id="ic-funnel-s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
    </symbol>
    <symbol id="ic-edit-s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/>
    </symbol>
    <symbol id="ic-play-s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polygon points="5 3 19 12 5 21 5 3"/>
    </symbol>
    <symbol id="ic-plane-s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9z"/>
    </symbol>
  </defs>
</svg>

<header class="settings-topbar">
    <a class="settings-back" href="inbox">
        <svg class="icon"><use href="#ic-back"/></svg>
        <span>Back to inbox</span>
    </a>
    <div class="settings-title">Settings</div>
    <a class="topbar-user" href="logout" title="Sign out" aria-label="Sign out">
        <span class="topbar-user-avatar"><?= h($initial) ?></span>
        <span class="topbar-user-name"><?= h($display_name) ?></span>
        <svg class="icon topbar-user-logout" width="15" height="15"><use href="#ic-logout"/></svg>
    </a>
</header>

<main class="settings-main">

    <nav class="settings-nav" id="settingsNav">
        <a class="settings-nav-item active" href="#account" data-target="account">
            <span class="snav-icon snav-icon-blue"><svg class="icon"><use href="#ic-user"/></svg></span>
            <span>Account</span>
        </a>
        <a class="settings-nav-item" href="#signature" data-target="signature">
            <span class="snav-icon snav-icon-indigo"><svg class="icon"><use href="#ic-pen"/></svg></span>
            <span>Signature</span>
        </a>
        <a class="settings-nav-item" href="#templates" data-target="templates">
            <span class="snav-icon snav-icon-indigo"><svg class="icon"><use href="#ic-edit-s"/></svg></span>
            <span>Templates</span>
        </a>
        <a class="settings-nav-item" href="#workspace" data-target="workspace">
            <span class="snav-icon snav-icon-indigo"><svg class="icon"><use href="#ic-server"/></svg></span>
            <span>Workspace</span>
        </a>
        <a class="settings-nav-item" href="#logo" data-target="logo">
            <span class="snav-icon snav-icon-pink"><svg class="icon"><use href="#ic-image"/></svg></span>
            <span>Workspace logo</span>
        </a>
        <a class="settings-nav-item" href="#out-of-office" data-target="out-of-office">
            <span class="snav-icon snav-icon-orange"><svg class="icon"><use href="#ic-plane-s"/></svg></span>
            <span>Out of office</span>
        </a>
        <a class="settings-nav-item" href="#tasks" data-target="tasks">
            <span class="snav-icon snav-icon-indigo"><svg class="icon"><use href="#ic-play-s"/></svg></span>
            <span>Background tasks</span>
        </a>
        <a class="settings-nav-item" href="#filters" data-target="filters">
            <span class="snav-icon snav-icon-teal"><svg class="icon"><use href="#ic-funnel-s"/></svg></span>
            <span>Filters</span>
        </a>
        <a class="settings-nav-item" href="#labels" data-target="labels">
            <span class="snav-icon snav-icon-orange"><svg class="icon"><use href="#ic-tag-s"/></svg></span>
            <span>Labels</span>
        </a>
        <a class="settings-nav-item" href="#contacts" data-target="contacts">
            <span class="snav-icon snav-icon-green"><svg class="icon"><use href="#ic-user"/></svg></span>
            <span>Contacts</span>
        </a>
        <a class="settings-nav-item" href="#calendars" data-target="calendars">
            <span class="snav-icon snav-icon-red"><svg class="icon"><use href="#ic-calendar-s"/></svg></span>
            <span>Calendars</span>
        </a>
        <a class="settings-nav-item" href="#mail-server" data-target="mail-server">
            <span class="snav-icon snav-icon-graphite"><svg class="icon"><use href="#ic-server"/></svg></span>
            <span>Mail server</span>
        </a>
        <a class="settings-nav-item" href="#update" data-target="update">
            <span class="snav-icon snav-icon-gray"><svg class="icon"><use href="#ic-refresh-s"/></svg></span>
            <span>Software update</span>
        </a>
        <a class="settings-nav-item" href="#diagnostics" data-target="diagnostics">
            <span class="snav-icon snav-icon-gray"><svg class="icon"><use href="#ic-server"/></svg></span>
            <span>Diagnostics</span>
        </a>
    </nav>

    <div class="settings-content">

        <h1 class="settings-hero">Settings</h1>

        <!-- Revealed by JS only if the private data/ directory turns out to be
             readable over HTTP (i.e. the server ignored the deny files we ship —
             typically nginx or lighttpd, which read neither .htaccess nor web.config). -->
        <div id="dataExposureWarning" class="settings-alert settings-alert-danger" hidden role="alert">
            <strong>Your private data folder is publicly readable.</strong>
            <p>
                Anyone on the internet can currently download <code>data/</code> from this site —
                that includes signatures, workspace logos, saved filters, contacts and the update token.
                The app ships Apache (<code>.htaccess</code>) and IIS (<code>web.config</code>) deny rules,
                but this server is ignoring them.
            </p>
            <p>Add this to your server config and reload it:</p>
            <pre><code># nginx — inside the server { } block
location ^~ /data/ { deny all; return 404; }

# lighttpd
$HTTP["url"] =~ "^/data/" { url.access-deny = ("") }</code></pre>
        </div>

        <section id="account" class="settings-section">
            <header class="settings-section-header">
                <h2>Account</h2>
                <p class="settings-section-desc">Your sign-in identity for <?= h($brandLabel) ?>.</p>
            </header>
            <div class="settings-card">
                <div class="settings-row">
                    <div class="settings-row-label">Email address</div>
                    <div class="settings-row-value mono"><?= h($email) ?></div>
                </div>
                <div class="settings-row settings-row-stack">
                    <div class="settings-row-label">
                        <label for="displayNameInput">Display name</label>
                    </div>
                    <div class="settings-row-value">
                        <div class="settings-inline-edit">
                            <input type="text" id="displayNameInput" class="settings-inline-input" maxlength="100"
                                   value="<?= h($display_name) ?>" autocomplete="off" spellcheck="false">
                            <button type="button" id="displayNameSave" class="settings-inline-save">Save</button>
                            <span id="displayNameStatus" class="settings-status" role="status" aria-live="polite"></span>
                        </div>
                        <p class="settings-inline-hint">The name people see in the “From” line of messages you send.</p>
                    </div>
                </div>
                <div class="settings-row">
                    <div class="settings-row-label">Workspace</div>
                    <div class="settings-row-value"><?= h($brandLabel) ?></div>
                </div>
            </div>

            <header class="settings-section-header" style="margin-top:24px">
                <h2>Display density</h2>
                <p class="settings-section-desc">Controls row spacing in the message list. Compact lets you see more at a glance.</p>
            </header>
            <div class="settings-card">
                <div class="density-options" id="densityOptions">
                    <?php $curDensity = $prefs['density'] ?? 'comfortable'; ?>
                    <?php foreach ([
                        'comfortable' => ['Comfortable', 'Roomy spacing — easiest to scan'],
                        'cozy'        => ['Cozy',        'Tighter rows, slightly smaller avatars'],
                        'compact'     => ['Compact',     'Single-line rows, fits ~30% more on screen'],
                    ] as $val => $info): ?>
                        <label class="density-option <?= $curDensity === $val ? 'active' : '' ?>">
                            <input type="radio" name="density" value="<?= h($val) ?>" <?= $curDensity === $val ? 'checked' : '' ?>>
                            <div class="density-option-text">
                                <span class="density-option-title"><?= h($info[0]) ?></span>
                                <span class="density-option-desc"><?= h($info[1]) ?></span>
                            </div>
                            <span class="density-option-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <span class="settings-status" id="densityStatus"></span>
            </div>

            <header class="settings-section-header" style="margin-top:24px">
                <h2>Theme</h2>
                <p class="settings-section-desc">Use the light or dark appearance, or follow your device's system setting.</p>
            </header>
            <div class="settings-card">
                <div class="density-options" id="themeOptions">
                    <?php $curTheme = $prefs['theme'] ?? 'system'; ?>
                    <?php foreach ([
                        'system' => ['System', 'Match your device\'s light or dark setting'],
                        'light'  => ['Light',  'Always use the light appearance'],
                        'dark'   => ['Dark',   'Always use the dark appearance'],
                    ] as $val => $info): ?>
                        <label class="density-option <?= $curTheme === $val ? 'active' : '' ?>">
                            <input type="radio" name="theme" value="<?= h($val) ?>" <?= $curTheme === $val ? 'checked' : '' ?>>
                            <div class="density-option-text">
                                <span class="density-option-title"><?= h($info[0]) ?></span>
                                <span class="density-option-desc"><?= h($info[1]) ?></span>
                            </div>
                            <span class="density-option-check"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <span class="settings-status" id="themeStatus"></span>
            </div>

            <header class="settings-section-header" style="margin-top:24px">
                <h2>Notifications</h2>
                <p class="settings-section-desc">Show a desktop notification and app-icon badge when new mail arrives while this tab is in the background. The unread count also appears in the browser tab title.</p>
            </header>
            <div class="settings-card">
                <div class="settings-toggles">
                    <label class="settings-toggle">
                        <input type="checkbox" id="notifyToggle" <?= !empty($prefs['notifications']) ? 'checked' : '' ?>>
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                        <span class="toggle-label">Desktop new-mail notifications</span>
                    </label>
                </div>
                <span class="settings-status" id="notifyStatus"></span>
            </div>

            <header class="settings-section-header" style="margin-top:24px">
                <h2>Privacy</h2>
                <p class="settings-section-desc">Images hosted on the sender's server act as read receipts — loading one reveals when you opened the message, your IP address and that your address is live. They stay blocked until you choose to load them, with a one-click button on each message.</p>
            </header>
            <div class="settings-card">
                <div class="settings-toggles">
                    <label class="settings-toggle">
                        <input type="checkbox" id="remoteImagesToggle" <?= !empty($prefs['remote_images']) ? 'checked' : '' ?>>
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                        <span class="toggle-label">Always load remote images</span>
                    </label>
                </div>
                <span class="settings-status" id="remoteImagesStatus"></span>
            </div>
        </section>

        <section id="signature" class="settings-section">
            <header class="settings-section-header">
                <h2>Signature</h2>
                <p class="settings-section-desc">A signature is appended at the bottom of every message you compose. Format with bold, italic, underline, or links.</p>
            </header>
            <div class="settings-card">
                <div class="signature-toolbar">
                    <button type="button" class="sig-tool" data-cmd="bold" title="Bold"><b>B</b></button>
                    <button type="button" class="sig-tool" data-cmd="italic" title="Italic"><i>I</i></button>
                    <button type="button" class="sig-tool" data-cmd="underline" title="Underline"><u>U</u></button>
                    <span class="sig-tool-divider"></span>
                    <button type="button" class="sig-tool" data-cmd="createLink" title="Insert link">
                        <svg class="icon" width="14" height="14"><use href="#ic-link"/></svg>
                    </button>
                    <button type="button" class="sig-tool" data-cmd="unlink" title="Remove link">×<svg class="icon" width="12" height="12" style="margin-left:-4px;"><use href="#ic-link"/></svg></button>
                    <span class="sig-tool-divider"></span>
                    <button type="button" class="sig-tool" data-cmd="image" title="Insert image / logo">
                        <svg class="icon" width="14" height="14"><use href="#ic-image"/></svg>
                    </button>
                </div>
                <div class="signature-editor" id="signatureEditor" contenteditable="true" data-placeholder="Type your signature here, or drop a logo image here…"><?= $prefs['signature'] ?></div>
                <p class="signature-hint">Tip: paste, drop, or use the image button to add a logo. Images are resized to a max of 480×240 and embedded inline so they appear in every recipient's inbox.</p>
            </div>

            <div class="settings-toggles">
                <label class="settings-toggle">
                    <input type="checkbox" id="autoAppend" <?= $prefs['auto_append'] ? 'checked' : '' ?>>
                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    <span class="toggle-label">Append signature to new messages</span>
                </label>
                <label class="settings-toggle">
                    <input type="checkbox" id="appendOnReplies" <?= $prefs['append_on_replies'] ? 'checked' : '' ?>>
                    <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    <span class="toggle-label">Append signature to replies and forwards</span>
                </label>
            </div>

            <div class="settings-actions">
                <button class="btn btn-primary" id="saveSignature">
                    <svg class="icon" width="14" height="14"><use href="#ic-check"/></svg>
                    <span>Save signature</span>
                </button>
                <span class="settings-status" id="signatureStatus"></span>
            </div>
        </section>

        <section id="templates" class="settings-section">
            <header class="settings-section-header">
                <h2>Templates</h2>
                <p class="settings-section-desc">Canned replies, inserted from the <strong>Templates</strong> button in the compose window. Write <code>{first_name}</code>, <code>{name}</code>, <code>{email}</code>, <code>{subject}</code>, <code>{date}</code>, <code>{my_name}</code> or <code>{my_email}</code> and they are filled in from the message you are writing; <code>{first_name|there}</code> falls back to "there" when the recipient's name is not known.</p>
            </header>
            <div class="settings-card">
                <div class="rules-list-header">
                    <span class="settings-inline-hint" id="tplCount">Loading&hellip;</span>
                    <button type="button" class="cal-modal-btn cal-modal-btn-primary" id="tplNewBtn"><svg class="icon" width="14" height="14"><use href="#ic-plus"/></svg><span>New template</span></button>
                </div>
                <div class="tpl-list" id="tplList"></div>
            </div>
            <div class="settings-card" id="tplEditor" hidden>
                <h3 class="settings-card-title" id="tplEditorTitle">New template</h3>
                <div class="settings-row settings-row-stack">
                    <label class="settings-row-label" for="tplName">Name</label>
                    <input type="text" id="tplName" class="settings-inline-input" maxlength="80" placeholder="e.g. Interview invitation" autocomplete="off">
                </div>
                <div class="settings-row settings-row-stack">
                    <label class="settings-row-label" for="tplSubject">Subject <span class="settings-inline-hint">(optional; used when the message has no subject yet)</span></label>
                    <input type="text" id="tplSubject" class="settings-inline-input" maxlength="200" placeholder="e.g. Interview: {subject}" autocomplete="off">
                </div>
                <div class="settings-row settings-row-stack">
                    <span class="settings-row-label">Body</span>
                    <div class="signature-toolbar tpl-toolbar">
                        <button type="button" class="sig-tool" data-cmd="bold" title="Bold"><b>B</b></button>
                        <button type="button" class="sig-tool" data-cmd="italic" title="Italic"><i>I</i></button>
                        <button type="button" class="sig-tool" data-cmd="underline" title="Underline"><u>U</u></button>
                        <button type="button" class="sig-tool" data-cmd="createLink" title="Insert link"><svg class="icon" width="14" height="14"><use href="#ic-link"/></svg></button>
                        <span class="sig-tool-divider"></span>
                        <button type="button" class="sig-tool tpl-var" data-var="{first_name|there}" title="The recipient's first name, or “there”">{first_name}</button>
                        <button type="button" class="sig-tool tpl-var" data-var="{name}" title="The recipient's full name">{name}</button>
                        <button type="button" class="sig-tool tpl-var" data-var="{subject}" title="The message subject">{subject}</button>
                        <button type="button" class="sig-tool tpl-var" data-var="{date}" title="Today's date">{date}</button>
                        <button type="button" class="sig-tool tpl-var" data-var="{my_name}" title="Your name">{my_name}</button>
                    </div>
                    <div class="signature-editor tpl-editor" id="tplBody" contenteditable="true" data-placeholder="Hi {first_name|there}, …"></div>
                </div>
                <div class="settings-inline-edit">
                    <button type="button" class="btn" id="tplDeleteBtn" hidden>Delete</button>
                    <button type="button" class="btn" id="tplCancelBtn">Cancel</button>
                    <button type="button" class="btn btn-primary" id="tplSaveBtn">Save template</button>
                    <span class="settings-status" id="tplStatus"></span>
                </div>
            </div>
        </section>

        <section id="workspace" class="settings-section">
            <header class="settings-section-header">
                <h2>Workspace</h2>
                <p class="settings-section-desc">The name and colour this installation is branded with. Shown on the sign-in page, in the header, in the browser tab and when the app is installed to a home screen &mdash; so this applies to everyone who signs in on this domain, not just you.</p>
            </header>

            <div class="settings-alert settings-alert-info" id="wsAdminNote" hidden>
                <p>Only <strong id="wsAdminEmail"></strong> can change the workspace for this installation.</p>
            </div>

            <div class="settings-card">
                <div class="settings-row settings-row-stack">
                    <div class="settings-row-label"><label for="wsName">Workspace name</label></div>
                    <div class="settings-row-value">
                        <div class="settings-inline-edit">
                            <input type="text" id="wsName" class="settings-inline-input" maxlength="60"
                                   autocomplete="off" spellcheck="false">
                        </div>
                        <p class="settings-inline-hint" id="wsNameHint">Leave empty to use the name derived from the domain.</p>
                    </div>
                </div>
                <div class="settings-row settings-row-stack">
                    <div class="settings-row-label"><label for="wsTagline">Tagline</label></div>
                    <div class="settings-row-value">
                        <div class="settings-inline-edit">
                            <input type="text" id="wsTagline" class="settings-inline-input" maxlength="80"
                                   autocomplete="off" spellcheck="false">
                        </div>
                        <p class="settings-inline-hint">Appears after the workspace name in the footer.</p>
                    </div>
                </div>
                <div class="settings-row settings-row-stack">
                    <div class="settings-row-label"><label for="wsColorText">Brand colour</label></div>
                    <div class="settings-row-value">
                        <div class="settings-inline-edit">
                            <input type="color" id="wsColor" class="ws-color-swatch" aria-label="Pick the brand colour">
                            <input type="text" id="wsColorText" class="settings-inline-input" maxlength="7"
                                   placeholder="#0078d4" autocomplete="off" spellcheck="false">
                        </div>
                        <p class="settings-inline-hint">Tints the browser tab and the app window when installed to a home screen.</p>
                    </div>
                </div>
                <div class="settings-row settings-row-stack">
                    <div class="settings-row-label">Preview</div>
                    <div class="settings-row-value">
                        <div class="ws-preview" id="wsPreview">
                            <span class="ws-preview-dot" id="wsPreviewDot"></span>
                            <span class="ws-preview-name" id="wsPreviewName"></span>
                            <span class="ws-preview-tag" id="wsPreviewTag"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="settings-actions">
                <button type="button" class="btn btn-primary" id="wsSave">Save workspace</button>
                <button type="button" class="btn" id="wsReset">Reset to automatic</button>
                <span id="wsStatus" class="settings-status" role="status" aria-live="polite"></span>
            </div>
        </section>
        <section id="logo" class="settings-section">
            <header class="settings-section-header">
                <h2>Workspace logo</h2>
                <p class="settings-section-desc">Displayed in the top-left of your inbox header. PNG with transparency works best. Image is auto-resized to fit the topbar.</p>
            </header>
            <div class="settings-card logo-card">
                <div class="logo-preview" id="logoPreview">
                    <?php if (!empty($prefs['workspace_logo'])): ?>
                        <img src="<?= h($prefs['workspace_logo']) ?>" alt="Workspace logo" data-ink-detect>
                    <?php else: ?>
                        <span class="logo-empty">No logo set</span>
                    <?php endif; ?>
                </div>
                <div class="logo-actions">
                    <button type="button" class="btn btn-ghost" id="logoPickBtn">
                        <svg class="icon" width="14" height="14"><use href="#ic-image"/></svg>
                        <span>Choose image</span>
                    </button>
                    <button type="button" class="btn btn-ghost danger" id="logoRemoveBtn"<?= empty($prefs['workspace_logo']) ? ' hidden' : '' ?>>
                        Remove
                    </button>
                </div>
            </div>
            <p class="signature-hint">You can also drop or paste an image into the preview area. Max 360×80 displayed; resized automatically.</p>
            <div class="settings-actions">
                <button class="btn btn-primary" id="saveLogo">
                    <svg class="icon" width="14" height="14"><use href="#ic-check"/></svg>
                    <span>Save logo</span>
                </button>
                <span class="settings-status" id="logoStatus"></span>
            </div>
        </section>

        <section id="out-of-office" class="settings-section">
            <header class="settings-section-header">
                <h2>Out of office</h2>
                <p class="settings-section-desc">Auto-reply to incoming messages while you're away. Replies are throttled per sender (one every <em><?= (int)$ooo['config']['cooldown_days'] ?></em> days by default), and automated mail (newsletters, no-reply, mailing lists) is skipped.</p>
            </header>
            <div class="settings-card">
                <label class="toggle-row" style="margin-bottom:14px">
                    <input type="checkbox" id="oooEnabled" <?= !empty($ooo['config']['enabled']) ? 'checked' : '' ?>>
                    <span class="toggle-label">Auto-reply is <strong id="oooState"><?= !empty($ooo['config']['enabled']) ? 'on' : 'off' ?></strong></span>
                </label>

                <div class="cal-field-row">
                    <label class="cal-field">
                        <span class="cal-field-label">Start date</span>
                        <input type="date" id="oooStart" class="cal-field-input" value="<?= h($ooo['config']['start_date']) ?>">
                    </label>
                    <label class="cal-field">
                        <span class="cal-field-label">End date</span>
                        <input type="date" id="oooEnd" class="cal-field-input" value="<?= h($ooo['config']['end_date']) ?>">
                    </label>
                </div>
                <p class="settings-hint">Leave dates empty to run indefinitely while the toggle is on.</p>

                <label class="cal-field" style="margin-top:10px">
                    <span class="cal-field-label">Subject (used as a default; per-message subjects become "Re: original subject")</span>
                    <input type="text" id="oooSubject" class="cal-field-input" value="<?= h($ooo['config']['subject']) ?>" maxlength="200">
                </label>

                <label class="cal-field" style="margin-top:10px">
                    <span class="cal-field-label">Auto-reply message</span>
                    <div class="signature-toolbar">
                        <button type="button" class="signature-btn" data-cmd="bold" title="Bold"><b>B</b></button>
                        <button type="button" class="signature-btn" data-cmd="italic" title="Italic"><i>I</i></button>
                        <button type="button" class="signature-btn" data-cmd="underline" title="Underline"><u>U</u></button>
                    </div>
                    <div id="oooBody" class="signature-editor" contenteditable="true" data-placeholder="Hi, I'm currently out of the office and will respond when I return."><?= $ooo['config']['body'] ?></div>
                </label>

                <label class="cal-field" style="margin-top:10px">
                    <span class="cal-field-label">Cooldown (days between replies to the same sender)</span>
                    <input type="number" id="oooCooldown" class="cal-field-input" min="0" max="365" value="<?= (int)$ooo['config']['cooldown_days'] ?>" style="max-width:120px">
                </label>

                <div class="settings-actions" style="margin-top:14px">
                    <button class="cal-modal-btn cal-modal-btn-primary" id="oooSaveBtn" type="button">Save</button>
                    <span class="settings-status" id="oooStatus"></span>
                </div>
            </div>
        </section>

        <section id="filters" class="settings-section">
            <header class="settings-section-header">
                <h2>Filters</h2>
                <p class="settings-section-desc">Automatic actions on incoming mail. Combine criteria with AND, then choose what happens — move to a folder, mark read, star, archive, or delete. Rules run on the polling cycle (about every minute while you're signed in).</p>
            </header>

            <div class="settings-card">
                <div class="rules-list-header">
                    <button type="button" class="cal-modal-btn cal-modal-btn-primary" id="ruleNewBtn">
                        <svg class="icon" width="13" height="13"><use href="#ic-funnel-s"/></svg>
                        Create new filter
                    </button>
                </div>
                <div class="rules-list" id="rulesList">
                    <?php if (empty($rulesData['rules'])): ?>
                        <div class="cal-feed-empty">No filters yet. Click "Create new filter" to set one up.</div>
                    <?php else: ?>
                        <?php foreach ($rulesData['rules'] as $r):
                            $criteria = [];
                            if (!empty($r['match']['from']))      $criteria[] = 'From: <em>' . h($r['match']['from']) . '</em>';
                            if (!empty($r['match']['to']))        $criteria[] = 'To: <em>' . h($r['match']['to']) . '</em>';
                            if (!empty($r['match']['subject']))   $criteria[] = 'Subject: <em>' . h($r['match']['subject']) . '</em>';
                            if (!empty($r['match']['has_words'])) $criteria[] = 'Has: <em>' . h($r['match']['has_words']) . '</em>';
                            if (!empty($r['match']['not_words'])) $criteria[] = 'Not: <em>' . h($r['match']['not_words']) . '</em>';
                            if (!empty($r['match']['has_attachment'])) $criteria[] = 'Has attachment';
                            $actions = [];
                            if (!empty($r['actions']['skip_inbox'])) $actions[] = 'Skip Inbox';
                            if (!empty($r['actions']['mark_read']))  $actions[] = 'Mark read';
                            if (!empty($r['actions']['star']))       $actions[] = 'Star';
                            if (!empty($r['actions']['move_to']))    $actions[] = 'Move to <em>' . h($r['actions']['move_to']) . '</em>';
                            if (!empty($r['actions']['delete']))     $actions[] = 'Delete';
                        ?>
                        <div class="rule-row" data-rule-id="<?= h($r['id']) ?>">
                            <label class="rule-toggle">
                                <input type="checkbox" class="rule-enabled" <?= !empty($r['enabled']) ? 'checked' : '' ?>>
                            </label>
                            <div class="rule-info">
                                <div class="rule-when">When <?= implode(' · ', $criteria) ?></div>
                                <div class="rule-then">Then <?= implode(' · ', $actions) ?></div>
                            </div>
                            <button type="button" class="cal-feed-btn" data-rule-action="run" title="Apply to existing mail">
                                <svg class="icon" width="13" height="13"><use href="#ic-play-s"/></svg>
                            </button>
                            <button type="button" class="cal-feed-btn" data-rule-action="edit" title="Edit">
                                <svg class="icon" width="13" height="13"><use href="#ic-edit-s"/></svg>
                            </button>
                            <button type="button" class="cal-feed-btn cal-feed-btn-danger" data-rule-action="delete" title="Delete">
                                <svg class="icon" width="13" height="13"><use href="#ic-trash-s"/></svg>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section id="labels" class="settings-section">
            <header class="settings-section-header">
                <h2>Labels</h2>
                <p class="settings-section-desc">Colour-coded tags, several per message, applied from the <strong>Label</strong> button above the message list. A folder holds a message in one place; labels let it be both "Interviewed" and "Dubai". They are stored on your mail server as IMAP keywords, so they survive in any other mail program, and you can search for them with <code>label:dubai</code>.</p>
            </header>
            <div class="settings-card">
                <div class="rules-list-header">
                    <span class="settings-inline-hint" id="lblCount">Loading&hellip;</span>
                    <button type="button" class="cal-modal-btn cal-modal-btn-primary" id="lblNewBtn"><svg class="icon" width="14" height="14"><use href="#ic-plus"/></svg><span>New label</span></button>
                </div>
                <div class="lbl-list" id="lblList"></div>
            </div>
            <div class="settings-card" id="lblEditor" hidden>
                <h3 class="settings-card-title" id="lblEditorTitle">New label</h3>
                <div class="settings-row settings-row-stack">
                    <label class="settings-row-label" for="lblName">Name</label>
                    <input type="text" id="lblName" class="settings-inline-input" maxlength="40" placeholder="e.g. Interviewed" autocomplete="off">
                </div>
                <div class="settings-row settings-row-stack">
                    <span class="settings-row-label">Colour</span>
                    <div class="lbl-swatches" id="lblSwatches" role="radiogroup" aria-label="Colour"></div>
                </div>
                <div class="settings-inline-edit">
                    <button type="button" class="btn" id="lblDeleteBtn" hidden>Delete</button>
                    <button type="button" class="btn" id="lblCancelBtn">Cancel</button>
                    <button type="button" class="btn btn-primary" id="lblSaveBtn">Save label</button>
                    <span class="settings-status" id="lblStatus"></span>
                </div>
                <div class="settings-row-stack">
                    <p class="settings-inline-hint">Renaming keeps the tag on your messages; deleting removes the label from this list but leaves the keyword on the server, so recreating a label with the same name brings its messages back.</p>
                </div>
            </div>
        </section>

        <section id="contacts" class="settings-section">
            <header class="settings-section-header">
                <h2>Contacts</h2>
                <p class="settings-section-desc">Addresses suggested while you type a recipient. They are collected automatically from mail you send and conversations you open — this is where you can correct a name, add someone before you have written to them, or remove an address you don't want suggested. A contact can also carry a phone, company, role, notes and <strong>groups</strong>; typing a group's name in To expands it to everyone in it.</p>
            </header>

            <div class="settings-card">
                <div class="rules-list-header">
                    <input type="search" id="contactFilter" class="cal-field-input contact-filter"
                           placeholder="Search contacts" autocomplete="off" aria-label="Search contacts">
                    <span class="contact-count" id="contactCount"></span>
                    <button type="button" class="cal-modal-btn cal-modal-btn-primary" id="contactNewBtn">
                        <svg class="icon" width="13" height="13"><use href="#ic-plus"/></svg>
                        Add contact
                    </button>
                    <span class="settings-status" id="contactStatus" role="status" aria-live="polite"></span>
                </div>
                <div class="contact-tools">
                    <div class="contact-groups" id="contactGroups"></div>
                    <div class="contact-io">
                        <label class="btn contact-import-label" for="contactImportFile">Import .vcf / .csv</label>
                        <input type="file" id="contactImportFile" accept=".vcf,.vcard,.csv,text/vcard,text/csv,text/plain" hidden>
                        <a class="btn" href="ajax/contacts.php?action=export&amp;format=vcf" download>Export vCard</a>
                        <a class="btn" href="ajax/contacts.php?action=export&amp;format=csv" download>Export CSV</a>
                        <button type="button" class="btn" id="contactDupBtn">Find duplicates</button>
                    </div>
                </div>
                <div class="contact-dups" id="contactDups" hidden></div>
                <form class="contact-form" id="contactForm" hidden>
                    <input type="hidden" id="contactOriginal" value="">
                    <div class="contact-form-row">
                        <label class="cal-field">
                            <span class="cal-field-label">Name</span>
                            <input type="text" id="contactName" class="cal-field-input" maxlength="120" placeholder="Jane Doe" autocomplete="off">
                        </label>
                        <label class="cal-field">
                            <span class="cal-field-label">Email address</span>
                            <input type="email" id="contactEmail" class="cal-field-input" maxlength="200" placeholder="jane@example.com" autocomplete="off" required>
                        </label>
                    </div>
                    <div class="contact-form-row">
                        <label class="cal-field">
                            <span class="cal-field-label">Phone</span>
                            <input type="tel" id="contactPhone" class="cal-field-input" maxlength="40" placeholder="+971 50 …" autocomplete="off">
                        </label>
                        <label class="cal-field">
                            <span class="cal-field-label">Company</span>
                            <input type="text" id="contactCompany" class="cal-field-input" maxlength="80" autocomplete="off">
                        </label>
                        <label class="cal-field">
                            <span class="cal-field-label">Role</span>
                            <input type="text" id="contactRole" class="cal-field-input" maxlength="80" autocomplete="off">
                        </label>
                    </div>
                    <div class="contact-form-row">
                        <label class="cal-field">
                            <span class="cal-field-label">Groups <span class="rule-field-hint">(comma-separated; a group name typed in To becomes everyone in it)</span></span>
                            <input type="text" id="contactGroupsInput" class="cal-field-input" maxlength="400" placeholder="Dubai, Recruiters" autocomplete="off" list="contactGroupNames">
                            <datalist id="contactGroupNames"></datalist>
                        </label>
                    </div>
                    <div class="contact-form-row">
                        <label class="cal-field">
                            <span class="cal-field-label">Notes</span>
                            <textarea id="contactNotes" class="cal-field-input contact-notes" maxlength="1000" rows="3"></textarea>
                        </label>
                    </div>
                    <div class="contact-form-actions">
                        <button type="submit" class="cal-modal-btn cal-modal-btn-primary" id="contactSave">Save contact</button>
                        <button type="button" class="cal-modal-btn" id="contactCancel">Cancel</button>
                    </div>
                </form>
                <div class="rules-list" id="contactsList">
                    <div class="cal-feed-empty">Loading…</div>
                </div>
            </div>
        </section>

        <section id="calendars" class="settings-section">
            <header class="settings-section-header">
                <h2>Connected calendars</h2>
                <p class="settings-section-desc">Subscribe to ICS feeds from Google Calendar, Outlook, iCloud, or any other source. Paste the calendar's secret iCal URL — events appear in the sidebar calendar alongside your local events. Sync is read-only and one-way.</p>
            </header>

            <div class="settings-card">
                <div class="cal-feed-form" id="calFeedForm">
                    <div class="cal-feed-form-row">
                        <input type="text" id="calFeedName" class="cal-field-input" placeholder="Display name (e.g. Personal Gmail)" maxlength="80">
                        <input type="color" id="calFeedColor" class="cal-feed-color" value="#1f5fb8" title="Calendar color">
                    </div>
                    <input type="url" id="calFeedUrl" class="cal-field-input" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics">
                    <div class="cal-feed-form-actions">
                        <button type="button" class="cal-modal-btn cal-modal-btn-primary" id="calFeedAdd">Add calendar</button>
                        <span class="settings-status" id="calFeedStatus"></span>
                    </div>
                </div>
                <p class="settings-hint">
                    <strong>Where to find your URL:</strong>
                    <em>Google Calendar:</em> Settings → click a calendar → "Integrate calendar" → copy <em>Secret address in iCal format</em>.
                    <em>Outlook 365:</em> calendar → Share → publish → copy ICS link.
                    <em>iCloud:</em> Calendar app → calendar → Public Calendar → copy URL (replace <code>webcal://</code> with <code>https://</code>).
                </p>
            </div>

            <div class="settings-card cal-feed-list-card">
                <h3 class="settings-card-title">Subscribed calendars</h3>
                <div class="cal-feed-list" id="calFeedList">
                    <?php if (empty($calendar['feeds'])): ?>
                        <div class="cal-feed-empty">No external calendars yet. Add one above.</div>
                    <?php else: ?>
                        <?php foreach ($calendar['feeds'] as $f): ?>
                            <div class="cal-feed-row" data-feed-id="<?= h($f['id']) ?>">
                                <span class="cal-feed-swatch" style="background:<?= h($f['color']) ?>"></span>
                                <div class="cal-feed-info">
                                    <div class="cal-feed-name"><?= h($f['name']) ?></div>
                                    <div class="cal-feed-meta">
                                        <?php if (!empty($f['last_sync_error'])): ?>
                                            <span class="cal-feed-err">Sync failed: <?= h($f['last_sync_error']) ?></span>
                                        <?php elseif (!empty($f['last_sync_at'])): ?>
                                            <?= (int)($f['event_count'] ?? 0) ?> events · synced <?= h(date('M j, H:i', strtotime($f['last_sync_at']))) ?>
                                        <?php else: ?>
                                            <span class="cal-feed-pending">Not synced yet</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <label class="cal-feed-toggle">
                                    <input type="checkbox" class="cal-feed-enabled" <?= !empty($f['enabled']) ? 'checked' : '' ?>>
                                    <span>Show</span>
                                </label>
                                <button type="button" class="cal-feed-btn" data-cal-action="sync" title="Sync now">
                                    <svg class="icon" width="13" height="13"><use href="#ic-refresh-s"/></svg>
                                </button>
                                <button type="button" class="cal-feed-btn cal-feed-btn-danger" data-cal-action="delete" title="Remove">
                                    <svg class="icon" width="13" height="13"><use href="#ic-trash-s"/></svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section id="mail-server" class="settings-section">
            <header class="settings-section-header">
                <h2>Mail server</h2>
                <p class="settings-section-desc">Read-only details about the IMAP and SMTP endpoints in use for this session.</p>
            </header>
            <div class="settings-card">
                <div class="settings-row">
                    <div class="settings-row-label">IMAP host</div>
                    <div class="settings-row-value mono"><?= h($_SESSION['imap_host']) ?></div>
                </div>
                <div class="settings-row">
                    <div class="settings-row-label">IMAP port</div>
                    <div class="settings-row-value mono"><?= h($_SESSION['imap_port']) ?> <?= !empty($_SESSION['imap_ssl']) ? '<span class="pill-ok">SSL</span>' : '<span class="pill-warn">no SSL</span>' ?></div>
                </div>
                <div class="settings-row">
                    <div class="settings-row-label">SMTP host</div>
                    <div class="settings-row-value mono"><?= h($_SESSION['smtp_host']) ?></div>
                </div>
                <div class="settings-row">
                    <div class="settings-row-label">SMTP port</div>
                    <div class="settings-row-value mono">tries 465 (SSL) → 587 (STARTTLS) → PHP mail()</div>
                </div>
            </div>
            <p class="settings-hint">To change these, sign out and re-sign in with Advanced settings.</p>
        </section>

        <section id="tasks" class="settings-section">
            <header class="settings-section-header">
                <h2>Background tasks</h2>
                <p class="settings-section-desc">Scheduled sends, out-of-office replies, snoozes and filters run on their own once an account is switched on: the app keeps itself awake on the server and runs them every five minutes. No cron job is needed. To do that, the server has to be able to open the mailbox on its own &mdash; which means keeping that account's password here, sealed. Turning it off deletes the stored password at once; it is also deleted automatically after 30 days with nothing pending.</p>
            </header>

            <div class="settings-alert settings-alert-danger" id="tasksBackendNote" hidden>
                <p>This server's PHP has neither libsodium nor OpenSSL, so passwords cannot be sealed here. Background tasks will keep running only while the app is open.</p>
            </div>

            <div class="settings-card">
                <div class="settings-toggles" id="tasksAccounts"><p class="settings-inline-hint">Loading&hellip;</p></div>
                <div class="settings-row-stack">
                    <p class="settings-inline-hint">The honest limit: on shared hosting the sealing key lives beside the data, so a full compromise of the hosting account would expose the password &mdash; no more than the session files PHP already writes while you are signed in.</p>
                </div>
                <div class="settings-inline-edit"><span class="settings-status" id="tasksAcctStatus"></span></div>
            </div>

            <div class="settings-card">
                <h3 class="settings-card-title">Scheduler</h3>
                <div class="settings-row">
                    <div class="settings-row-label">Status</div>
                    <div class="settings-row-value" id="tasksCronStatus">Checking&hellip;</div>
                </div>
                <div class="settings-row-stack">
                    <p class="settings-inline-hint" id="tasksCronDetail"></p>
                </div>
                <details class="settings-details" id="tasksCronDetails">
                <summary>Host cron job (optional)</summary>
                <div class="settings-row-stack">
                    <p class="settings-inline-hint">Not needed: the app wakes itself. If your host offers Cron Jobs, adding one makes the timing exact and keeps it running through server restarts.</p>
                </div>
                <div id="tasksSetup" hidden>
                    <div class="settings-row-stack">
                        <p class="settings-inline-hint">In your hosting panel's <strong>Cron Jobs</strong> (cPanel, DirectAdmin, Plesk) set the schedule to <strong>every five minutes</strong> and paste <strong>one</strong> of these as the command. On a plain crontab, put <code id="tasksSchedule">*/5 * * * *</code> in front of it.</p>
                        <pre class="settings-cron-line" id="tasksCpanel"></pre>
                        <pre class="settings-cron-line" id="tasksCurl"></pre>
                    </div>
                    <div class="settings-inline-edit">
                        <button type="button" class="btn" id="tasksCopyCpanel">Copy PHP command</button>
                        <button type="button" class="btn" id="tasksCopyCurl">Copy curl command</button>
                        <button type="button" class="btn" id="tasksRegen">New token</button>
                        <span class="settings-status" id="tasksStatus"></span>
                    </div>
                    <div class="settings-row-stack">
                        <p class="settings-inline-hint">If the PHP command answers that the IMAP extension is missing (some hosts' command-line PHP lacks it), use the curl command instead: it runs through the web server's PHP, the one this app already uses. The curl command carries a token that lets anyone who has it trigger a run (and nothing else); if it leaks, make a new one and update the cron.</p>
                    </div>
                </div>
                <div class="settings-row-stack" id="tasksNoAdmin" hidden>
                    <p class="settings-inline-hint">Only <strong id="tasksAdminEmail"></strong> can see the cron commands for this installation.</p>
                </div>
                </details>
            </div>
        </section>

        <section id="diagnostics" class="settings-section">
            <header class="settings-section-header">
                <h2>Diagnostics</h2>
                <p class="settings-section-desc">What the server knows about itself, and the last things it wrote down. The log lives in <code>data/logs/app.log</code>, rotates at 1&nbsp;MB, and never contains a password.</p>
            </header>
            <div class="settings-card">
                <div class="settings-row"><div class="settings-row-label">PHP</div><div class="settings-row-value mono" id="diagPhp">&hellip;</div></div>
                <div class="settings-row"><div class="settings-row-label">IMAP extension</div><div class="settings-row-value" id="diagImap">&hellip;</div></div>
                <div class="settings-row"><div class="settings-row-label">Credential sealing</div><div class="settings-row-value" id="diagSeal">&hellip;</div></div>
                <div class="settings-row"><div class="settings-row-label">Scheduler</div><div class="settings-row-value" id="diagCron">&hellip;</div></div>
                <div class="settings-row"><div class="settings-row-label">Self-wake</div><div class="settings-row-value" id="diagWake">&hellip;</div></div>
                <div class="settings-row"><div class="settings-row-label">Log file</div><div class="settings-row-value" id="diagLogStatus">&hellip;</div></div>
            </div>
            <div class="settings-card" id="diagLogCard">
                <h3 class="settings-card-title">Recent log</h3>
                <div class="settings-inline-edit">
                    <button type="button" class="btn" id="diagReprobe">Re-check self-wake</button>
                    <button type="button" class="btn" id="diagRefresh">Refresh</button>
                    <span class="settings-status" id="diagStatus"></span>
                </div>
                <pre class="settings-log" id="diagLog">Loading&hellip;</pre>
            </div>
            <div class="settings-alert settings-alert-info" id="diagNoAdmin" hidden>
                <p>Only <strong id="diagAdminEmail"></strong> can read the log for this installation.</p>
            </div>
        </section>

        <section id="update" class="settings-section">
            <header class="settings-section-header">
                <h2>Software update</h2>
                <p class="settings-section-desc">Update the app to the latest version from its code repository. Your signature, workspace logo, brand, filters, contacts and everything else under <span class="mono">data/</span> is preserved — only the program files change.</p>
            </header>
            <div class="settings-card">
                <div class="settings-row">
                    <div class="settings-row-label">Installed version</div>
                    <div class="settings-row-value mono" id="updateCurrent"><?= h(update_installed_label()) ?></div>
                </div>
                <div class="settings-row">
                    <div class="settings-row-label">Status</div>
                    <div class="settings-row-value" id="updateState">Not checked yet.</div>
                </div>
            </div>
            <div class="settings-actions">
                <button class="btn" id="updateCheckBtn" type="button">Check for updates</button>
                <button class="btn btn-primary" id="updateApplyBtn" type="button" hidden>Update now</button>
                <span class="settings-status" id="updateStatus"></span>
            </div>
            <p class="settings-hint">No setup needed — updates come from the app's built-in code repository. Advanced (optional): create <span class="mono">data/update.json</span> to point at a fork (<span class="mono">repo</span>/<span class="mono">branch</span>), add a <span class="mono">token</span> for a private repo, or set <span class="mono">admin_email</span> to limit who can update.</p>
        </section>

    </div>
</main>

<!-- Filter (rule) editor modal -->
<div class="cal-modal hidden" id="ruleModal" aria-hidden="true">
    <div class="cal-modal-backdrop" data-rule-close="1"></div>
    <div class="cal-modal-panel" role="dialog" aria-labelledby="ruleModalTitle" style="max-width:560px">
        <header class="cal-modal-header">
            <span class="cal-modal-title" id="ruleModalTitle">New filter</span>
            <button class="cal-modal-close" type="button" data-rule-close="1" aria-label="Close">
                <svg class="icon"><use href="#ic-x"/></svg>
            </button>
        </header>
        <div class="cal-modal-body">
            <div class="rule-section-label rule-mode-row">
                <span>When mail arrives matching</span>
                <select id="ruleMode" class="cal-field-input rule-mode-select" aria-label="How conditions combine">
                    <option value="all">all of these</option>
                    <option value="any">any of these</option>
                </select>
            </div>
            <label class="cal-field">
                <span class="cal-field-label">From</span>
                <input type="text" id="ruleFrom" class="cal-field-input" placeholder="someone@example.com or partial match">
            </label>
            <label class="cal-field">
                <span class="cal-field-label">To</span>
                <input type="text" id="ruleTo" class="cal-field-input" placeholder="me@... or alias">
            </label>
            <label class="cal-field">
                <span class="cal-field-label">Cc</span>
                <input type="text" id="ruleCc" class="cal-field-input" placeholder="copied to…">
            </label>
            <label class="cal-field">
                <span class="cal-field-label">Subject</span>
                <input type="text" id="ruleSubject" class="cal-field-input" placeholder="contains…">
            </label>
            <label class="cal-field">
                <span class="cal-field-label">Has the words</span>
                <input type="text" id="ruleHasWords" class="cal-field-input" placeholder="anywhere in headers or body">
            </label>
            <label class="cal-field">
                <span class="cal-field-label">Doesn't have</span>
                <input type="text" id="ruleNotWords" class="cal-field-input" placeholder="exclude messages containing…">
            </label>
            <label class="cal-field cal-field-check">
                <input type="checkbox" id="ruleHasAttachment">
                <span>Has attachment <span class="rule-field-hint">(always required when ticked)</span></span>
            </label>
            <div class="rule-field-pair">
                <label class="cal-field">
                    <span class="cal-field-label">Larger than</span>
                    <input type="text" id="ruleLarger" class="cal-field-input" placeholder="e.g. 5M or 500K" autocomplete="off">
                </label>
                <label class="cal-field">
                    <span class="cal-field-label">Smaller than</span>
                    <input type="text" id="ruleSmaller" class="cal-field-input" placeholder="e.g. 50K" autocomplete="off">
                </label>
            </div>
            <div class="rule-field-pair">
                <label class="cal-field">
                    <span class="cal-field-label">Older than (days)</span>
                    <input type="number" id="ruleOlder" class="cal-field-input" min="0" max="3650" placeholder="e.g. 30">
                </label>
                <label class="cal-field">
                    <span class="cal-field-label">Newer than (days)</span>
                    <input type="number" id="ruleNewer" class="cal-field-input" min="0" max="3650" placeholder="e.g. 7">
                </label>
            </div>
            <label class="cal-field">
                <span class="cal-field-label">Search query</span>
                <input type="text" id="ruleQuery" class="cal-field-input" placeholder="from:boss is:unread -subject:newsletter larger:1M" autocomplete="off" spellcheck="false">
                <span class="rule-field-hint">The same operators as the search box; anything you can search for can be filtered.</span>
            </label>
            <div class="rule-preview" id="rulePreview"></div>

            <div class="rule-section-label" style="margin-top:14px">Then do</div>
            <label class="cal-field cal-field-check">
                <input type="checkbox" id="ruleSkipInbox">
                <span>Skip the Inbox (Archive it)</span>
            </label>
            <label class="cal-field cal-field-check">
                <input type="checkbox" id="ruleMarkRead">
                <span>Mark as read</span>
            </label>
            <label class="cal-field cal-field-check">
                <input type="checkbox" id="ruleStar">
                <span>Star (flag) it</span>
            </label>
            <label class="cal-field">
                <span class="cal-field-label">Add label</span>
                <select id="ruleLabel" class="cal-field-input"><option value="">— none —</option></select>
            </label>
            <label class="cal-field">
                <span class="cal-field-label">Apply label / move to folder</span>
                <select id="ruleMoveTo" class="cal-field-input">
                    <option value="">(no folder change)</option>
                </select>
            </label>
            <label class="cal-field cal-field-check">
                <input type="checkbox" id="ruleDelete">
                <span>Delete it</span>
            </label>
            <label class="cal-field cal-field-check" id="ruleApplyExistingWrap">
                <input type="checkbox" id="ruleApplyExisting">
                <span>Also apply to existing matching messages in Inbox</span>
            </label>
        </div>
        <footer class="cal-modal-footer">
            <button class="cal-modal-btn cal-modal-btn-danger" id="ruleDeleteBtn" type="button">Delete</button>
            <span class="cal-modal-spacer"></span>
            <button class="cal-modal-btn" id="rulePreviewBtn" type="button">Preview matches</button>
            <button class="cal-modal-btn" type="button" data-rule-close="1">Cancel</button>
            <button class="cal-modal-btn cal-modal-btn-primary" id="ruleSaveBtn" type="button">Save filter</button>
        </footer>
    </div>
</div>

<div class="app-footer">
    &copy; <?= date('Y') ?> <strong><?= h($brand['name']) ?></strong> · <?= h($brand['tagline']) ?>
</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
    const $ = (id) => document.getElementById(id);

    const editor = $('signatureEditor');

    let imgInput = null;
    function openImagePicker() {
        if (!imgInput) {
            imgInput = document.createElement('input');
            imgInput.type = 'file';
            imgInput.accept = 'image/png,image/jpeg,image/gif,image/webp,image/svg+xml';
            imgInput.style.display = 'none';
            imgInput.addEventListener('change', () => {
                const file = imgInput.files && imgInput.files[0];
                if (file) handleImageFile(file);
                imgInput.value = '';
            });
            document.body.appendChild(imgInput);
        }
        imgInput.click();
    }

    function readFileAsDataURL(file) {
        return new Promise((resolve, reject) => {
            const r = new FileReader();
            r.onload = () => resolve(r.result);
            r.onerror = reject;
            r.readAsDataURL(file);
        });
    }

    function loadImage(src) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = reject;
            img.src = src;
        });
    }

    async function processImage(dataUri, mimeType) {
        if (mimeType === 'image/svg+xml') return dataUri;
        try {
            const img = await loadImage(dataUri);
            const maxW = 480, maxH = 240;
            let w = img.naturalWidth || img.width;
            let h = img.naturalHeight || img.height;
            if (w <= maxW && h <= maxH && dataUri.length < 200000) return dataUri;
            const ratio = Math.min(maxW / w, maxH / h, 1);
            w = Math.round(w * ratio);
            h = Math.round(h * ratio);
            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, w, h);
            const out = (mimeType === 'image/png' || mimeType === 'image/gif') ? 'image/png' : 'image/jpeg';
            const quality = out === 'image/jpeg' ? 0.85 : undefined;
            return canvas.toDataURL(out, quality);
        } catch (e) {
            return dataUri;
        }
    }

    async function handleImageFile(file) {
        if (!file.type || !file.type.startsWith('image/')) {
            alert('Only image files (PNG, JPG, GIF, WebP, SVG) are supported.');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert('That image is over 5MB. Please choose a smaller file.');
            return;
        }
        try {
            const dataUri = await readFileAsDataURL(file);
            const finalUri = await processImage(dataUri, file.type);
            const html = '<img src="' + finalUri + '" alt="" style="max-width:100%;height:auto;border:0;display:inline-block;">';
            editor.focus();
            document.execCommand('insertHTML', false, html);
        } catch (e) {
            alert('Could not insert image: ' + (e.message || e));
        }
    }

    document.querySelectorAll('#signature .sig-tool').forEach((b) => {
        b.addEventListener('mousedown', (e) => {
            e.preventDefault();
            const cmd = b.dataset.cmd;
            if (cmd === 'createLink') {
                const sel = window.getSelection();
                const selected = sel && sel.toString();
                const url = window.prompt(selected ? 'Link ' + selected + ' to:' : 'Insert link URL:');
                if (url) document.execCommand('createLink', false, url);
                editor.focus();
            } else if (cmd === 'image') {
                editor.focus();
                openImagePicker();
            } else {
                document.execCommand(cmd, false, null);
                editor.focus();
            }
        });
    });

    editor.addEventListener('dragover', (e) => {
        if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) {
            e.preventDefault();
            editor.classList.add('drag-active');
        }
    });
    editor.addEventListener('dragleave', () => editor.classList.remove('drag-active'));
    editor.addEventListener('drop', (e) => {
        editor.classList.remove('drag-active');
        if (!e.dataTransfer || !e.dataTransfer.files || e.dataTransfer.files.length === 0) return;
        e.preventDefault();
        handleImageFile(e.dataTransfer.files[0]);
    });

    editor.addEventListener('paste', (e) => {
        if (!e.clipboardData || !e.clipboardData.items) return;
        for (const item of e.clipboardData.items) {
            if (item.type && item.type.startsWith('image/')) {
                e.preventDefault();
                const file = item.getAsFile();
                if (file) handleImageFile(file);
                return;
            }
        }
    });

    /* Sidebar = pane switcher, as in iPadOS Settings. Every section used to render
       stacked on one enormous scroll; now exactly one is shown at a time. */
    (function () {
        const items = [...document.querySelectorAll('.settings-nav-item')];
        const panes = [...document.querySelectorAll('.settings-section')];

        function show(target, push) {
            const pane = document.getElementById(target);
            if (!pane) return;
            panes.forEach(p => p.classList.toggle('visible', p === pane));
            items.forEach(l => {
                const on = l.dataset.target === target;
                l.classList.toggle('active', on);
                if (on) l.setAttribute('aria-current', 'page'); else l.removeAttribute('aria-current');
            });
            document.querySelector('.settings-content').scrollTop = 0;
            window.scrollTo(0, 0);
            if (push && history.replaceState) history.replaceState(null, '', '#' + target);
        }

        items.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                show(link.dataset.target, true);
            });
        });

        // Honour a deep link (#filters), else open the first pane.
        const initial = (location.hash || '').replace('#', '');
        show(items.some(i => i.dataset.target === initial) ? initial : items[0].dataset.target, false);
        window.addEventListener('hashchange', () => {
            const t = (location.hash || '').replace('#', '');
            if (items.some(i => i.dataset.target === t)) show(t, false);
        });
    })();

    /* ---------- Software update ---------- */
    (function () {
        const checkBtn = $('updateCheckBtn'), applyBtn = $('updateApplyBtn');
        if (!checkBtn || !applyBtn) return;
        const stateEl = $('updateState'), curEl = $('updateCurrent'), statusEl = $('updateStatus');
        const short = (s) => (s && s.length >= 7) ? s.slice(0, 7) : (s || 'unknown');
        async function post(action) {
            const r = await fetch('ajax/update.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.__CSRF__ },
                body: 'action=' + encodeURIComponent(action),
            });
            if (r.status === 401) { window.location = 'index'; return {}; }
            try { return await r.json(); } catch (e) { return { error: 'Invalid server response' }; }
        }
        checkBtn.addEventListener('click', async () => {
            checkBtn.disabled = true; statusEl.textContent = ''; statusEl.className = 'settings-status';
            stateEl.textContent = 'Checking…';
            const d = await post('check');
            checkBtn.disabled = false;
            if (d.error) { stateEl.textContent = 'Not available.'; statusEl.className = 'settings-status error'; statusEl.textContent = d.error; applyBtn.hidden = true; return; }
            if (d.installed_label) curEl.textContent = d.installed_label;
            if (d.update_available) {
                stateEl.textContent = 'Update available — ' + short(d.latest) + (d.committed_at ? ' · ' + new Date(d.committed_at).toLocaleDateString() : '');
                applyBtn.hidden = false;
            } else {
                stateEl.textContent = 'Up to date (' + short(d.latest) + ').';
                applyBtn.hidden = true;
            }
        });
        applyBtn.addEventListener('click', async () => {
            if (!confirm('Update the app now? This replaces the program files with the latest version. Your data — signatures, logo, filters, mail — is not affected.')) return;
            applyBtn.disabled = true; checkBtn.disabled = true;
            statusEl.className = 'settings-status'; statusEl.textContent = 'Downloading and installing…';
            const d = await post('apply');
            applyBtn.disabled = false; checkBtn.disabled = false;
            if (d.error) { statusEl.className = 'settings-status error'; statusEl.textContent = d.error; return; }
            statusEl.className = 'settings-status ok';
            statusEl.textContent = 'Updated ' + (d.files || 0) + ' files'
                + (d.pruned ? ', removed ' + d.pruned + ' no longer needed' : '')
                + ' to ' + short(d.version) + '. Reloading…';
            applyBtn.hidden = true;
            setTimeout(() => location.reload(), 1500);
        });
    })();

    /* Remote-images toggle. Off (the default) blocks them until asked. */
    (function () {
        const t = $('remoteImagesToggle');
        if (!t) return;
        const s = $('remoteImagesStatus');
        t.addEventListener('change', async () => {
            const fd = new FormData();
            fd.append('remote_images', t.checked ? 'true' : 'false');
            s.textContent = ''; s.className = 'settings-status';
            try {
                const r = await fetch('ajax/prefs.php', {
                    method: 'POST', body: fd, credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': window.__CSRF__ },
                });
                const d = await r.json();
                if (d.error) { s.textContent = d.error; s.className = 'settings-status error'; t.checked = !t.checked; return; }
                s.textContent = t.checked ? 'Remote images will load automatically' : 'Remote images will be blocked';
                s.className = 'settings-status success';
                setTimeout(() => { s.textContent = ''; }, 2600);
            } catch (e) {
                s.textContent = 'Save failed: ' + e.message; s.className = 'settings-status error';
                t.checked = !t.checked;
            }
        });
    })();

    /* ---------- Contacts ---------- */
    (function () {
        const listEl = $('contactsList');
        if (!listEl) return;
        const form   = $('contactForm'), nameEl = $('contactName'), emailEl = $('contactEmail'),
              origEl = $('contactOriginal'), statusEl = $('contactStatus');
        let contacts = [], groups = [], groupFilter = '';

        const esc = (v) => String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

        function say(msg, isError) {
            statusEl.textContent = msg;
            statusEl.className = 'settings-status' + (isError ? ' error' : (msg ? ' success' : ''));
            if (msg && !isError) setTimeout(() => { statusEl.textContent = ''; }, 2400);
        }

        // Rendered as a multi-column grid of compact cards rather than one
        // full-width row per contact: an address book of any size used to run to
        // several screens of mostly-empty space.
        function visible() {
            const q = ($('contactFilter').value || '').trim().toLowerCase();
            let rows = contacts;
            if (groupFilter) rows = rows.filter(c => (c.groups || []).some(g => g.toLowerCase() === groupFilter.toLowerCase()));
            if (!q) return rows;
            return rows.filter(c =>
                (c.email || '').toLowerCase().includes(q) ||
                (c.name  || '').toLowerCase().includes(q) ||
                (c.company || '').toLowerCase().includes(q) ||
                (c.role || '').toLowerCase().includes(q) ||
                (c.phone || '').toLowerCase().includes(q) ||
                (c.groups || []).some(g => g.toLowerCase().includes(q)));
        }
        function renderGroups() {
            const el = $('contactGroups');
            const dl = $('contactGroupNames');
            if (dl) dl.innerHTML = groups.map(g => '<option value="' + esc(g.name) + '">').join('');
            if (!groups.length) { el.innerHTML = ''; return; }
            el.innerHTML = '<button type="button" class="contact-group-chip' + (!groupFilter ? ' on' : '') + '" data-group="">Everyone</button>' +
                groups.map(g => '<button type="button" class="contact-group-chip' + (groupFilter.toLowerCase() === g.name.toLowerCase() ? ' on' : '') + '" data-group="' + esc(g.name) + '">' + esc(g.name) + ' <span class="contact-group-n">' + g.count + '</span></button>').join('');
        }

        function render() {
            const rows = visible();
            const count = $('contactCount');
            if (count) {
                count.textContent = contacts.length
                    ? (rows.length === contacts.length
                        ? contacts.length + (contacts.length === 1 ? ' contact' : ' contacts')
                        : rows.length + ' of ' + contacts.length)
                    : '';
            }
            if (!contacts.length) {
                listEl.className = 'rules-list';
                listEl.innerHTML = '<div class="cal-feed-empty">No contacts yet. They are collected as you send mail, or add one above.</div>';
                return;
            }
            if (!rows.length) {
                listEl.className = 'rules-list';
                listEl.innerHTML = '<div class="cal-feed-empty">No contact matches that search.</div>';
                return;
            }
            listEl.className = 'rules-list contacts-grid';
            listEl.innerHTML = rows.map(c => {
                const label = c.name || c.email;
                const detail = [c.role, c.company].filter(Boolean).join(' · ');
                const chips = (c.groups || []).map(g => '<span class="contact-group-tag">' + esc(g) + '</span>').join('');
                return '<div class="contact-card" data-email="' + esc(c.email) + '">' +
                    '<div class="contact-card-main">' +
                        '<div class="contact-card-name">' + esc(label) + '</div>' +
                        (c.name ? '<div class="contact-card-mail">' + esc(c.email) + '</div>' : '') +
                        (detail ? '<div class="contact-card-detail">' + esc(detail) + '</div>' : '') +
                        (c.phone ? '<div class="contact-card-detail">' + esc(c.phone) + '</div>' : '') +
                        (chips ? '<div class="contact-card-groups">' + chips + '</div>' : '') +
                    '</div>' +
                    '<div class="contact-card-actions">' +
                        '<button type="button" class="cal-feed-btn" data-contact-edit="' + esc(c.email) + '" title="Edit" aria-label="Edit ' + esc(label) + '">' +
                            '<svg class="icon" width="13" height="13"><use href="#ic-edit-s"/></svg></button>' +
                        '<button type="button" class="cal-feed-btn cal-feed-btn-danger" data-contact-del="' + esc(c.email) + '" title="Remove" aria-label="Remove ' + esc(label) + '">' +
                            '<svg class="icon" width="13" height="13"><use href="#ic-trash-s"/></svg></button>' +
                    '</div>' +
                '</div>';
            }).join('');
        }

        async function api(action, params, method) {
            const opts = { method: method || 'GET', credentials: 'same-origin' };
            let url = 'ajax/contacts.php?action=' + encodeURIComponent(action);
            if (method === 'POST') {
                const fd = new FormData();
                for (const [k, v] of Object.entries(params || {})) fd.append(k, v == null ? '' : v);
                opts.body = fd;
                opts.headers = { 'X-CSRF-Token': window.__CSRF__ };
            }
            const r = await fetch(url, opts);
            if (r.status === 401) { window.location = 'index'; return {}; }
            try { return await r.json(); } catch (e) { return { error: 'Invalid server response' }; }
        }

        function openForm(c) {
            form.hidden = false;
            origEl.value  = c ? c.email : '';
            nameEl.value  = c ? (c.name || '') : '';
            emailEl.value = c ? c.email : '';
            $('contactPhone').value   = c ? (c.phone || '') : '';
            $('contactCompany').value = c ? (c.company || '') : '';
            $('contactRole').value    = c ? (c.role || '') : '';
            $('contactGroupsInput').value = c ? (c.groups || []).join(', ') : (groupFilter || '');
            $('contactNotes').value   = c ? (c.notes || '') : '';
            (c ? nameEl : emailEl).focus();
        }
        function closeForm() { form.hidden = true; origEl.value = ''; form.reset(); }

        $('contactFilter').addEventListener('input', render);
        $('contactNewBtn').addEventListener('click', () => openForm(null));
        $('contactCancel').addEventListener('click', closeForm);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const d = await api('save', {
                email: emailEl.value.trim(),
                name: nameEl.value.trim(),
                original_email: origEl.value,
                phone: $('contactPhone').value.trim(),
                company: $('contactCompany').value.trim(),
                role: $('contactRole').value.trim(),
                groups: $('contactGroupsInput').value.trim(),
                notes: $('contactNotes').value,
            }, 'POST');
            if (d.error) { say(d.error, true); return; }
            contacts = d.contacts || [];
            groups = d.groups || groupsFrom(contacts);
            renderGroups(); render(); closeForm(); say('Saved');
        });

        listEl.addEventListener('click', async (e) => {
            const ed = e.target.closest('[data-contact-edit]');
            if (ed) {
                const c = contacts.find(x => x.email === ed.dataset.contactEdit);
                if (c) openForm(c);
                return;
            }
            const del = e.target.closest('[data-contact-del]');
            if (!del) return;
            const email = del.dataset.contactDel;
            if (!confirm('Remove ' + email + ' from your contacts? This only affects address suggestions — no mail is deleted.')) return;
            const d = await api('delete', { email: email }, 'POST');
            if (d.error) { say(d.error, true); return; }
            contacts = d.contacts || [];
            groups = d.groups || groupsFrom(contacts);
            renderGroups(); render(); say('Removed');
        });

        // Groups are a view of the book; rebuild them when the server did not send them.
        function groupsFrom(list) {
            const m = new Map();
            list.forEach(c => (c.groups || []).forEach(g => { const k = g.toLowerCase(); if (!m.has(k)) m.set(k, { name: g, count: 0 }); m.get(k).count++; }));
            return Array.from(m.values()).sort((a, b) => a.name.localeCompare(b.name));
        }
        $('contactGroups').addEventListener('click', (e) => {
            const chip = e.target.closest('.contact-group-chip');
            if (!chip) return;
            groupFilter = chip.dataset.group || '';
            renderGroups(); render();
        });

        // Import: the file goes up as-is; the server tells vCard from CSV by content.
        $('contactImportFile').addEventListener('change', async (e) => {
            const f = e.target.files && e.target.files[0];
            if (!f) return;
            say('Importing ' + f.name + '…');
            const fd = new FormData();
            fd.append('file', f);
            const r = await fetch('ajax/contacts.php?action=import', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.__CSRF__ }, body: fd });
            let d; try { d = await r.json(); } catch (x) { d = { error: 'Invalid server response' }; }
            e.target.value = '';
            if (d.error) { say(d.error, true); return; }
            contacts = d.contacts || []; groups = d.groups || groupsFrom(contacts);
            renderGroups(); render();
            const n = d.result || {};
            say('Imported: ' + (n.added || 0) + ' added, ' + (n.updated || 0) + ' updated, ' + (n.skipped || 0) + ' unchanged or skipped.');
        });

        // Duplicates: the server proposes, the person decides.
        async function showDuplicates(list) {
            const box = $('contactDups');
            if (!list) { const d = await api('duplicates'); if (d.error) { say(d.error, true); return; } list = d.duplicates || []; }
            box.hidden = false;
            if (!list.length) { box.innerHTML = '<div class="cal-feed-empty">No likely duplicates found.</div>'; return; }
            box.innerHTML = '<div class="contact-dups-title">Possible duplicates — the first in each row is kept and gains what the others have.</div>' +
                list.map((g, i) => '<div class="contact-dup-row" data-i="' + i + '">' +
                    '<div class="contact-dup-main"><div class="contact-dup-reason">' + esc(g.reason) + '</div><div class="contact-dup-emails">' +
                    g.contacts.map((em, j) => '<span class="contact-dup-email' + (j === 0 ? ' keep' : '') + '">' + esc(em) + '</span>').join(' ') + '</div></div>' +
                    '<button type="button" class="btn btn-primary" data-merge="' + i + '">Merge</button></div>').join('');
            box._list = list;
        }
        $('contactDupBtn').addEventListener('click', () => showDuplicates(null));
        $('contactDups').addEventListener('click', async (e) => {
            const b = e.target.closest('[data-merge]');
            if (!b) return;
            const g = ($('contactDups')._list || [])[parseInt(b.dataset.merge, 10)];
            if (!g) return;
            b.disabled = true;
            const fd = new FormData();
            fd.append('keep', g.contacts[0]);
            g.contacts.slice(1).forEach(em => fd.append('others[]', em));
            const r = await fetch('ajax/contacts.php?action=merge', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.__CSRF__ }, body: fd });
            let d; try { d = await r.json(); } catch (x) { d = { error: 'Invalid server response' }; }
            if (d.error) { say(d.error, true); b.disabled = false; return; }
            contacts = d.contacts || []; groups = d.groups || groupsFrom(contacts);
            renderGroups(); render(); say('Merged');
            showDuplicates(d.duplicates || []);
        });

        (async () => {
            const d = await api('all');
            if (d.error) { listEl.innerHTML = '<div class="cal-feed-empty">Could not load contacts.</div>'; return; }
            contacts = d.contacts || [];
            groups = d.groups || groupsFrom(contacts);
            renderGroups(); render();
        })();
    })();

    /* ---------- Private-data exposure check ----------
     * The deny files we ship cover Apache and IIS; nginx/lighttpd read neither,
     * and no PHP code can install a rule into their config. So ask the BROWSER:
     * if it can fetch our known-content probe out of data/, the directory is
     * being served and the admin needs to know right now. Same-origin, one tiny
     * request, and silent whenever the directory is correctly denied. */
    (function () {
        const warn = $('dataExposureWarning');
        if (!warn || !window.fetch) return;
        fetch('data/exposure-probe.txt', { cache: 'no-store', credentials: 'omit' })
            .then(r => r.ok ? r.text() : null)
            .then(t => { if (t && t.indexOf('webmail-data-directory-is-web-readable') !== -1) warn.hidden = false; })
            .catch(() => {}); // blocked/offline → nothing to report
    })();

    /* ---------- Display name ---------- */
    (function () {
        const input = $('displayNameInput'), btn = $('displayNameSave'), status = $('displayNameStatus');
        if (!input || !btn) return;
        let saved = input.value;
        async function save() {
            const val = input.value.trim();
            if (val === saved) { status.textContent = ''; return; }
            btn.disabled = true; status.textContent = ''; status.className = 'settings-status';
            try {
                const r = await fetch('ajax/prefs.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': window.__CSRF__ },
                    body: new URLSearchParams({ display_name: val }),
                });
                if (r.status === 401) { window.location = 'index'; return; }
                const d = await r.json();
                if (d.error) { status.textContent = d.error; status.className = 'settings-status error'; }
                else {
                    saved = val; input.value = val;
                    status.textContent = 'Saved'; status.className = 'settings-status success';
                    // Reflect the new name in the top-bar chip without a reload.
                    const chip = document.querySelector('.topbar-user-name');
                    if (chip) chip.textContent = val || <?= js($email) ?>;
                    setTimeout(() => { status.textContent = ''; }, 2200);
                }
            } catch (e) {
                status.textContent = 'Save failed: ' + e.message; status.className = 'settings-status error';
            }
            btn.disabled = false;
        }
        btn.addEventListener('click', save);
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } });
    })();

    $('saveSignature').addEventListener('click', async () => {
        const status = $('signatureStatus');
        const btn = $('saveSignature');
        btn.disabled = true;
        status.textContent = 'Saving…';
        status.className = 'settings-status';

        const body = new URLSearchParams({
            signature: $('signatureEditor').innerHTML,
            auto_append: $('autoAppend').checked ? '1' : '',
            append_on_replies: $('appendOnReplies').checked ? '1' : '',
        });
        try {
            const r = await fetch('ajax/prefs.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': window.__CSRF__ },
                body
            });
            const data = await r.json();
            if (data.error) {
                status.textContent = data.error;
                status.className = 'settings-status error';
            } else {
                status.textContent = 'Saved';
                status.className = 'settings-status success';
                setTimeout(() => { status.textContent = ''; }, 2200);
            }
        } catch (e) {
            status.textContent = 'Save failed: ' + e.message;
            status.className = 'settings-status error';
        }
        btn.disabled = false;
    });

    /* === Workspace logo === */
    let pendingLogo = undefined; // undefined = no change, '' = remove, '<datauri>' = new
    let logoInput = null;
    const logoPreview = $('logoPreview');

    function openLogoPicker() {
        if (!logoInput) {
            logoInput = document.createElement('input');
            logoInput.type = 'file';
            logoInput.accept = 'image/png,image/jpeg,image/gif,image/webp,image/svg+xml';
            logoInput.style.display = 'none';
            logoInput.addEventListener('change', () => {
                const file = logoInput.files && logoInput.files[0];
                if (file) handleLogoFile(file);
                logoInput.value = '';
            });
            document.body.appendChild(logoInput);
        }
        logoInput.click();
    }

    async function processLogo(dataUri, mimeType) {
        if (mimeType === 'image/svg+xml') return dataUri;
        try {
            const img = await loadImage(dataUri);
            const maxW = 360, maxH = 80;
            let w = img.naturalWidth || img.width;
            let h = img.naturalHeight || img.height;
            const ratio = Math.min(maxW / w, maxH / h, 1);
            w = Math.round(w * ratio);
            h = Math.round(h * ratio);
            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, w, h);
            const out = (mimeType === 'image/png' || mimeType === 'image/gif') ? 'image/png' : 'image/jpeg';
            const quality = out === 'image/jpeg' ? 0.9 : undefined;
            return canvas.toDataURL(out, quality);
        } catch (e) {
            return dataUri;
        }
    }

    async function handleLogoFile(file) {
        if (!file.type || !file.type.startsWith('image/')) {
            alert('Logo must be an image (PNG, JPG, GIF, WebP, SVG).');
            return;
        }
        if (file.size > 3 * 1024 * 1024) {
            alert('Logo file too large (max 3 MB).');
            return;
        }
        try {
            const dataUri = await readFileAsDataURL(file);
            const finalUri = await processLogo(dataUri, file.type);
            pendingLogo = finalUri;
            logoPreview.innerHTML = '<img src="' + finalUri + '" alt="Workspace logo" data-ink-detect>';
            // Re-measure so the preview shows the backdrop the topbar will use,
            // before the tenant commits to this image.
            if (window.detectLogoInk) window.detectLogoInk(logoPreview);
            $('logoRemoveBtn').hidden = false;
            $('logoStatus').textContent = 'Click "Save logo" to apply.';
            $('logoStatus').className = 'settings-status';
        } catch (e) {
            alert('Could not load image: ' + (e.message || e));
        }
    }

    $('logoPickBtn').addEventListener('click', openLogoPicker);

    $('logoRemoveBtn').addEventListener('click', () => {
        pendingLogo = '';
        logoPreview.innerHTML = '<span class="logo-empty">No logo set</span>';
        $('logoRemoveBtn').hidden = true;
        $('logoStatus').textContent = 'Click "Save logo" to remove.';
        $('logoStatus').className = 'settings-status';
    });

    logoPreview.addEventListener('dragover', (e) => {
        if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) {
            e.preventDefault();
            logoPreview.classList.add('drag-active');
        }
    });
    logoPreview.addEventListener('dragleave', () => logoPreview.classList.remove('drag-active'));
    logoPreview.addEventListener('drop', (e) => {
        logoPreview.classList.remove('drag-active');
        if (!e.dataTransfer || !e.dataTransfer.files || e.dataTransfer.files.length === 0) return;
        e.preventDefault();
        handleLogoFile(e.dataTransfer.files[0]);
    });
    logoPreview.addEventListener('paste', (e) => {
        if (!e.clipboardData || !e.clipboardData.items) return;
        for (const item of e.clipboardData.items) {
            if (item.type && item.type.startsWith('image/')) {
                e.preventDefault();
                const file = item.getAsFile();
                if (file) handleLogoFile(file);
                return;
            }
        }
    });

    /* === Image resize handles in signature editor === */
    let selectedImg = null;
    let resizeOverlay = null;

    function ensureOverlay() {
        if (resizeOverlay) return resizeOverlay;
        resizeOverlay = document.createElement('div');
        resizeOverlay.className = 'img-resize-overlay';
        resizeOverlay.innerHTML = ['nw','ne','sw','se'].map(c =>
            '<div class="resize-handle resize-' + c + '" data-corner="' + c + '"></div>'
        ).join('');
        resizeOverlay.style.display = 'none';
        document.body.appendChild(resizeOverlay);
        resizeOverlay.querySelectorAll('.resize-handle').forEach(h => {
            h.addEventListener('mousedown', (e) => startResize(e, h.dataset.corner));
        });
        return resizeOverlay;
    }

    function positionOverlay() {
        if (!selectedImg || !resizeOverlay) return;
        if (!editor.contains(selectedImg)) { deselectImage(); return; }
        const r = selectedImg.getBoundingClientRect();
        resizeOverlay.style.left   = r.left + 'px';
        resizeOverlay.style.top    = r.top + 'px';
        resizeOverlay.style.width  = r.width + 'px';
        resizeOverlay.style.height = r.height + 'px';
    }

    function selectImage(img) {
        if (selectedImg && selectedImg !== img) selectedImg.classList.remove('img-selected');
        selectedImg = img;
        img.classList.add('img-selected');
        ensureOverlay();
        positionOverlay();
        resizeOverlay.style.display = 'block';
    }

    function deselectImage() {
        if (selectedImg) selectedImg.classList.remove('img-selected');
        selectedImg = null;
        if (resizeOverlay) resizeOverlay.style.display = 'none';
    }

    function startResize(e, corner) {
        if (!selectedImg) return;
        e.preventDefault();
        e.stopPropagation();
        const startX  = e.clientX;
        const startW  = selectedImg.offsetWidth;
        const startH  = selectedImg.offsetHeight;
        const aspect  = startH > 0 ? startW / startH : 1;
        const isLeft  = corner === 'nw' || corner === 'sw';
        document.body.style.userSelect = 'none';

        function onMove(ev) {
            const dx = ev.clientX - startX;
            let newW = isLeft ? (startW - dx) : (startW + dx);
            newW = Math.max(40, Math.min(800, newW));
            const newH = Math.round(newW / aspect);
            newW = Math.round(newW);
            selectedImg.style.width  = newW + 'px';
            selectedImg.style.height = newH + 'px';
            selectedImg.setAttribute('width',  newW);
            selectedImg.setAttribute('height', newH);
            positionOverlay();
        }
        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.body.style.userSelect = '';
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    }

    editor.addEventListener('click', (e) => {
        const img = e.target.closest('img');
        if (img && editor.contains(img)) {
            e.preventDefault();
            selectImage(img);
        } else {
            deselectImage();
        }
    });

    document.addEventListener('mousedown', (e) => {
        if (e.target.closest('.img-resize-overlay')) return;
        if (e.target.closest('#signatureEditor img')) return;
        deselectImage();
    });

    editor.addEventListener('input', () => {
        if (selectedImg && !editor.contains(selectedImg)) deselectImage();
        else positionOverlay();
    });
    editor.addEventListener('scroll', positionOverlay);
    window.addEventListener('scroll', positionOverlay, true);
    window.addEventListener('resize', positionOverlay);

    $('saveLogo').addEventListener('click', async () => {
        if (pendingLogo === undefined) {
            $('logoStatus').textContent = 'No changes to save.';
            $('logoStatus').className = 'settings-status';
            return;
        }
        const btn = $('saveLogo');
        const status = $('logoStatus');
        btn.disabled = true;
        status.textContent = 'Saving…';
        status.className = 'settings-status';

        const body = new URLSearchParams({ workspace_logo: pendingLogo });
        try {
            const r = await fetch('ajax/prefs.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': window.__CSRF__ }, body });
            const data = await r.json();
            if (data.error) {
                status.textContent = data.error;
                status.className = 'settings-status error';
            } else {
                status.textContent = 'Saved · reload your inbox to see it';
                status.className = 'settings-status success';
                pendingLogo = undefined;
            }
        } catch (e) {
            status.textContent = 'Save failed: ' + e.message;
            status.className = 'settings-status error';
        }
        btn.disabled = false;
    });
})();
</script>

<script nonce="<?= csp_nonce() ?>">
/* ---------- Connected calendars (settings page) ---------- */
(function () {
    const $ = (id) => document.getElementById(id);

    function postJson(action, body) {
        return fetch('ajax/calendar.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.__CSRF__ },
            credentials: 'same-origin',
            body: JSON.stringify(body || {}),
        }).then(r => r.json());
    }

    function setStatus(msg, isError) {
        const el = $('calFeedStatus');
        if (!el) return;
        el.textContent = msg || '';
        el.style.color = isError ? 'var(--c-error)' : 'var(--c-success)';
    }

    function fmtSync(iso, errorMsg, count) {
        if (errorMsg) return '<span class="cal-feed-err">Sync failed: ' + escapeHtml(errorMsg) + '</span>';
        if (!iso) return '<span class="cal-feed-pending">Not synced yet</span>';
        const d = new Date(iso);
        const opts = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
        return (count || 0) + ' events · synced ' + d.toLocaleString(undefined, opts);
    }

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function renderFeedRow(feed) {
        const wrap = document.createElement('div');
        wrap.className = 'cal-feed-row';
        wrap.dataset.feedId = feed.id;
        wrap.innerHTML =
            '<span class="cal-feed-swatch" style="background:' + escapeHtml(feed.color || '#1f5fb8') + '"></span>' +
            '<div class="cal-feed-info">' +
                '<div class="cal-feed-name">' + escapeHtml(feed.name) + '</div>' +
                '<div class="cal-feed-meta">' + fmtSync(feed.last_sync_at, feed.last_sync_error, feed.event_count) + '</div>' +
            '</div>' +
            '<label class="cal-feed-toggle"><input type="checkbox" class="cal-feed-enabled" ' + (feed.enabled ? 'checked' : '') + '><span>Show</span></label>' +
            '<button type="button" class="cal-feed-btn" data-cal-action="sync" title="Sync now"><svg class="icon" width="13" height="13"><use href="#ic-refresh-s"/></svg></button>' +
            '<button type="button" class="cal-feed-btn cal-feed-btn-danger" data-cal-action="delete" title="Remove"><svg class="icon" width="13" height="13"><use href="#ic-trash-s"/></svg></button>';
        return wrap;
    }

    /* Out-of-office (vacation responder) */
    const oooSaveBtn = $('oooSaveBtn');
    if (oooSaveBtn) {
        // Inline formatting toolbar for the body
        document.querySelectorAll('#out-of-office .signature-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const cmd = btn.dataset.cmd;
                if (cmd) document.execCommand(cmd, false, null);
            });
        });
        oooSaveBtn.addEventListener('click', async () => {
            const cfg = {
                enabled:       $('oooEnabled').checked,
                start_date:    $('oooStart').value,
                end_date:      $('oooEnd').value,
                subject:       $('oooSubject').value.trim(),
                body:          $('oooBody').innerHTML,
                cooldown_days: parseInt($('oooCooldown').value, 10) || 0,
            };
            const status = $('oooStatus');
            status.textContent = 'Saving…';
            status.style.color = '';
            oooSaveBtn.disabled = true;
            try {
                const r = await fetch('ajax/out_of_office.php?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.__CSRF__ },
                    credentials: 'same-origin',
                    body: JSON.stringify({ config: cfg }),
                });
                const data = await r.json();
                if (data.error) {
                    status.textContent = data.error;
                    status.style.color = 'var(--c-error)';
                } else {
                    status.textContent = 'Saved';
                    status.style.color = 'var(--c-success)';
                    $('oooState').textContent = cfg.enabled ? 'on' : 'off';
                    setTimeout(() => { status.textContent = ''; }, 3000);
                }
            } catch (e) {
                status.textContent = 'Network error';
                status.style.color = 'var(--c-error)';
            }
            oooSaveBtn.disabled = false;
        });
    }

    /* Density radio group */
    const densityWrap = $('densityOptions');
    if (densityWrap) {
        densityWrap.addEventListener('change', async (e) => {
            const r = e.target.closest('input[name="density"]');
            if (!r) return;
            densityWrap.querySelectorAll('.density-option').forEach(o => o.classList.toggle('active', o.contains(r)));
            const status = $('densityStatus');
            status.textContent = 'Saving…';
            status.style.color = '';
            try {
                const fd = new FormData();
                fd.append('density', r.value);
                const resp = await fetch('ajax/prefs.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': window.__CSRF__ } });
                const data = await resp.json();
                if (data.error) {
                    status.textContent = data.error;
                    status.style.color = 'var(--c-error)';
                    return;
                }
                status.textContent = 'Saved · changes apply on next page load';
                status.style.color = 'var(--c-success)';
                setTimeout(() => { status.textContent = ''; }, 3000);
            } catch (err) {
                status.textContent = 'Network error';
                status.style.color = 'var(--c-error)';
            }
        });
    }

    /* Theme radio group — applies live and persists the pref. */
    const themeWrap = $('themeOptions');
    if (themeWrap) {
        const mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        const applyTheme = (t) => {
            const dark = t === 'dark' || (t === 'system' && !!(mq && mq.matches));
            document.documentElement.classList.toggle('theme-dark', dark);
            document.documentElement.classList.toggle('theme-light', !dark);
        };
        themeWrap.addEventListener('change', async (e) => {
            const r = e.target.closest('input[name="theme"]');
            if (!r) return;
            themeWrap.querySelectorAll('.density-option').forEach(o => o.classList.toggle('active', o.contains(r)));
            applyTheme(r.value);
            const status = $('themeStatus');
            status.textContent = 'Saving…';
            status.style.color = '';
            try {
                const fd = new FormData();
                fd.append('theme', r.value);
                const resp = await fetch('ajax/prefs.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': window.__CSRF__ } });
                const data = await resp.json();
                if (data.error) {
                    status.textContent = data.error;
                    status.style.color = 'var(--c-error)';
                    return;
                }
                status.textContent = 'Saved';
                status.style.color = 'var(--c-success)';
                setTimeout(() => { status.textContent = ''; }, 3000);
            } catch (err) {
                status.textContent = 'Network error';
                status.style.color = 'var(--c-error)';
            }
        });
    }

    /* Desktop notifications toggle — request OS permission when turned on. */
    const notifyToggle = $('notifyToggle');
    if (notifyToggle) {
        const nStatus = $('notifyStatus');
        const setStatus = (msg, err) => { if (nStatus) { nStatus.textContent = msg; nStatus.style.color = err ? 'var(--c-error)' : ''; } };
        const saveNotify = async (on) => {
            const fd = new FormData(); fd.append('notifications', on ? 'true' : 'false');
            try {
                const resp = await fetch('ajax/prefs.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': window.__CSRF__ } });
                const data = await resp.json();
                if (data.error) { setStatus(data.error, true); return false; }
                setStatus('Saved'); setTimeout(() => setStatus(''), 2500); return true;
            } catch (e) { setStatus('Network error', true); return false; }
        };
        if (!('Notification' in window)) {
            notifyToggle.disabled = true;
            setStatus('This browser does not support notifications.', true);
        }
        notifyToggle.addEventListener('change', async () => {
            if (notifyToggle.checked) {
                let perm = ('Notification' in window) ? Notification.permission : 'denied';
                if (perm === 'default') { try { perm = await Notification.requestPermission(); } catch (e) { perm = 'denied'; } }
                if (perm !== 'granted') {
                    notifyToggle.checked = false;
                    setStatus(perm === 'denied' ? 'Blocked by the browser — allow notifications for this site, then try again.' : 'Permission not granted.', true);
                    return;
                }
                await saveNotify(true);
            } else {
                await saveNotify(false);
            }
        });
    }

    const addBtn = $('calFeedAdd');
    if (addBtn) {
        addBtn.addEventListener('click', async () => {
            const name = $('calFeedName').value.trim();
            const url  = $('calFeedUrl').value.trim();
            const color = $('calFeedColor').value;
            if (!name || !url) { setStatus('Name and URL are required', true); return; }
            addBtn.disabled = true;
            setStatus('Adding…');
            const r = await postJson('feed_add', { name, url, color });
            if (r.error) {
                setStatus(r.error, true);
                addBtn.disabled = false;
                return;
            }
            setStatus('Added — syncing…');
            const sync = await postJson('feed_sync', { id: r.feed.id });
            const feed = sync.feed || r.feed;
            if (sync.error) {
                feed.last_sync_error = sync.error;
            }
            const list = $('calFeedList');
            const empty = list.querySelector('.cal-feed-empty');
            if (empty) empty.remove();
            list.appendChild(renderFeedRow(feed));
            $('calFeedName').value = '';
            $('calFeedUrl').value  = '';
            setStatus(sync.error ? 'Saved (sync failed: ' + sync.error + ')' : 'Added — ' + (sync.count || 0) + ' events synced',
                      !!sync.error);
            addBtn.disabled = false;
        });
    }

    const list = $('calFeedList');
    if (list) {
        list.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-cal-action]');
            if (!btn) return;
            const row  = btn.closest('.cal-feed-row');
            if (!row) return;
            const id   = row.dataset.feedId;
            const act  = btn.dataset.calAction;
            if (act === 'delete') {
                if (!confirm('Remove this calendar? Cached events will be discarded.')) return;
                const r = await postJson('feed_delete', { id });
                if (r.error) { alert(r.error); return; }
                row.remove();
                if (!list.children.length) {
                    list.innerHTML = '<div class="cal-feed-empty">No external calendars yet. Add one above.</div>';
                }
            } else if (act === 'sync') {
                btn.disabled = true;
                btn.classList.add('cal-feed-btn-syncing');
                const r = await postJson('feed_sync', { id });
                btn.disabled = false;
                btn.classList.remove('cal-feed-btn-syncing');
                if (r.error) {
                    row.querySelector('.cal-feed-meta').innerHTML = fmtSync(null, r.error, 0);
                    return;
                }
                row.querySelector('.cal-feed-meta').innerHTML = fmtSync(r.feed.last_sync_at, null, r.feed.event_count);
            }
        });
        list.addEventListener('change', async (e) => {
            const cb = e.target.closest('.cal-feed-enabled');
            if (!cb) return;
            const row = cb.closest('.cal-feed-row');
            const id  = row.dataset.feedId;
            const r = await postJson('feed_toggle', { id, enabled: cb.checked });
            if (r.error) { alert(r.error); cb.checked = !cb.checked; }
        });
    }
})();
</script>

<script nonce="<?= csp_nonce() ?>">
/* ---------- Filters / Rules (settings page) ---------- */
(function () {
    const $ = (id) => document.getElementById(id);

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function postJson(action, body) {
        return fetch('ajax/rules.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.__CSRF__ },
            credentials: 'same-origin',
            body: JSON.stringify(body || {}),
        }).then(r => r.json());
    }

    let editingRuleId = null;
    let folderListLoaded = false;

    async function loadFolderListIntoSelect() {
        if (folderListLoaded) return;
        try {
            const r = await fetch('ajax/fetch.php?action=folders', { credentials: 'same-origin' });
            const data = await r.json();
            if (!data || !Array.isArray(data.folders)) return;
            const sel = $('ruleMoveTo');
            const cur = sel.value;
            sel.innerHTML = '<option value="">(no folder change)</option>' +
                data.folders.map(f => '<option value="' + escapeHtml(f.name) + '">' + escapeHtml(displayFolderName(f.name)) + '</option>').join('');
            sel.value = cur;
            folderListLoaded = true;
        } catch (e) {}
    }
    function displayFolderName(name) {
        if (!name) return '';
        if (name.toLowerCase() === 'inbox') return 'Inbox';
        return name.split(/[.\\/]/).map((p, i, a) => i === a.length - 1 ? p.charAt(0).toUpperCase() + p.slice(1) : p).join(' / ');
    }

    // Bytes back to what was typed: 5242880 → 5M, 51200 → 50K (mirrors rule_size_text).
    function sizeText(b) {
        b = parseInt(b, 10) || 0;
        if (b <= 0) return '';
        if (b % 1048576 === 0) return (b / 1048576) + 'M';
        if (b % 1024 === 0) return (b / 1024) + 'K';
        return String(b);
    }
    function readModalForm() {
        return {
            id: editingRuleId,
            enabled: true,
            match: {
                mode:           $('ruleMode').value,
                from:           $('ruleFrom').value.trim(),
                to:             $('ruleTo').value.trim(),
                cc:             $('ruleCc').value.trim(),
                subject:        $('ruleSubject').value.trim(),
                has_words:      $('ruleHasWords').value.trim(),
                not_words:      $('ruleNotWords').value.trim(),
                has_attachment: $('ruleHasAttachment').checked,
                larger:         $('ruleLarger').value.trim(),
                smaller:        $('ruleSmaller').value.trim(),
                older_than:     parseInt($('ruleOlder').value, 10) || 0,
                newer_than:     parseInt($('ruleNewer').value, 10) || 0,
                query:          $('ruleQuery').value.trim(),
            },
            actions: {
                skip_inbox: $('ruleSkipInbox').checked,
                mark_read:  $('ruleMarkRead').checked,
                star:       $('ruleStar').checked,
                label:      $('ruleLabel').value,
                move_to:    $('ruleMoveTo').value,
                delete:     $('ruleDelete').checked,
            },
        };
    }
    function fillModalForm(rule) {
        $('ruleMode').value         = rule?.match?.mode === 'any' ? 'any' : 'all';
        $('ruleFrom').value         = rule?.match?.from         || '';
        $('ruleTo').value           = rule?.match?.to           || '';
        $('ruleCc').value           = rule?.match?.cc           || '';
        $('ruleSubject').value      = rule?.match?.subject      || '';
        $('ruleHasWords').value     = rule?.match?.has_words    || '';
        $('ruleNotWords').value     = rule?.match?.not_words    || '';
        $('ruleHasAttachment').checked = !!rule?.match?.has_attachment;
        $('ruleLarger').value       = sizeText(rule?.match?.larger);
        $('ruleSmaller').value      = sizeText(rule?.match?.smaller);
        $('ruleOlder').value        = rule?.match?.older_than ? String(rule.match.older_than) : '';
        $('ruleNewer').value        = rule?.match?.newer_than ? String(rule.match.newer_than) : '';
        $('ruleQuery').value        = rule?.match?.query        || '';
        $('ruleSkipInbox').checked  = !!rule?.actions?.skip_inbox;
        $('ruleMarkRead').checked   = !!rule?.actions?.mark_read;
        $('ruleStar').checked       = !!rule?.actions?.star;
        $('ruleLabel').value        = rule?.actions?.label || '';
        $('ruleMoveTo').value       = rule?.actions?.move_to    || '';
        $('ruleDelete').checked     = !!rule?.actions?.delete;
        $('rulePreview').textContent = '';
        $('ruleApplyExisting').checked = false;
    }
    function openRuleModal(rule) {
        editingRuleId = rule?.id || null;
        $('ruleModalTitle').textContent = editingRuleId ? 'Edit filter' : 'New filter';
        $('ruleDeleteBtn').style.display = editingRuleId ? '' : 'none';
        $('ruleApplyExistingWrap').style.display = editingRuleId ? 'none' : '';
        fillModalForm(rule || {});
        loadFolderListIntoSelect();
        $('ruleModal').classList.remove('hidden');
        $('ruleModal').setAttribute('aria-hidden', 'false');
    }
    function closeRuleModal() {
        $('ruleModal').classList.add('hidden');
        $('ruleModal').setAttribute('aria-hidden', 'true');
        editingRuleId = null;
    }

    // The account's labels, for the "Add label" action and the list summary.
    let cachedLabels = [];
    function labelName(id) { const l = cachedLabels.find(x => x.id === id); return l ? l.name : '(deleted label)'; }
    fetch('ajax/labels.php?action=list', { credentials: 'same-origin' }).then(r => r.json()).then(r => {
        cachedLabels = (r && r.labels) ? r.labels : [];
        const sel = $('ruleLabel');
        if (sel) cachedLabels.forEach(l => { const o = document.createElement('option'); o.value = l.id; o.textContent = l.name; sel.appendChild(o); });
        renderRulesList();
    }).catch(() => {});
    function summarizeRule(r) {
        const c = [];
        if (r.match.from)           c.push('From: <em>' + escapeHtml(r.match.from) + '</em>');
        if (r.match.to)             c.push('To: <em>' + escapeHtml(r.match.to) + '</em>');
        if (r.match.subject)        c.push('Subject: <em>' + escapeHtml(r.match.subject) + '</em>');
        if (r.match.has_words)      c.push('Has: <em>' + escapeHtml(r.match.has_words) + '</em>');
        if (r.match.not_words)      c.push('Not: <em>' + escapeHtml(r.match.not_words) + '</em>');
        if (r.match.cc)             c.push('Cc: <em>' + escapeHtml(r.match.cc) + '</em>');
        if (r.match.larger)         c.push('Larger than <em>' + escapeHtml(sizeText(r.match.larger)) + '</em>');
        if (r.match.smaller)        c.push('Smaller than <em>' + escapeHtml(sizeText(r.match.smaller)) + '</em>');
        if (r.match.older_than)     c.push('Older than <em>' + r.match.older_than + ' day' + (r.match.older_than === 1 ? '' : 's') + '</em>');
        if (r.match.newer_than)     c.push('Newer than <em>' + r.match.newer_than + ' day' + (r.match.newer_than === 1 ? '' : 's') + '</em>');
        if (r.match.query)          c.push('Query: <em>' + escapeHtml(r.match.query) + '</em>');
        if (r.match.has_attachment) c.push('Has attachment');
        const joiner = (r.match.mode === 'any' && c.length > 1) ? ' <b>or</b> ' : ' · ';
        const a = [];
        if (r.actions.skip_inbox) a.push('Skip Inbox');
        if (r.actions.mark_read)  a.push('Mark read');
        if (r.actions.star)       a.push('Star');
        if (r.actions.label)      a.push('Label: <em>' + escapeHtml(labelName(r.actions.label)) + '</em>');
        if (r.actions.move_to)    a.push('Move to <em>' + escapeHtml(r.actions.move_to) + '</em>');
        if (r.actions.delete)     a.push('Delete');
        return { when: c.join(joiner), then: a.join(' · ') };
    }
    function rowFromRule(r) {
        const wrap = document.createElement('div');
        wrap.className = 'rule-row';
        wrap.dataset.ruleId = r.id;
        const s = summarizeRule(r);
        wrap.innerHTML =
            '<label class="rule-toggle"><input type="checkbox" class="rule-enabled" ' + (r.enabled ? 'checked' : '') + '></label>' +
            '<div class="rule-info"><div class="rule-when">When ' + s.when + '</div><div class="rule-then">Then ' + s.then + '</div></div>' +
            '<button type="button" class="cal-feed-btn" data-rule-action="run" title="Apply to existing mail"><svg class="icon" width="13" height="13"><use href="#ic-play-s"/></svg></button>' +
            '<button type="button" class="cal-feed-btn" data-rule-action="edit" title="Edit"><svg class="icon" width="13" height="13"><use href="#ic-edit-s"/></svg></button>' +
            '<button type="button" class="cal-feed-btn cal-feed-btn-danger" data-rule-action="delete" title="Delete"><svg class="icon" width="13" height="13"><use href="#ic-trash-s"/></svg></button>';
        return wrap;
    }
    let cachedRules = <?= js(array_values($rulesData['rules'])) ?>;

    /* ---- Wire up ---- */

    $('ruleNewBtn').addEventListener('click', () => openRuleModal(null));

    $('ruleModal').addEventListener('click', (e) => {
        if (e.target.closest('[data-rule-close="1"]')) closeRuleModal();
    });

    $('ruleSaveBtn').addEventListener('click', async () => {
        const body = readModalForm();
        if (!editingRuleId) body.apply_existing = $('ruleApplyExisting').checked;
        const action = editingRuleId ? 'update' : 'add';
        const r = await postJson(action, body);
        if (r.error) { alert(r.error); return; }
        if (editingRuleId) {
            const i = cachedRules.findIndex(x => x.id === editingRuleId);
            if (i >= 0) cachedRules[i] = r.rule;
        } else {
            cachedRules.push(r.rule);
            if (r.applied) alert('Filter created. Applied to ' + r.applied + ' existing message' + (r.applied === 1 ? '' : 's') + '.');
        }
        renderRulesList();
        closeRuleModal();
    });

    $('ruleDeleteBtn').addEventListener('click', async () => {
        if (!editingRuleId) return;
        if (!confirm('Delete this filter?')) return;
        const r = await postJson('delete', { id: editingRuleId });
        if (r.error) { alert(r.error); return; }
        cachedRules = cachedRules.filter(x => x.id !== editingRuleId);
        renderRulesList();
        closeRuleModal();
    });

    $('rulePreviewBtn').addEventListener('click', async () => {
        $('rulePreview').textContent = 'Searching…';
        const body = readModalForm();
        const r = await postJson('preview', body);
        if (r.error) { $('rulePreview').textContent = r.error; return; }
        const out = $('rulePreview');
        if (!r.count) {
            out.textContent = 'No matches in your Inbox right now.';
            return;
        }
        const samples = (r.matches || []).slice(0, 5).map(m =>
            '<li><strong>' + escapeHtml(m.from) + '</strong> · ' + escapeHtml(m.subject || '(no subject)') + '</li>'
        ).join('');
        out.innerHTML = '<div>' + r.count + ' message' + (r.count === 1 ? '' : 's') + ' match. Sample:</div><ul>' + samples + '</ul>';
    });

    function renderRulesList() {
        const list = $('rulesList');
        if (!cachedRules.length) {
            list.innerHTML = '<div class="cal-feed-empty">No filters yet. Click "Create new filter" to set one up.</div>';
            return;
        }
        list.innerHTML = '';
        cachedRules.forEach(r => list.appendChild(rowFromRule(r)));
    }

    $('rulesList').addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-rule-action]');
        if (!btn) return;
        const row = btn.closest('.rule-row');
        if (!row) return;
        const id = row.dataset.ruleId;
        const act = btn.dataset.ruleAction;
        const rule = cachedRules.find(x => x.id === id);
        if (act === 'edit')   return openRuleModal(rule);
        if (act === 'delete') {
            if (!confirm('Delete this filter?')) return;
            const r = await postJson('delete', { id });
            if (r.error) { alert(r.error); return; }
            cachedRules = cachedRules.filter(x => x.id !== id);
            renderRulesList();
            return;
        }
        if (act === 'run') {
            btn.disabled = true;
            const r = await postJson('run_one', { id });
            btn.disabled = false;
            if (r.error) { alert(r.error); return; }
            alert('Applied to ' + r.count + ' message' + (r.count === 1 ? '' : 's') + '.');
            return;
        }
    });
    $('rulesList').addEventListener('change', async (e) => {
        const cb = e.target.closest('.rule-enabled');
        if (!cb) return;
        const row = cb.closest('.rule-row');
        const id = row.dataset.ruleId;
        const r = await postJson('toggle', { id, enabled: cb.checked });
        if (r.error) { alert(r.error); cb.checked = !cb.checked; return; }
        const rule = cachedRules.find(x => x.id === id);
        if (rule) rule.enabled = cb.checked;
    });
})();
</script>
<script nonce="<?= csp_nonce() ?>">
/* === Labels === */
(function () {
    const $ = id => document.getElementById(id);
    if (!$('lblList')) return;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const say = (msg, kind) => { const el = $('lblStatus'); el.textContent = msg || ''; el.className = 'settings-status' + (kind ? ' ' + kind : ''); };
    const call = async (action, body, asJson) => {
        const r = await fetch('ajax/labels.php?action=' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': asJson ? 'application/json' : 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.__CSRF__ },
            body: asJson ? JSON.stringify(body) : new URLSearchParams(body).toString(),
        });
        if (r.status === 401) { window.location = 'index'; return { error: 'Signed out' }; }
        try { return await r.json(); } catch (e) { return { error: 'Invalid server response' }; }
    };
    let list = [], editing = null, color = 0;
    const COLORS = 12;

    function render() {
        $('lblCount').textContent = list.length ? list.length + ' label' + (list.length === 1 ? '' : 's') : 'No labels yet.';
        $('lblList').innerHTML = list.map(l =>
            '<div class="lbl-row" data-id="' + esc(l.id) + '"><span class="label-dot label-c' + (l.color | 0) + '"></span>' +
            '<div class="lbl-row-main"><div class="lbl-row-name">' + esc(l.name) + '</div><div class="lbl-row-sub">label:' + esc(l.slug) + '</div></div>' +
            '<button type="button" class="btn" data-edit="' + esc(l.id) + '">Edit</button></div>').join('');
    }
    function renderSwatches() {
        let h = '';
        for (let i = 0; i < COLORS; i++) h += '<button type="button" class="lbl-swatch label-c' + i + (i === color ? ' selected' : '') + '" role="radio" aria-checked="' + (i === color) + '" data-color="' + i + '" aria-label="Colour ' + (i + 1) + '"></button>';
        $('lblSwatches').innerHTML = h;
    }
    async function load() {
        const r = await fetch('ajax/labels.php?action=list', { credentials: 'same-origin' }).then(x => x.json()).catch(() => ({ error: 'Network error' }));
        if (r.error) { $('lblCount').textContent = r.error; return; }
        list = r.labels || [];
        render();
    }
    function open(l) {
        editing = l ? l.id : null;
        color = l ? (l.color | 0) : (list.length % COLORS);
        $('lblEditorTitle').textContent = l ? 'Edit label' : 'New label';
        $('lblName').value = l ? l.name : '';
        $('lblDeleteBtn').hidden = !l;
        renderSwatches();
        $('lblEditor').hidden = false;
        say('');
        $('lblName').focus();
    }
    function close() { $('lblEditor').hidden = true; editing = null; }

    $('lblNewBtn').addEventListener('click', () => open(null));
    $('lblCancelBtn').addEventListener('click', close);
    $('lblList').addEventListener('click', (e) => {
        const b = e.target.closest('[data-edit]');
        if (!b) return;
        const l = list.find(x => x.id === b.dataset.edit);
        if (l) open(l);
    });
    $('lblSwatches').addEventListener('click', (e) => {
        const sw = e.target.closest('.lbl-swatch');
        if (!sw) return;
        color = parseInt(sw.dataset.color, 10) || 0;
        renderSwatches();
    });
    $('lblSaveBtn').addEventListener('click', async () => {
        say('Saving…');
        const r = await call('save', { id: editing || '', name: $('lblName').value, color }, true);
        if (r.error) { say(r.error, 'error'); return; }
        list = r.labels || list;
        render();
        close();
    });
    $('lblDeleteBtn').addEventListener('click', async () => {
        if (!editing || !window.confirm('Delete this label? Messages keep the keyword on the server; the label just stops showing.')) return;
        const r = await call('delete', { id: editing });
        if (r.error) { say(r.error, 'error'); return; }
        list = r.labels || [];
        render();
        close();
    });
    load();
})();
</script>
<script nonce="<?= csp_nonce() ?>">
/* === Templates ===
   A list and an editor. Everything saved goes through ajax/templates.php,
   which sanitises the body the way the signature is sanitised. */
(function () {
    const $ = id => document.getElementById(id);
    if (!$('tplList')) return;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const say = (msg, kind) => { const el = $('tplStatus'); el.textContent = msg || ''; el.className = 'settings-status' + (kind ? ' ' + kind : ''); };
    const call = async (action, body, asJson) => {
        const r = await fetch('ajax/templates.php?action=' + action, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': asJson ? 'application/json' : 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.__CSRF__ },
            body: asJson ? JSON.stringify(body) : new URLSearchParams(body).toString(),
        });
        if (r.status === 401) { window.location = 'index'; return { error: 'Signed out' }; }
        try { return await r.json(); } catch (e) { return { error: 'Invalid server response' }; }
    };
    let list = [], editing = null;

    function render() {
        $('tplCount').textContent = list.length ? list.length + ' template' + (list.length === 1 ? '' : 's') : 'No templates yet.';
        $('tplList').innerHTML = list.map(t => {
            const tmp = document.createElement('div'); tmp.innerHTML = t.body || '';
            const snippet = (t.subject || tmp.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 90);
            return '<div class="tpl-row" data-id="' + esc(t.id) + '">' +
                '<div class="tpl-row-main"><div class="tpl-row-name">' + esc(t.name) + '</div>' +
                (snippet ? '<div class="tpl-row-sub">' + esc(snippet) + '</div>' : '') + '</div>' +
                '<button type="button" class="btn" data-edit="' + esc(t.id) + '">Edit</button></div>';
        }).join('');
    }
    async function load() {
        const r = await fetch('ajax/templates.php?action=list', { credentials: 'same-origin' }).then(x => x.json()).catch(() => ({ error: 'Network error' }));
        if (r.error) { $('tplCount').textContent = r.error; return; }
        list = r.templates || [];
        render();
    }
    function open(t) {
        editing = t ? t.id : null;
        $('tplEditorTitle').textContent = t ? 'Edit template' : 'New template';
        $('tplName').value = t ? t.name : '';
        $('tplSubject').value = t ? (t.subject || '') : '';
        $('tplBody').innerHTML = t ? (t.body || '') : '';
        $('tplDeleteBtn').hidden = !t;
        $('tplEditor').hidden = false;
        say('');
        $('tplName').focus();
    }
    function close() { $('tplEditor').hidden = true; editing = null; }

    $('tplNewBtn').addEventListener('click', () => open(null));
    $('tplCancelBtn').addEventListener('click', close);
    $('tplList').addEventListener('click', (e) => {
        const b = e.target.closest('[data-edit]');
        if (!b) return;
        const t = list.find(x => x.id === b.dataset.edit);
        if (t) open(t);
    });
    $('tplSaveBtn').addEventListener('click', async () => {
        say('Saving…');
        const r = await call('save', { id: editing || '', name: $('tplName').value, subject: $('tplSubject').value, body: $('tplBody').innerHTML }, true);
        if (r.error) { say(r.error, 'error'); return; }
        list = r.templates || list;
        render();
        close();
    });
    $('tplDeleteBtn').addEventListener('click', async () => {
        if (!editing || !window.confirm('Delete this template?')) return;
        const r = await call('delete', { id: editing });
        if (r.error) { say(r.error, 'error'); return; }
        list = r.templates || [];
        render();
        close();
    });
    // The body toolbar: formatting, and a click on a variable types it at the caret.
    const editor = $('tplBody');
    document.querySelectorAll('#templates .sig-tool').forEach((b) => {
        b.addEventListener('mousedown', (e) => {
            e.preventDefault();
            editor.focus();
            if (b.dataset.var) { document.execCommand('insertText', false, b.dataset.var); return; }
            const cmd = b.dataset.cmd;
            if (cmd === 'createLink') {
                const url = window.prompt('Link to:');
                if (url) document.execCommand('createLink', false, url);
            } else {
                document.execCommand(cmd, false, null);
            }
        });
    });
    load();
})();
</script>
<script nonce="<?= csp_nonce() ?>">
/* === Background tasks + Diagnostics ===
   One status call feeds both panes. Every switch here is a round trip to
   ajax/cron.php, never local state: what the page shows is what the server
   holds, so a failed save can never look like a success. */
(function () {
    const $ = id => document.getElementById(id);
    if (!$('tasksAccounts')) return;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const post = async (body) => {
        const r = await fetch('ajax/cron.php', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.__CSRF__ },
            body: new URLSearchParams(body).toString() });
        if (r.status === 401) { window.location = 'index'; return { error: 'Signed out' }; }
        try { return await r.json(); } catch (e) { return { error: 'Invalid server response' }; }
    };
    const get = async (q) => {
        const r = await fetch('ajax/cron.php?' + q, { credentials: 'same-origin' });
        if (r.status === 401) { window.location = 'index'; return { error: 'Signed out' }; }
        try { return await r.json(); } catch (e) { return { error: 'Invalid server response' }; }
    };
    const say = (el, msg, kind) => { if (!el) return; el.textContent = msg || ''; el.className = 'settings-status' + (kind ? ' ' + kind : ''); };
    const ago = (iso) => {
        const t = iso ? Date.parse(iso) : NaN;
        if (isNaN(t)) return 'never';
        const s = Math.max(0, Math.round((Date.now() - t) / 1000));
        if (s < 60) return s + ' s ago';
        if (s < 3600) return Math.round(s / 60) + ' min ago';
        if (s < 86400) return Math.round(s / 3600) + ' h ago';
        return Math.round(s / 86400) + ' d ago';
    };

    function paint(d) {
        // accounts
        const wrap = $('tasksAccounts');
        if (!d.accounts || !d.accounts.length) {
            wrap.innerHTML = '<p class="settings-inline-hint">No accounts are signed in.</p>';
        } else {
            wrap.innerHTML = d.accounts.map(a =>
                '<label class="settings-toggle">' +
                    '<input type="checkbox" class="tasks-acct" data-acct="' + esc(a.id) + '"' + (a.enabled ? ' checked' : '') + (d.backend ? '' : ' disabled') + '>' +
                    '<span class="toggle-track"><span class="toggle-thumb"></span></span>' +
                    '<span class="toggle-label">Run tasks for ' + esc(a.email) + ' while signed out</span>' +
                '</label>').join('');
        }
        $('tasksBackendNote').hidden = !!d.backend;

        // scheduler status — the same words in both panes
        // The server decides the state (lib/cron.php cron_status_words); the
        // page only puts words to it, with the same words in both panes.
        const s    = d.status || { state: 'off', detail: '' };
        const last = d.last_run ? ago(d.last_run) : 'never';
        const next = (d.next_due > 0) ? ' Next in ' + (d.next_due < 90 ? d.next_due + ' s' : Math.round(d.next_due / 60) + ' min') + '.' : '';
        const words = ({
            off:      ['Off', ''],
            starting: ['Starting — the first run is on its way.', ''],
            self:     ['Self-running — last run ' + last + '.' + next, 'ok'],
            cron:     ['Running (host cron) — last run ' + last + '.', 'ok'],
            running:  ['Running — last run ' + last + '.' + next, 'ok'],
            stopped:  ['Not running — last run ' + last + '.', 'error'],
        })[s.state] || ['Unknown', ''];
        $('tasksCronStatus').textContent = words[0];
        $('tasksCronStatus').className = 'settings-row-value' + (words[1] ? ' settings-status ' + words[1] : '');
        $('tasksCronDetail').textContent = s.detail || '';
        $('diagCron').textContent = words[0];
        const tr = d.trigger || {};
        const wakeNames = { local: 'loopback (127.0.0.1)', 'local-noverify': 'loopback (127.0.0.1, unverified TLS)', public: 'its own address',
                            inline: 'in-process, after each response', sync: 'inside background polls only', none: 'none', '': 'not checked yet' };
        $('diagWake').textContent = (wakeNames[tr.method] !== undefined ? wakeNames[tr.method] : tr.method)
            + (tr.error ? ' — ' + tr.error : '')
            + (d.chain && d.chain.mode === 'unsupported' ? ' — chain unsupported: ' + d.chain.reason : (d.hop_len ? ' — hop ' + d.hop_len + ' s' : ''));

        // setup lines — admins only
        $('tasksSetup').hidden = !d.is_admin;
        $('tasksNoAdmin').hidden = !!d.is_admin;
        if (d.is_admin) {
            $('tasksSchedule').textContent = d.schedule || '*/5 * * * *';
            $('tasksCpanel').textContent   = d.php_cmd || '';
            $('tasksCurl').textContent     = d.curl_cmd || '';
        } else {
            $('tasksAdminEmail').textContent = d.admin_email || '';
        }

        // diagnostics facts
        $('diagPhp').textContent  = d.php || '';
        $('diagImap').textContent = d.imap ? 'available' : 'MISSING — nothing in this app can read mail';
        $('diagSeal').textContent = d.backend ? (d.backend === 'sodium' ? 'libsodium' : 'OpenSSL AES-256-GCM') : 'unavailable';
        const lg = d.log || {};
        const kb = lg.bytes > 0 && lg.bytes < 1024 ? '<1 KB' : Math.round((lg.bytes || 0) / 1024) + ' KB';
        $('diagLogStatus').textContent = (lg.file || 'data/logs/app.log') + ' · ' + kb +
            (lg.generations && lg.generations.length ? ' · ' + lg.generations.length + ' rotated' : '') +
            (lg.writable === false ? ' · NOT WRITABLE' : '');
        $('diagNoAdmin').hidden = !!d.is_admin;
        $('diagLogCard').hidden = !d.is_admin;
        if (!d.is_admin) { $('diagAdminEmail').textContent = d.admin_email || ''; $('diagLog').textContent = ''; }
    }

    async function load() {
        const d = await get('action=status');
        if (d.error) { say($('tasksStatus'), d.error, 'error'); $('tasksCronStatus').textContent = d.error; return; }
        paint(d);
        if (d.is_admin) loadLog();
    }

    async function loadLog() {
        say($('diagStatus'), 'Loading…');
        const d = await get('action=log&n=200');
        if (d.error) { say($('diagStatus'), d.error, 'error'); return; }
        const lines = d.lines || [];
        $('diagLog').innerHTML = lines.length
            ? lines.map(l => { const bad = / (ERROR|FATAL) /.test(l); return '<span class="' + (bad ? 'err' : '') + '">' + esc(l) + '</span>'; }).join('\n')
            : 'Nothing logged yet.';
        $('diagLog').scrollTop = $('diagLog').scrollHeight;
        say($('diagStatus'), lines.length + ' line' + (lines.length === 1 ? '' : 's'));
    }

    $('tasksAccounts').addEventListener('change', async (e) => {
        const cb = e.target.closest('.tasks-acct');
        if (!cb) return;
        cb.disabled = true;
        say($('tasksAcctStatus'), 'Saving…');
        const d = await post({ action: cb.checked ? 'enable' : 'disable', acct: cb.dataset.acct });
        if (d.error) { cb.checked = !cb.checked; cb.disabled = false; say($('tasksAcctStatus'), d.error, 'error'); return; }
        paint(d);
        say($('tasksAcctStatus'), cb.checked ? 'On. The password is sealed on the server.' : 'Off. The stored password was deleted.', 'ok');
    });

    const copy = async (text, el) => {
        try { await navigator.clipboard.writeText(text); say(el, 'Copied.', 'ok'); }
        catch (e) { say(el, 'Select the line and copy it.', 'error'); }
    };
    $('tasksCopyCpanel').addEventListener('click', () => copy($('tasksCpanel').textContent, $('tasksStatus')));
    $('tasksCopyCurl').addEventListener('click',   () => copy($('tasksCurl').textContent,   $('tasksStatus')));
    $('tasksRegen').addEventListener('click', async () => {
        say($('tasksStatus'), 'Making a new token…');
        const d = await post({ action: 'regenerate_token' });
        if (d.error) { say($('tasksStatus'), d.error, 'error'); return; }
        paint(d);
        say($('tasksStatus'), 'New token. Update the cron line on your host.', 'ok');
    });
    $('diagRefresh').addEventListener('click', loadLog);
    $('diagReprobe').addEventListener('click', async () => {
        say($('diagStatus'), 'Looking for a way to reach cron.php…');
        const d = await post({ action: 'reprobe' });
        if (d.error) { say($('diagStatus'), d.error, 'error'); return; }
        paint(d);
        say($('diagStatus'), 'Checked.', 'ok');
        loadLog();
    });

    load();
})();
</script>
<script nonce="<?= csp_nonce() ?>">
/* === Workspace branding ===
   Install-wide, so the server owns every fallback: an empty field means "derive
   it", and the derived value only ever comes from ajax/brand.php. Keeping that
   logic in one place is what stops the preview drifting from what the app will
   actually render. */
(function () {
    const $ = id => document.getElementById(id);
    const nameI = $('wsName'), tagI = $('wsTagline');
    const colorI = $('wsColor'), colorT = $('wsColorText');
    const saveB = $('wsSave'), resetB = $('wsReset'), status = $('wsStatus');
    if (!nameI) return;

    let derived = { name: '', tagline: '', color: '#0078d4', domain: '' };
    let mayEdit = true;
    const HEX = /^#[0-9a-fA-F]{6}$/;

    const say = (msg, kind) => {
        status.textContent = msg || '';
        status.className = 'settings-status' + (kind ? ' ' + kind : '');
    };

    function paintPreview() {
        // An empty field means "derive it", so the preview shows the derived
        // value — exactly what the header will show after a reload.
        $('wsPreviewName').textContent = (nameI.value.trim() || derived.name) + ' WorkSpace';
        $('wsPreviewTag').textContent = tagI.value.trim() || derived.tagline;
        const c = colorT.value.trim();
        $('wsPreviewDot').style.background = HEX.test(c) ? c : derived.color;
    }

    function applyState(d) {
        if (d.derived) derived = d.derived;
        mayEdit = d.may_edit !== false;
        const o = d.override || {};
        nameI.value = o.name || '';
        tagI.value = o.tagline || '';
        colorT.value = o.color || '';
        colorI.value = HEX.test(colorT.value) ? colorT.value : derived.color;
        nameI.placeholder = derived.name;
        tagI.placeholder = derived.tagline;
        $('wsNameHint').textContent =
            'Leave empty to use "' + derived.name + '", derived from ' +
            (derived.domain || 'this domain') + '.';
        if (!mayEdit) {
            [nameI, tagI, colorI, colorT, saveB, resetB].forEach(el => { el.disabled = true; });
            $('wsAdminEmail').textContent = d.admin_email || 'the administrator';
            $('wsAdminNote').hidden = false;
        }
        paintPreview();
    }

    [nameI, tagI, colorT].forEach(el => el.addEventListener('input', paintPreview));
    colorI.addEventListener('input', () => { colorT.value = colorI.value; paintPreview(); });
    colorT.addEventListener('input', () => {
        const v = colorT.value.trim();
        if (HEX.test(v)) colorI.value = v;
    });

    async function post(body, working, done) {
        saveB.disabled = true; resetB.disabled = true;
        say(working);
        try {
            const r = await fetch('ajax/brand.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': window.__CSRF__ },
                body: body
            });
            const d = await r.json();
            if (d.error) { say(d.error, 'error'); return; }
            applyState(d);
            say(done, 'ok');
        } catch (e) {
            say('Could not reach the server.', 'error');
        } finally {
            if (mayEdit) { saveB.disabled = false; resetB.disabled = false; }
        }
    }

    saveB.addEventListener('click', () => {
        const c = colorT.value.trim();
        if (c !== '' && !HEX.test(c)) {
            say('Colour must be a 6-digit hex value such as #0078d4.', 'error');
            colorT.focus();
            return;
        }
        post(new URLSearchParams({ name: nameI.value, tagline: tagI.value, color: c }),
             'Saving...', 'Saved. Reload to see it in the header.');
    });

    resetB.addEventListener('click', () => {
        post(new URLSearchParams({ reset: '1' }),
             'Resetting...', 'Reset. The name is derived from the domain again.');
    });

    fetch('ajax/brand.php', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => { if (!d.error) applyState(d); })
        .catch(() => {});
})();
</script>
<script src="assets/logo-ink.js?v=<?= @filemtime(__DIR__."/assets/logo-ink.js") ?>"></script>
</body>
</html>
