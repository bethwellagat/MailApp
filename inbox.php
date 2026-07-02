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
require_once __DIR__ . '/lib/csrf.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// Encode a value for safe embedding inside a <script> block: the JSON_HEX_*
// flags escape <, >, &, ' and " so a stored value can never break out of the
// script context (e.g. a signature containing "</script>").
function js($v) { return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

$display_name = $_SESSION['display_name'] ?? $_SESSION['email'];
$email        = $_SESSION['email'];
$initial      = strtoupper(mb_substr($display_name, 0, 1, 'UTF-8'));

$brand        = resolve_brand();
$brandDomain  = $brand['domain'];
$brandLabel   = $brand['name'] . ' WorkSpace';

$prefs        = load_prefs($email);
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
<title>Inbox · <?= h($brandLabel) ?></title>
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
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs>
    <symbol id="ic-envelope" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/>
    </symbol>
    <symbol id="ic-envelope-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 8l9 6 9-6"/><path d="M3 8v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8l-9-5z"/>
    </symbol>
    <symbol id="ic-inbox" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/>
    </symbol>
    <symbol id="ic-send" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
    </symbol>
    <symbol id="ic-draft" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>
    </symbol>
    <symbol id="ic-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
    </symbol>
    <symbol id="ic-spam" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    </symbol>
    <symbol id="ic-folder" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
    </symbol>
    <symbol id="ic-archive" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>
    </symbol>
    <symbol id="ic-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </symbol>
    <symbol id="ic-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
    </symbol>
    <symbol id="ic-pencil" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/>
    </symbol>
    <symbol id="ic-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
    </symbol>
    <symbol id="ic-reply" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>
    </symbol>
    <symbol id="ic-reply-all" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="7 17 2 12 7 7"/><polyline points="12 17 7 12 12 7"/><path d="M22 18v-2a4 4 0 0 0-4-4H7"/>
    </symbol>
    <symbol id="ic-forward" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="15 17 20 12 15 7"/><path d="M4 18v-2a4 4 0 0 1 4-4h12"/>
    </symbol>
    <symbol id="ic-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
    </symbol>
    <symbol id="ic-minimize" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="6" y1="18" x2="18" y2="18"/>
    </symbol>
    <symbol id="ic-expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>
    </symbol>
    <symbol id="ic-collapse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/>
    </symbol>
    <symbol id="ic-logout" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
    </symbol>
    <symbol id="ic-list" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
    </symbol>
    <symbol id="ic-flag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>
    </symbol>
    <symbol id="ic-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </symbol>
    <symbol id="ic-move" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><polyline points="14 8 17 11 14 14"/><line x1="9" y1="11" x2="17" y2="11"/>
    </symbol>
    <symbol id="ic-chev-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="6 9 12 15 18 9"/>
    </symbol>
    <symbol id="ic-filter" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
    </symbol>
    <symbol id="ic-info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
    </symbol>
    <symbol id="ic-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </symbol>
    <symbol id="ic-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
    </symbol>
    <symbol id="ic-shield-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    </symbol>
    <symbol id="ic-block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
    </symbol>
    <symbol id="ic-gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
    </symbol>
    <symbol id="ic-pin" viewBox="0 0 24 24" fill="currentColor" stroke="none">
      <path d="M16 4l-1.4 1.4 1 1L11.7 10l-2.1-.7-1.5 1.5 4.6 4.6L11 18l1.4 1.4 2.1-2.1 4.6 4.6 1.4-1.4-4.6-4.6 1.7-1.7-.7-2.1 4.3-3.9 1 1L24 8z" transform="rotate(-45 12 12)"/>
    </symbol>
    <symbol id="ic-paperclip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
    </symbol>
    <symbol id="ic-replied" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/>
    </symbol>
    <symbol id="ic-user" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
    </symbol>
    <symbol id="ic-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
    </symbol>
    <symbol id="ic-print" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="6 9 6 2 18 2 18 9"/>
      <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
      <rect x="6" y="14" width="12" height="8"/>
    </symbol>
    <symbol id="ic-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
      <polyline points="7 10 12 15 17 10"/>
      <line x1="12" y1="15" x2="12" y2="3"/>
    </symbol>
    <symbol id="ic-calendar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
      <line x1="16" y1="2" x2="16" y2="6"/>
      <line x1="8" y1="2" x2="8" y2="6"/>
      <line x1="3" y1="10" x2="21" y2="10"/>
    </symbol>
    <symbol id="ic-chev-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="15 18 9 12 15 6"/>
    </symbol>
    <symbol id="ic-chev-right" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="9 18 15 12 9 6"/>
    </symbol>
  </defs>
</svg>

<div class="app-shell">

    <header class="topbar">
        <a class="topbar-logo-slot" id="topbarLogo" href="inbox" title="Go to inbox · refresh">
            <?php if (!empty($prefs['workspace_logo'])): ?>
                <img src="<?= h($prefs['workspace_logo']) ?>" alt="<?= h($brandLabel) ?>" class="topbar-logo">
            <?php else: ?>
                <span class="topbar-logo-default">
                    <span class="topbar-logo-mark"><svg class="icon" width="14" height="14"><use href="#ic-envelope"/></svg></span>
                    <span class="topbar-logo-text"><?= h($brandLabel) ?></span>
                </span>
            <?php endif; ?>
        </a>
        <div class="topbar-search">
            <input type="search" id="searchInput" class="topbar-search-input" placeholder="Search" aria-label="Search mail">
            <svg class="icon topbar-search-icon"><use href="#ic-search"/></svg>
        </div>
        <div class="topbar-end">
            <button type="button" class="topbar-icon-light" id="themeToggle" title="Toggle dark mode" aria-label="Toggle dark mode">
                <svg class="icon theme-ic-to-dark" width="14" height="14"><use href="#ic-moon"/></svg>
                <svg class="icon theme-ic-to-light" width="14" height="14"><use href="#ic-sun"/></svg>
            </button>
            <a class="topbar-icon-light" href="settings" title="Settings" aria-label="Settings">
                <svg class="icon" width="14" height="14"><use href="#ic-gear"/></svg>
            </a>
            <a class="topbar-user" href="logout" title="Sign out" aria-label="Sign out">
                <span class="topbar-user-avatar"><?= h($initial) ?></span>
                <span class="topbar-user-name"><?= h($display_name) ?></span>
                <svg class="icon topbar-user-logout" width="15" height="15"><use href="#ic-logout"/></svg>
            </a>
        </div>
    </header>

    <nav class="actionbar">
        <button class="ab-btn ab-icon-only" id="drawerToggle" title="Folders" aria-label="Folders">
            <svg class="icon"><use href="#ic-menu"/></svg>
        </button>
        <button class="ab-btn ab-primary" id="composeBtn">
            <svg class="icon"><use href="#ic-plus"/></svg>
            <span>New Email</span>
        </button>
        <div class="ab-divider"></div>
        <button class="ab-btn" id="abDelete" disabled>
            <svg class="icon"><use href="#ic-trash"/></svg>
            <span>Delete</span>
        </button>
        <button class="ab-btn" id="abArchive" disabled>
            <svg class="icon"><use href="#ic-archive"/></svg>
            <span>Archive</span>
        </button>
        <div class="ab-btn-wrap">
            <button class="ab-btn" id="abMove" disabled>
                <svg class="icon"><use href="#ic-move"/></svg>
                <span>Move</span>
                <svg class="icon ab-chev" width="12" height="12"><use href="#ic-chev-down"/></svg>
            </button>
            <div class="dropdown" id="moveDropdown"></div>
        </div>
        <button class="ab-btn" id="abFlag" disabled>
            <svg class="icon"><use href="#ic-flag"/></svg>
            <span>Flag</span>
        </button>
        <button class="ab-btn" id="abMarkRead" disabled>
            <svg class="icon"><use href="#ic-envelope-open"/></svg>
            <span>Mark Read</span>
        </button>
        <button class="ab-btn" id="abMarkUnread" disabled>
            <svg class="icon"><use href="#ic-envelope"/></svg>
            <span>Mark Unread</span>
        </button>
        <div class="ab-divider"></div>
        <button class="ab-btn" id="abSync">
            <svg class="icon"><use href="#ic-refresh"/></svg>
            <span>Sync</span>
        </button>
    </nav>

    <main class="app-main" id="appMain">

        <aside class="folder-pane" id="folderPane">
            <div class="folder-pane-header">
                <span class="folder-pane-title">Folders</span>
                <button class="folder-pane-add" id="folderNewBtn" title="New folder" aria-label="New folder">
                    <svg class="icon" width="14" height="14"><use href="#ic-plus"/></svg>
                </button>
            </div>
            <nav class="folder-list" id="folderList">
                <div class="list-loading">Loading…</div>
            </nav>

            <section class="cal-pane" id="calPane">
                <header class="cal-pane-header">
                    <button class="cal-pane-toggle" id="calCollapseBtn" title="Toggle calendar">
                        <svg class="icon" width="13" height="13"><use href="#ic-calendar"/></svg>
                        <span class="cal-pane-title">Calendar</span>
                        <svg class="icon cal-pane-chev" width="11" height="11"><use href="#ic-chev-down"/></svg>
                    </button>
                    <button class="cal-pane-add" id="calAddBtn" title="New event" aria-label="New event">
                        <svg class="icon" width="13" height="13"><use href="#ic-plus"/></svg>
                    </button>
                </header>
                <div class="cal-pane-body" id="calPaneBody">
                    <div class="cal-mini">
                        <div class="cal-mini-nav">
                            <button class="cal-mini-navbtn" id="calPrevMonth" title="Previous month" aria-label="Previous month">
                                <svg class="icon" width="13" height="13"><use href="#ic-chev-left"/></svg>
                            </button>
                            <button class="cal-mini-month" id="calMonthLabel" title="Jump to today">May 2026</button>
                            <button class="cal-mini-navbtn" id="calNextMonth" title="Next month" aria-label="Next month">
                                <svg class="icon" width="13" height="13"><use href="#ic-chev-right"/></svg>
                            </button>
                        </div>
                        <div class="cal-mini-grid" id="calMiniGrid"></div>
                    </div>
                    <div class="cal-agenda" id="calAgenda">
                        <div class="cal-agenda-header" id="calAgendaHeader">Today</div>
                        <div class="cal-agenda-list" id="calAgendaList">
                            <div class="cal-agenda-empty">No events</div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="folder-pane-footer">
                <div class="folder-pane-account" title="<?= h($email) ?>">
                    <svg class="icon"><use href="#ic-user"/></svg>
                    <span class="folder-pane-account-email"><?= h($email) ?></span>
                </div>
                <a class="folder-item" href="logout">
                    <svg class="icon folder-icon"><use href="#ic-logout"/></svg>
                    <span class="folder-label">Sign out</span>
                </a>
            </div>
        </aside>

        <section class="list-pane">
            <div class="list-folder-name" id="listFolderName">Inbox · — messages</div>
            <div class="list-filters" id="listFilters">
                <button class="list-filter-chip active" data-filter="all" type="button">All</button>
                <button class="list-filter-chip" data-filter="unread" type="button">Unread</button>
                <button class="list-filter-chip" data-filter="flagged" type="button">Flagged</button>
                <button class="list-filter-chip" data-filter="attachments" type="button">
                    <svg class="icon" width="12" height="12"><use href="#ic-paperclip"/></svg>
                    Attachments
                </button>
            </div>
            <div class="list-selection hidden" id="listSelection" aria-hidden="true">
                <label class="list-selection-all">
                    <input type="checkbox" id="listSelectAll">
                    <span class="list-selection-count" id="listSelectionCount">0 selected</span>
                </label>
                <button type="button" class="list-selection-close" id="listSelectionClose">Cancel</button>
            </div>
            <div class="list-items" id="messageList">
                <div class="list-loading">Loading messages…</div>
            </div>
            <div class="list-pagination" id="pagination" hidden>
                <button class="pager-btn" id="prevPage" disabled>← Prev</button>
                <span class="pager-info" id="pagerInfo">Page 1 of 1</span>
                <button class="pager-btn" id="nextPage" disabled>Next →</button>
            </div>
        </section>

        <section class="reading-pane" id="readingPane">
            <div class="reading-empty" id="readingEmpty">
                <svg class="reading-empty-icon"><use href="#ic-envelope"/></svg>
                <div class="reading-empty-text">Select a message to read</div>
            </div>

            <button class="reading-back" id="readingBackBtn" type="button" aria-label="Back to message list">
                <svg class="icon" width="18" height="18"><use href="#ic-chev-left"/></svg>
                <span>Back</span>
            </button>
            <div class="reading-content hidden" id="readingContent">
                <div class="reading-actions">
                    <button class="reading-action-btn" id="replyBtn" title="Reply" aria-label="Reply">
                        <svg class="icon"><use href="#ic-reply"/></svg>
                    </button>
                    <button class="reading-action-btn" id="replyAllBtn" title="Reply all" aria-label="Reply all">
                        <svg class="icon"><use href="#ic-reply-all"/></svg>
                    </button>
                    <button class="reading-action-btn" id="forwardBtn" title="Forward" aria-label="Forward">
                        <svg class="icon"><use href="#ic-forward"/></svg>
                    </button>
                    <button class="reading-action-btn" id="printBtn" title="Print" aria-label="Print">
                        <svg class="icon"><use href="#ic-print"/></svg>
                    </button>
                    <button class="reading-action-btn reading-action-unsub" id="unsubscribeBtn" title="Unsubscribe from this mailing list" hidden>
                        <svg class="icon"><use href="#ic-block"/></svg>
                        <span class="reading-action-label">Unsubscribe</span>
                    </button>
                </div>
                <h1 class="reading-subject" id="readSubject"></h1>
                <div class="reading-thread-summary" id="readThreadSummary" hidden></div>
                <div class="reading-thread" id="readThread"></div>
            </div>
        </section>

    </main>

</div>

<!-- Folder context menu -->
<div class="ctx-menu" id="folderCtxMenu" hidden>
    <button class="ctx-item" data-action="mark-read" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-envelope-open"/></svg>
        <span>Mark all as read</span>
    </button>
    <button class="ctx-item" data-action="new-subfolder" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-plus"/></svg>
        <span>New subfolder…</span>
    </button>
    <div class="ctx-divider ctx-divider-folder-delete"></div>
    <button class="ctx-item ctx-item-danger" data-action="delete-folder" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-trash"/></svg>
        <span>Delete folder…</span>
    </button>
</div>

<!-- Message context menu (right-click on a message) -->
<div class="ctx-menu" id="msgCtxMenu" hidden>
    <button class="ctx-item" data-msg-action="reply" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-reply"/></svg>
        <span>Reply</span>
    </button>
    <button class="ctx-item" data-msg-action="reply-all" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-reply-all"/></svg>
        <span>Reply all</span>
    </button>
    <button class="ctx-item" data-msg-action="forward" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-forward"/></svg>
        <span>Forward</span>
    </button>
    <div class="ctx-divider ctx-divider-reply"></div>
    <button class="ctx-item" data-msg-action="read-toggle" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-envelope-open"/></svg>
        <span class="ctx-read-label">Mark as unread</span>
    </button>
    <button class="ctx-item" data-msg-action="flag-toggle" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-flag"/></svg>
        <span class="ctx-flag-label">Flag</span>
    </button>
    <button class="ctx-item" data-msg-action="snooze" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-clock"/></svg>
        <span>Snooze…</span>
    </button>
    <button class="ctx-item" data-msg-action="filter-like" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-filter"/></svg>
        <span>Filter messages like this…</span>
    </button>
    <button class="ctx-item" data-msg-action="block" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-block"/></svg>
        <span>Block sender…</span>
    </button>
    <div class="ctx-divider"></div>
    <button class="ctx-item" data-msg-action="archive" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-archive"/></svg>
        <span>Archive</span>
    </button>
    <button class="ctx-item ctx-item-danger" data-msg-action="delete" type="button">
        <svg class="icon" width="14" height="14"><use href="#ic-trash"/></svg>
        <span>Delete</span>
    </button>
</div>

<!-- Attachment preview modal -->
<div class="attach-preview hidden" id="attachPreview" aria-hidden="true">
    <div class="attach-preview-backdrop" data-close="1"></div>
    <div class="attach-preview-panel" role="dialog">
        <header class="attach-preview-header">
            <div class="attach-preview-title">
                <span class="attach-preview-name" id="attachPreviewName"></span>
                <span class="attach-preview-meta" id="attachPreviewMeta"></span>
            </div>
            <div class="attach-preview-actions">
                <a class="btn btn-ghost" id="attachPreviewDownload" target="_blank" rel="noopener" download>
                    <svg class="icon" width="14" height="14"><use href="#ic-download"/></svg>
                    <span>Download</span>
                </a>
                <button class="attach-preview-close" id="attachPreviewClose" type="button" aria-label="Close" data-close="1">
                    <svg class="icon"><use href="#ic-x"/></svg>
                </button>
            </div>
        </header>
        <div class="attach-preview-body" id="attachPreviewBody"></div>
    </div>
</div>

<!-- Compose modal -->
<div class="compose-modal" id="composeModal" role="dialog" aria-label="New message">
    <div class="compose-header">
        <span class="compose-title" id="composeTitle">New Message</span>
        <div class="compose-header-actions">
            <button class="compose-hdr-btn" id="composeMinimize" type="button" aria-label="Minimize" title="Minimize">
                <svg class="icon" width="14" height="14"><use href="#ic-minimize"/></svg>
            </button>
            <button class="compose-hdr-btn" id="composeExpand" type="button" aria-label="Full screen" title="Full screen">
                <svg class="icon" width="14" height="14"><use href="#ic-expand"/></svg>
            </button>
            <button class="compose-close" id="composeClose" type="button" aria-label="Close" title="Close">
                <svg class="icon" width="14" height="14"><use href="#ic-x"/></svg>
            </button>
        </div>
    </div>
    <div class="compose-fields">
        <div class="compose-row" id="composeFromRow" hidden>
            <label class="compose-row-label" for="composeFrom">From</label>
            <select class="compose-row-input compose-row-select" id="composeFrom"></select>
        </div>
        <div class="compose-row">
            <label class="compose-row-label" for="composeTo">To</label>
            <input type="text" class="compose-row-input" id="composeTo" placeholder="recipient@example.com" autocomplete="off">
        </div>
        <div class="compose-row">
            <label class="compose-row-label" for="composeCc">Cc</label>
            <input type="text" class="compose-row-input" id="composeCc" autocomplete="off">
            <button type="button" class="compose-row-toggle" id="composeBccToggle" aria-controls="composeBccRow" aria-expanded="false">Bcc</button>
        </div>
        <div class="compose-row" id="composeBccRow" hidden>
            <label class="compose-row-label" for="composeBcc">Bcc</label>
            <input type="text" class="compose-row-input" id="composeBcc" autocomplete="off">
        </div>
        <div class="compose-row">
            <label class="compose-row-label" for="composeSubject">Subject</label>
            <input type="text" class="compose-row-input" id="composeSubject" autocomplete="off">
        </div>
    </div>
    <div class="compose-toolbar">
        <button class="compose-tool" data-cmd="bold" title="Bold" aria-label="Bold" type="button"><b>B</b></button>
        <button class="compose-tool" data-cmd="italic" title="Italic" aria-label="Italic" type="button"><i>I</i></button>
        <button class="compose-tool" data-cmd="underline" title="Underline" aria-label="Underline" type="button"><u>U</u></button>
        <button class="compose-tool" data-cmd="insertUnorderedList" title="Bullet list" aria-label="Bullet list" type="button">
            <svg class="icon" width="14" height="14"><use href="#ic-list"/></svg>
        </button>
        <span class="compose-tool-divider"></span>
        <button class="compose-tool" id="composeAttach" title="Attach file" aria-label="Attach file" type="button">
            <svg class="icon" width="14" height="14"><use href="#ic-paperclip"/></svg>
        </button>
        <input type="file" id="composeFileInput" multiple hidden>
    </div>
    <div class="compose-body" id="composeBody" contenteditable="true" data-placeholder="Write your message…"></div>
    <div class="compose-attachments" id="composeAttachmentsList"></div>
    <div class="compose-drop-overlay" id="composeDropOverlay" aria-hidden="true">
        <div class="compose-drop-overlay-inner">Drop files to attach</div>
    </div>
    <div class="compose-footer">
        <span class="compose-status" id="composeStatus"></span>
        <button class="compose-discard" id="composeDiscard" type="button" aria-label="Discard draft" title="Discard draft">
            <svg class="icon" width="16" height="16"><use href="#ic-trash"/></svg>
        </button>
        <div class="btn-send-group">
            <button class="btn-send" id="sendBtn">
                <svg class="icon" width="14" height="14"><use href="#ic-send"/></svg>
                <span class="btn-send-label">Send</span>
            </button>
            <button class="btn-send-chev" id="sendChevBtn" type="button" aria-label="Send options">
                <svg class="icon" width="11" height="11"><use href="#ic-chev-down"/></svg>
            </button>
            <div class="send-menu hidden" id="sendMenu">
                <button class="send-menu-item" type="button" data-send-action="schedule">
                    <svg class="icon" width="14" height="14"><use href="#ic-clock"/></svg>
                    <div>
                        <div class="send-menu-label">Schedule send</div>
                        <div class="send-menu-sub">Pick a date and time</div>
                    </div>
                </button>
                <div class="send-menu-divider"></div>
                <div class="send-menu-schedule" id="sendMenuSchedule">
                    <input type="datetime-local" id="sendScheduleInput" class="cal-field-input">
                    <button type="button" class="cal-modal-btn cal-modal-btn-primary" id="sendScheduleConfirm">Schedule</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Inline filter (rule) modal — shown from right-click "Filter like this" -->
<div class="cal-modal hidden" id="filterModal" aria-hidden="true">
    <div class="cal-modal-backdrop" data-filter-close="1"></div>
    <div class="cal-modal-panel" role="dialog" aria-labelledby="filterModalTitle" style="max-width:520px">
        <header class="cal-modal-header">
            <span class="cal-modal-title" id="filterModalTitle">Create filter</span>
            <button class="cal-modal-close" type="button" data-filter-close="1" aria-label="Close">
                <svg class="icon"><use href="#ic-x"/></svg>
            </button>
        </header>
        <div class="cal-modal-body">
            <div class="rule-section-label">When mail arrives matching</div>
            <label class="cal-field">
                <span class="cal-field-label">From</span>
                <input type="text" id="filterFrom" class="cal-field-input" placeholder="someone@example.com">
            </label>
            <label class="cal-field">
                <span class="cal-field-label">Subject contains</span>
                <input type="text" id="filterSubject" class="cal-field-input" placeholder="optional">
            </label>
            <label class="cal-field cal-field-check">
                <input type="checkbox" id="filterHasAttachment">
                <span>Has attachment</span>
            </label>

            <div class="rule-section-label" style="margin-top:14px">Then do</div>
            <label class="cal-field">
                <span class="cal-field-label">Move to folder</span>
                <select id="filterMoveTo" class="cal-field-input">
                    <option value="">(no folder change)</option>
                </select>
            </label>
            <label class="cal-field cal-field-check">
                <input type="checkbox" id="filterMarkRead">
                <span>Mark as read</span>
            </label>
            <label class="cal-field cal-field-check">
                <input type="checkbox" id="filterStar">
                <span>Star (flag) it</span>
            </label>
            <label class="cal-field cal-field-check">
                <input type="checkbox" id="filterApplyExisting" checked>
                <span>Also apply to existing matching messages in Inbox</span>
            </label>
            <p class="filter-modal-hint">
                More options? Visit <a href="settings#filters">Settings → Filters</a>.
            </p>
        </div>
        <footer class="cal-modal-footer">
            <span class="cal-modal-spacer"></span>
            <button class="cal-modal-btn" type="button" data-filter-close="1">Cancel</button>
            <button class="cal-modal-btn cal-modal-btn-primary" id="filterSaveBtn" type="button">Create filter</button>
        </footer>
    </div>
</div>

<!-- Calendar event modal -->
<div class="cal-modal hidden" id="calModal" aria-hidden="true">
    <div class="cal-modal-backdrop" data-cal-close="1"></div>
    <div class="cal-modal-panel" role="dialog" aria-labelledby="calModalTitle">
        <header class="cal-modal-header">
            <span class="cal-modal-title" id="calModalTitle">New event</span>
            <button class="cal-modal-close" type="button" data-cal-close="1" aria-label="Close">
                <svg class="icon"><use href="#ic-x"/></svg>
            </button>
        </header>
        <div class="cal-modal-body">
            <label class="cal-field">
                <span class="cal-field-label">Title</span>
                <input type="text" id="calEvTitle" class="cal-field-input" maxlength="200" placeholder="Add a title">
            </label>
            <label class="cal-field cal-field-check">
                <input type="checkbox" id="calEvAllDay">
                <span>All day</span>
            </label>
            <div class="cal-field-row">
                <label class="cal-field">
                    <span class="cal-field-label">Start</span>
                    <input type="datetime-local" id="calEvStart" class="cal-field-input">
                </label>
                <label class="cal-field">
                    <span class="cal-field-label">End</span>
                    <input type="datetime-local" id="calEvEnd" class="cal-field-input">
                </label>
            </div>
            <label class="cal-field">
                <span class="cal-field-label">Location</span>
                <input type="text" id="calEvLocation" class="cal-field-input" maxlength="200" placeholder="Optional">
            </label>
            <label class="cal-field">
                <span class="cal-field-label">Notes</span>
                <textarea id="calEvNotes" class="cal-field-input" rows="3" maxlength="4000" placeholder="Optional"></textarea>
            </label>
        </div>
        <footer class="cal-modal-footer">
            <button class="cal-modal-btn cal-modal-btn-danger" id="calEvDelete" type="button">Delete</button>
            <span class="cal-modal-spacer"></span>
            <button class="cal-modal-btn" type="button" data-cal-close="1">Cancel</button>
            <button class="cal-modal-btn cal-modal-btn-primary" id="calEvSave" type="button">Save</button>
        </footer>
    </div>
</div>

<!-- Keyboard shortcuts help modal -->
<div class="shortcuts-modal hidden" id="shortcutsModal" aria-hidden="true">
    <div class="shortcuts-modal-backdrop" data-shortcuts-close="1"></div>
    <div class="shortcuts-modal-panel" role="dialog" aria-labelledby="shortcutsModalTitle">
        <header class="shortcuts-modal-header">
            <span class="shortcuts-modal-title" id="shortcutsModalTitle">Keyboard shortcuts</span>
            <button class="shortcuts-modal-close" type="button" data-shortcuts-close="1" aria-label="Close">
                <svg class="icon"><use href="#ic-x"/></svg>
            </button>
        </header>
        <div class="shortcuts-modal-body">
            <section class="shortcuts-group">
                <h3>Navigation</h3>
                <div class="shortcuts-row"><kbd>j</kbd> <kbd>↓</kbd> <span>Next message</span></div>
                <div class="shortcuts-row"><kbd>k</kbd> <kbd>↑</kbd> <span>Previous message</span></div>
                <div class="shortcuts-row"><kbd>/</kbd> <span>Focus search</span></div>
            </section>
            <section class="shortcuts-group">
                <h3>Actions</h3>
                <div class="shortcuts-row"><kbd>c</kbd> <span>Compose new email</span></div>
                <div class="shortcuts-row"><kbd>r</kbd> <span>Reply</span></div>
                <div class="shortcuts-row"><kbd>a</kbd> <span>Reply all</span></div>
                <div class="shortcuts-row"><kbd>f</kbd> <span>Forward</span></div>
                <div class="shortcuts-row"><kbd>e</kbd> <span>Archive</span></div>
                <div class="shortcuts-row"><kbd>#</kbd> <kbd>⌫</kbd> <span>Delete</span></div>
                <div class="shortcuts-row"><kbd>u</kbd> <span>Mark read / unread</span></div>
                <div class="shortcuts-row"><kbd>s</kbd> <span>Flag / unflag</span></div>
            </section>
            <section class="shortcuts-group">
                <h3>Selection</h3>
                <div class="shortcuts-row"><kbd>⌘</kbd>+click <kbd>Ctrl</kbd>+click <span>Toggle row selection</span></div>
                <div class="shortcuts-row"><kbd>Shift</kbd>+click <span>Select range</span></div>
                <div class="shortcuts-row"><kbd>Esc</kbd> <span>Clear selection / close menus</span></div>
            </section>
            <section class="shortcuts-group">
                <h3>Help</h3>
                <div class="shortcuts-row"><kbd>?</kbd> <span>Show this dialog</span></div>
            </section>
        </div>
    </div>
</div>

<div class="acct-modal hidden" id="addAccountModal" role="dialog" aria-modal="true" aria-label="Add account" aria-hidden="true">
    <div class="acct-modal-backdrop" data-close="1"></div>
    <div class="acct-modal-card">
        <div class="acct-modal-header">
            <span class="acct-modal-title">Add account</span>
            <button class="acct-modal-close" id="addAccountClose" type="button" aria-label="Close">
                <svg class="icon" width="14" height="14"><use href="#ic-x"/></svg>
            </button>
        </div>
        <form class="acct-form" id="addAccountForm" autocomplete="off">
            <label class="acct-field">
                <span class="acct-field-label">Email address</span>
                <input type="email" class="acct-field-input" id="aaEmail" required placeholder="you@example.com">
            </label>
            <label class="acct-field">
                <span class="acct-field-label">Password</span>
                <input type="password" class="acct-field-input" id="aaPassword" required>
            </label>
            <button type="button" class="acct-advanced-toggle" id="aaAdvancedToggle" aria-expanded="false">Advanced settings</button>
            <div class="acct-advanced hidden" id="aaAdvanced">
                <label class="acct-field">
                    <span class="acct-field-label">Display name</span>
                    <input type="text" class="acct-field-input" id="aaName">
                </label>
                <label class="acct-field">
                    <span class="acct-field-label">IMAP host</span>
                    <input type="text" class="acct-field-input" id="aaImapHost" placeholder="mail.example.com">
                </label>
                <div class="acct-field-row">
                    <label class="acct-field acct-field-grow">
                        <span class="acct-field-label">IMAP port</span>
                        <input type="number" class="acct-field-input" id="aaImapPort" value="993">
                    </label>
                    <label class="acct-check">
                        <input type="checkbox" id="aaImapSsl" checked> SSL
                    </label>
                </div>
                <label class="acct-field">
                    <span class="acct-field-label">SMTP host</span>
                    <input type="text" class="acct-field-input" id="aaSmtpHost" placeholder="mail.example.com">
                </label>
            </div>
            <div class="acct-form-status" id="aaStatus" role="alert"></div>
            <div class="acct-form-actions">
                <button type="button" class="acct-btn-secondary" id="aaCancel">Cancel</button>
                <button type="submit" class="acct-btn-primary" id="aaSubmit">
                    <span class="aa-submit-label">Add account</span>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="app-footer">
    &copy; <?= date('Y') ?> <strong><?= h($brand['name']) ?></strong> · <?= h($brand['tagline']) ?>
</div>

<script nonce="<?= csp_nonce() ?>">
window.__CSRF__ = <?= js(csrf_token()) ?>;
window.__USER__ = {
    email: <?= js($email) ?>,
    name:  <?= js($display_name) ?>,
    initial: <?= js($initial) ?>
};
window.__PREFS__ = <?= js($prefs) ?>;
window.__ACCOUNTS__ = <?= js(account_list()) ?>;
window.__ACTIVE_ACCOUNT__ = <?= js(account_active_id()) ?>;
window.__PRIMARY_ACCOUNT__ = <?= js($_SESSION['primary_account'] ?? '') ?>;
</script>
<script src="assets/app.js?v=<?= @filemtime(__DIR__.'/assets/app.js') ?>"></script>
</body>
</html>
