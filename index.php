<?php
require_once __DIR__ . '/lib/session.php'; session_boot();
csp_header();

if (!empty($_SESSION['email']) && !empty($_SESSION['imap_host'])) {
    header('Location: inbox');
    exit;
}

$error = '';
$email = '';
$advanced_open = false;
$prefill = [
    'imap_host' => '',
    'imap_port' => 993,
    'imap_ssl'  => true,
    'smtp_host' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email        = trim($_POST['email'] ?? '');
    $password     = (string)($_POST['password'] ?? '');
    $imap_host    = trim($_POST['imap_host'] ?? '');
    $imap_port    = (int)($_POST['imap_port'] ?? 993);
    $imap_ssl     = isset($_POST['imap_ssl']);
    $smtp_host    = trim($_POST['smtp_host'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');

    if (!$imap_port) {
        $imap_port = $imap_ssl ? 993 : 143;
    }

    if ($imap_host === '' && strpos($email, '@') !== false) {
        $domain = substr($email, strpos($email, '@') + 1);
        $imap_host = 'mail.' . $domain;
    }
    if ($smtp_host === '') {
        $smtp_host = preg_replace('/^imap\./i', 'mail.', $imap_host);
    }
    if ($display_name === '' && strpos($email, '@') !== false) {
        $display_name = substr($email, 0, strpos($email, '@'));
    }

    $prefill = [
        'imap_host' => $imap_host,
        'imap_port' => $imap_port,
        'imap_ssl'  => $imap_ssl,
        'smtp_host' => $smtp_host,
    ];
    $advanced_open = !empty($_POST['imap_host']) || !empty($_POST['smtp_host']);

    if ($email === '' || $password === '') {
        $error = 'Please enter your email address and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address does not look valid.';
    } elseif (!function_exists('imap_open')) {
        $error = 'PHP IMAP extension is not enabled on this server. Contact your host.';
    } elseif (($throttleWait = login_throttle_retry_after($email)) > 0) {
        $error = 'Too many sign-in attempts. Please wait ' . ceil($throttleWait / 60) . ' minute(s) before trying again.';
    } else {
        $flags = $imap_ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls';
        $mailbox = '{' . $imap_host . ':' . $imap_port . $flags . '}INBOX';

        $mbox = @imap_open($mailbox, $email, $password, OP_HALFOPEN, 1);

        if ($mbox === false) {
            login_throttle_record($email, false);
            $msgs = imap_errors() ?: [];
            $last = $msgs ? end($msgs) : 'Could not connect to mail server.';
            $clean = $last;
            if (stripos($last, 'authentication') !== false || stripos($last, 'invalid') !== false || stripos($last, 'AUTH') !== false) {
                $clean = 'Incorrect email or password.';
            } elseif (stripos($last, 'unable to') !== false || stripos($last, 'connection') !== false || stripos($last, 'refused') !== false) {
                $clean = 'Could not reach IMAP server at ' . $imap_host . ':' . $imap_port . '. Check the host or use Advanced settings.';
            }
            $error = $clean;
        } else {
            @imap_close($mbox);
            imap_errors();
            imap_alerts();

            login_throttle_record($email, true);
            session_regenerate_id(true);
            $_SESSION['email']        = $email;
            $_SESSION['password']     = $password;
            $_SESSION['imap_host']    = $imap_host;
            $_SESSION['imap_port']    = $imap_port;
            $_SESSION['imap_ssl']     = $imap_ssl;
            $_SESSION['smtp_host']    = $smtp_host;
            $_SESSION['display_name'] = $display_name;
            $_SESSION['login_time']   = time();
            $_SESSION['last_seen']    = time();

            // A display name saved in Settings is the source of truth: prefer it
            // over whatever was typed on the login form, so the choice survives
            // logout/login. (The login field only seeds the very first sign-in,
            // before any preference exists.)
            require_once __DIR__ . '/lib/prefs.php';
            $savedPrefs = load_prefs($email);
            if (!empty($savedPrefs['display_name'])) {
                $_SESSION['display_name'] = $savedPrefs['display_name'];
            }

            header('Location: inbox');
            exit;
        }
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

require_once __DIR__ . '/lib/brand.php';
$brand       = resolve_brand();
$brandDomain = $brand['domain'];
$brandLabel  = $brand['name'] . ' WorkSpace';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script nonce="<?= csp_nonce() ?>">
/* No saved theme at the login screen — follow the OS setting, before first paint. */
(function(){try{
  var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  document.documentElement.classList.add(dark ? 'theme-dark' : 'theme-light');
}catch(e){}})();
</script>
<title>Sign in · <?= h($brandLabel) ?></title>
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
/* PWA on the login screen:
   1. Register the service worker so the app is installable before sign-in.
   2. Reaching this (unauthenticated) page means no session is active — logout,
      expiry, or a different user on a shared browser. Wipe any cached mail JSON
      so previously-read messages can never be read by the next person. Static
      assets and the offline shell are kept so install/offline still work. */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.js').catch(function () {});
  });
}
if ('caches' in window) {
  caches.keys().then(function (names) {
    names.forEach(function (n) { if (n.indexOf('-msg-') !== -1) caches.delete(n); });
  }).catch(function () {});
  if (navigator.serviceWorker && navigator.serviceWorker.controller) {
    navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_MESSAGE_CACHE' });
  }
}
</script>
</head>
<body class="login-page">
<div class="login-shell">

    <form class="login-card" method="post" autocomplete="on" novalidate>
        <header class="login-brand">
            <span class="login-brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <polyline points="3 7 12 13 21 7"/>
                </svg>
            </span>
            <span class="login-brand-text">
                <span class="login-brand-domain"><?= h($brand['name']) ?></span>
                <span class="login-brand-tag">WorkSpace</span>
            </span>
        </header>

        <div class="login-intro">
            <h1>Welcome back</h1>
            <p>Sign in to access your secure mailbox.</p>
        </div>

        <?php if ($error): ?>
            <div class="error-banner" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= h($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="form-field">
            <label class="form-label" for="email">Email address</label>
            <div class="input-shell">
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><circle cx="12" cy="12" r="4"/><path d="M16 12v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-3.6 7.2"/></svg>
                <input class="form-input" type="email" id="email" name="email" required autofocus
                       autocomplete="username" inputmode="email"
                       value="<?= h($email) ?>" placeholder="you@yourdomain.com">
            </div>
        </div>

        <div class="form-field">
            <label class="form-label" for="password">Password</label>
            <div class="input-shell">
                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <input class="form-input" type="password" id="password" name="password" required
                       autocomplete="current-password" placeholder="••••••••">
                <button type="button" class="input-toggle" id="pwToggle" aria-label="Show password">
                    <svg id="eyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg id="eyeOff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" hidden><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a19.49 19.49 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a19.49 19.49 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                </button>
            </div>
        </div>

        <button type="button" class="advanced-toggle <?= $advanced_open ? 'open' : '' ?>" id="advancedToggle">
            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><polyline points="9 6 15 12 9 18"/></svg>
            <span>Advanced settings</span>
        </button>

        <div class="advanced-fields <?= $advanced_open ? 'open' : '' ?>" id="advancedFields">
            <div class="form-field">
                <label class="form-label" for="imap_host">IMAP host</label>
                <div class="input-shell">
                    <input class="form-input" type="text" id="imap_host" name="imap_host"
                           value="<?= h($prefill['imap_host']) ?>" placeholder="auto-detect from email">
                </div>
            </div>
            <div class="advanced-row">
                <div class="form-field" style="flex:1;">
                    <label class="form-label" for="imap_port">IMAP port</label>
                    <div class="input-shell">
                        <input class="form-input" type="number" id="imap_port" name="imap_port"
                               value="<?= h($prefill['imap_port']) ?>" min="1" max="65535">
                    </div>
                </div>
                <label class="checkbox-row" style="align-self:flex-end;padding-bottom:9px;">
                    <input type="checkbox" name="imap_ssl" value="1" <?= $prefill['imap_ssl'] ? 'checked' : '' ?>>
                    <span>SSL</span>
                </label>
            </div>
            <div class="form-field">
                <label class="form-label" for="smtp_host">SMTP host</label>
                <div class="input-shell">
                    <input class="form-input" type="text" id="smtp_host" name="smtp_host"
                           value="<?= h($prefill['smtp_host']) ?>" placeholder="defaults to IMAP host">
                </div>
            </div>
            <div class="form-field" style="margin-bottom:0;">
                <label class="form-label" for="display_name">Display name</label>
                <div class="input-shell">
                    <input class="form-input" type="text" id="display_name" name="display_name"
                           placeholder="Your name (shown in outgoing mail)">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" id="submitBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" class="btn-icon"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span class="btn-label">Sign in securely</span>
        </button>

        <div class="login-secure">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
            <span>Credentials are never stored. Session-only authentication.</span>
        </div>
    </form>

    <footer class="login-page-footer">
        <span>&copy; <?= date('Y') ?> <strong><?= h($brand['name']) ?></strong></span>
        <span aria-hidden="true">·</span>
        <span><?= h($brand['tagline']) ?></span>
    </footer>

</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
    var toggle = document.getElementById('advancedToggle');
    var fields = document.getElementById('advancedFields');
    toggle.addEventListener('click', function () {
        toggle.classList.toggle('open');
        fields.classList.toggle('open');
    });

    var pw      = document.getElementById('password');
    var pwBtn   = document.getElementById('pwToggle');
    var eyeOpen = document.getElementById('eyeOpen');
    var eyeOff  = document.getElementById('eyeOff');
    pwBtn.addEventListener('click', function () {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        eyeOpen.hidden = show;
        eyeOff.hidden  = !show;
        pwBtn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        pw.focus();
    });

    var form = document.querySelector('.login-card');
    var btn  = document.getElementById('submitBtn');
    form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.querySelector('.btn-label').textContent = 'Signing in…';
    });
})();
</script>
</body>
</html>
