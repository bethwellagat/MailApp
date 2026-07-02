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
<meta name="theme-color" content="#0078d4">
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
            <svg class="icon"><use href="#ic-user"/></svg>
            <span>Account</span>
        </a>
        <a class="settings-nav-item" href="#signature" data-target="signature">
            <svg class="icon"><use href="#ic-pen"/></svg>
            <span>Signature</span>
        </a>
        <a class="settings-nav-item" href="#logo" data-target="logo">
            <svg class="icon"><use href="#ic-image"/></svg>
            <span>Workspace logo</span>
        </a>
        <a class="settings-nav-item" href="#out-of-office" data-target="out-of-office">
            <svg class="icon"><use href="#ic-plane-s"/></svg>
            <span>Out of office</span>
        </a>
        <a class="settings-nav-item" href="#filters" data-target="filters">
            <svg class="icon"><use href="#ic-funnel-s"/></svg>
            <span>Filters</span>
        </a>
        <a class="settings-nav-item" href="#calendars" data-target="calendars">
            <svg class="icon"><use href="#ic-calendar-s"/></svg>
            <span>Calendars</span>
        </a>
        <a class="settings-nav-item" href="#mail-server" data-target="mail-server">
            <svg class="icon"><use href="#ic-server"/></svg>
            <span>Mail server</span>
        </a>
        <a class="settings-nav-item" href="#update" data-target="update">
            <svg class="icon"><use href="#ic-refresh-s"/></svg>
            <span>Software update</span>
        </a>
    </nav>

    <div class="settings-content">

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
                <div class="settings-row">
                    <div class="settings-row-label">Display name</div>
                    <div class="settings-row-value"><?= h($display_name) ?></div>
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

        <section id="logo" class="settings-section">
            <header class="settings-section-header">
                <h2>Workspace logo</h2>
                <p class="settings-section-desc">Displayed in the top-left of your inbox header. PNG with transparency works best. Image is auto-resized to fit the topbar.</p>
            </header>
            <div class="settings-card logo-card">
                <div class="logo-preview" id="logoPreview">
                    <?php if (!empty($prefs['workspace_logo'])): ?>
                        <img src="<?= h($prefs['workspace_logo']) ?>" alt="Workspace logo">
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

        <section id="update" class="settings-section">
            <header class="settings-section-header">
                <h2>Software update</h2>
                <p class="settings-section-desc">Update the app to the latest version from its code repository. Your signature, workspace logo, brand, filters, contacts and everything else under <span class="mono">data/</span> is preserved — only the program files change.</p>
            </header>
            <div class="settings-card">
                <div class="settings-row">
                    <div class="settings-row-label">Installed version</div>
                    <div class="settings-row-value mono" id="updateCurrent">unknown</div>
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
            <div class="rule-section-label">When mail arrives matching</div>
            <label class="cal-field">
                <span class="cal-field-label">From</span>
                <input type="text" id="ruleFrom" class="cal-field-input" placeholder="someone@example.com or partial match">
            </label>
            <label class="cal-field">
                <span class="cal-field-label">To</span>
                <input type="text" id="ruleTo" class="cal-field-input" placeholder="me@... or alias">
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
                <span>Has attachment</span>
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

    document.querySelectorAll('.sig-tool').forEach((b) => {
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

    document.querySelectorAll('.settings-nav-item').forEach((link) => {
        link.addEventListener('click', () => {
            document.querySelectorAll('.settings-nav-item').forEach(l => l.classList.remove('active'));
            link.classList.add('active');
        });
    });

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
            curEl.textContent = d.current ? short(d.current) : 'not recorded';
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
            statusEl.textContent = 'Updated ' + (d.files || 0) + ' files to ' + short(d.version) + '. Reloading…';
            applyBtn.hidden = true;
            setTimeout(() => location.reload(), 1500);
        });
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
            logoPreview.innerHTML = '<img src="' + finalUri + '" alt="Workspace logo">';
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

    function readModalForm() {
        return {
            id: editingRuleId,
            enabled: true,
            match: {
                from:           $('ruleFrom').value.trim(),
                to:             $('ruleTo').value.trim(),
                subject:        $('ruleSubject').value.trim(),
                has_words:      $('ruleHasWords').value.trim(),
                not_words:      $('ruleNotWords').value.trim(),
                has_attachment: $('ruleHasAttachment').checked,
            },
            actions: {
                skip_inbox: $('ruleSkipInbox').checked,
                mark_read:  $('ruleMarkRead').checked,
                star:       $('ruleStar').checked,
                move_to:    $('ruleMoveTo').value,
                delete:     $('ruleDelete').checked,
            },
        };
    }
    function fillModalForm(rule) {
        $('ruleFrom').value         = rule?.match?.from         || '';
        $('ruleTo').value           = rule?.match?.to           || '';
        $('ruleSubject').value      = rule?.match?.subject      || '';
        $('ruleHasWords').value     = rule?.match?.has_words    || '';
        $('ruleNotWords').value     = rule?.match?.not_words    || '';
        $('ruleHasAttachment').checked = !!rule?.match?.has_attachment;
        $('ruleSkipInbox').checked  = !!rule?.actions?.skip_inbox;
        $('ruleMarkRead').checked   = !!rule?.actions?.mark_read;
        $('ruleStar').checked       = !!rule?.actions?.star;
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

    function summarizeRule(r) {
        const c = [];
        if (r.match.from)           c.push('From: <em>' + escapeHtml(r.match.from) + '</em>');
        if (r.match.to)             c.push('To: <em>' + escapeHtml(r.match.to) + '</em>');
        if (r.match.subject)        c.push('Subject: <em>' + escapeHtml(r.match.subject) + '</em>');
        if (r.match.has_words)      c.push('Has: <em>' + escapeHtml(r.match.has_words) + '</em>');
        if (r.match.not_words)      c.push('Not: <em>' + escapeHtml(r.match.not_words) + '</em>');
        if (r.match.has_attachment) c.push('Has attachment');
        const a = [];
        if (r.actions.skip_inbox) a.push('Skip Inbox');
        if (r.actions.mark_read)  a.push('Mark read');
        if (r.actions.star)       a.push('Star');
        if (r.actions.move_to)    a.push('Move to <em>' + escapeHtml(r.actions.move_to) + '</em>');
        if (r.actions.delete)     a.push('Delete');
        return { when: c.join(' · '), then: a.join(' · ') };
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
</body>
</html>
