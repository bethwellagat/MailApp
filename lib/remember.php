<?php
/**
 * Stay signed in.
 *
 * Every browser restart was a sign-in, because credentials live only in the
 * PHP session. This keeps a remembered device signed in for REMEMBER_DAYS
 * without ever writing the password where the server alone could read it:
 *
 *   cookie   mailapp_remember = <selector>.<secret>   (HttpOnly, SameSite=Lax,
 *            Secure on https). The secret exists in the cookie and nowhere
 *            else.
 *   record   data/remember/<sha256(selector)>.json holds the account sealed
 *            (lib/credstore.php: libsodium secretbox / AES-256-GCM) under
 *            sha256(secret). Without the cookie the record cannot be opened;
 *            without the record the cookie is worthless.
 *
 * On a request with no session and a cookie, the record is opened, the
 * session is built exactly as the login form builds it, and the secret is
 * rotated — a cookie value copied from one device stops working the first
 * time the real device uses it. A cookie whose selector exists but whose
 * secret does not open the record is treated as theft: the record is
 * revoked. Logout revokes the device; Settings → Security lists devices and
 * can forget any of them or all at once.
 */

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/credstore.php';

const REMEMBER_COOKIE  = 'mailapp_remember';
const REMEMBER_DAYS    = 30;
const REMEMBER_MAX     = 10;        // remembered devices per account; the oldest goes
const REMEMBER_FIELDS  = ['email', 'password', 'imap_host', 'imap_port', 'imap_ssl', 'smtp_host', 'display_name'];

function remember_dir()            { return app_data_root() . '/remember'; }
function remember_path($selector)  { return remember_dir() . '/' . hash('sha256', (string)$selector) . '.json'; }

/* ---------- the token: pure ---------- */

function remember_token_make() {
    return ['selector' => bin2hex(random_bytes(12)), 'secret' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')];
}
function remember_token_string(array $t) { return $t['selector'] . '.' . $t['secret']; }
function remember_token_parse($cookie) {
    if (!is_string($cookie) || !preg_match('/^([a-f0-9]{24})\.([A-Za-z0-9_-]{40,48})$/', $cookie, $m)) return null;
    return ['selector' => $m[1], 'secret' => $m[2]];
}
/** The sealing key: the secret, hashed. 32 bytes, derived on every use, never stored. */
function remember_key($secret) { return hash('sha256', (string)$secret, true); }

/** "Chrome on macOS" from a User-Agent, for the devices list. Pure, never the raw string. */
function remember_ua_hint($ua) {
    $ua = (string)$ua;
    $browser = 'Browser';
    if (preg_match('/Edg(e|A|iOS)?\//', $ua))            $browser = 'Edge';
    elseif (preg_match('/OPR\/|Opera/', $ua))             $browser = 'Opera';
    elseif (preg_match('/Firefox\/|FxiOS/', $ua))         $browser = 'Firefox';
    elseif (preg_match('/CriOS\/|Chrome\//', $ua))        $browser = 'Chrome';
    elseif (preg_match('/Safari\//', $ua) && preg_match('/Version\//', $ua)) $browser = 'Safari';
    $os = 'a device';
    if (preg_match('/iPhone/', $ua))             $os = 'iPhone';
    elseif (preg_match('/iPad/', $ua))           $os = 'iPad';
    elseif (preg_match('/Android/', $ua))        $os = 'Android';
    elseif (preg_match('/Windows/', $ua))        $os = 'Windows';
    elseif (preg_match('/Mac OS X|Macintosh/', $ua)) $os = 'macOS';
    elseif (preg_match('/CrOS/', $ua))           $os = 'ChromeOS';
    elseif (preg_match('/Linux/', $ua))          $os = 'Linux';
    return $browser . ' on ' . $os;
}

/* ---------- records ---------- */

function remember_read($selector) {
    $f = remember_path($selector);
    $r = is_file($f) ? @json_decode((string)@file_get_contents($f), true) : null;
    return (is_array($r) && !empty($r['selector']) && !empty($r['sealed'])) ? $r : null;
}
function remember_write(array $rec) {
    $dir = remember_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    $ok = (bool)atomic_write_json(remember_path($rec['selector']), $rec);
    if ($ok) @chmod(remember_path($rec['selector']), 0600);
    return $ok;
}
function remember_revoke($selector) {
    $f = remember_path($selector);
    return is_file($f) ? @unlink($f) : false;
}
/** Every record for an account (expired ones removed on the way), newest first. */
function remember_records($email, $now = null) {
    $now = $now ?? time();
    $out = [];
    foreach ((array)@glob(remember_dir() . '/*.json') as $f) {
        $r = @json_decode((string)@file_get_contents($f), true);
        if (!is_array($r) || empty($r['selector'])) continue;
        if ((int)($r['expires'] ?? 0) <= $now) { @unlink($f); continue; }
        if (strcasecmp((string)($r['email'] ?? ''), (string)$email) !== 0) continue;
        $out[] = $r;
    }
    usort($out, fn($a, $b) => (int)$b['created'] - (int)$a['created']);
    return $out;
}

/**
 * Remember this device: seal the account under a fresh secret, keep the
 * record, return the cookie value. $acct holds REMEMBER_FIELDS.
 */
function remember_issue(array $acct, $ua = '', $now = null) {
    $now = $now ?? time();
    if (cred_backend() === null) return null;
    $t   = remember_token_make();
    $data = [];
    foreach (REMEMBER_FIELDS as $k) $data[$k] = $acct[$k] ?? null;
    if (empty($data['email']) || empty($data['password'])) return null;
    $sealed = cred_seal($data, remember_key($t['secret']));
    if (!$sealed) return null;
    // One device too many: the oldest is forgotten.
    $existing = remember_records($data['email'], $now);
    while (count($existing) >= REMEMBER_MAX) { $old = array_pop($existing); remember_revoke($old['selector']); }
    $rec = ['selector' => $t['selector'], 'email' => (string)$data['email'], 'created' => $now, 'last_used' => $now,
            'expires' => $now + REMEMBER_DAYS * 86400, 'ua' => remember_ua_hint($ua), 'sealed' => $sealed];
    if (!remember_write($rec)) return null;
    return ['cookie' => remember_token_string($t), 'selector' => $t['selector'], 'expires' => $rec['expires']];
}

/**
 * Open a cookie's record. The account on success; null otherwise. A record
 * that exists but will not open with this secret is revoked on the spot.
 */
function remember_open(array $token, $now = null) {
    $now = $now ?? time();
    $rec = remember_read($token['selector']);
    if (!$rec) return null;
    if ((int)($rec['expires'] ?? 0) <= $now) { remember_revoke($token['selector']); return null; }
    $acct = cred_open($rec['sealed'], remember_key($token['secret']));
    if (!is_array($acct) || empty($acct['email'])) {
        remember_revoke($token['selector']);
        app_log('warn', 'remember: a cookie with a valid selector but the wrong secret; device revoked', ['account' => $rec['email'] ?? '']);
        return null;
    }
    return ['account' => $acct, 'record' => $rec];
}

/** A new secret for the same device: re-seal, keep the selector, return the new cookie value. */
function remember_rotate(array $rec, array $acct, $ua = '', $now = null) {
    $now = $now ?? time();
    $secret = remember_token_make()['secret'];
    $data = [];
    foreach (REMEMBER_FIELDS as $k) $data[$k] = $acct[$k] ?? null;
    $sealed = cred_seal($data, remember_key($secret));
    if (!$sealed) return null;
    $rec['sealed']    = $sealed;
    $rec['last_used'] = $now;
    if ($ua !== '') $rec['ua'] = remember_ua_hint($ua);
    if (!remember_write($rec)) return null;
    return remember_token_string(['selector' => $rec['selector'], 'secret' => $secret]);
}

function remember_revoke_all($email, $now = null) {
    $n = 0;
    foreach (remember_records($email, $now) as $r) if (remember_revoke($r['selector'])) $n++;
    return $n;
}

/** What Settings shows: never the selector itself, only enough to recognise and forget a device. */
function remember_list($email, $currentSelector = '', $now = null) {
    $out = [];
    foreach (remember_records($email, $now) as $r) {
        $out[] = ['id' => substr(hash('sha256', $r['selector']), 0, 16), 'device' => (string)($r['ua'] ?? ''), 'created' => (int)$r['created'],
                  'last_used' => (int)$r['last_used'], 'expires' => (int)$r['expires'], 'current' => ($r['selector'] === $currentSelector)];
    }
    return $out;
}
/** Resolve a list id back to a selector (the page never sees selectors). */
function remember_selector_for_id($email, $id) {
    foreach (remember_records($email) as $r) if (substr(hash('sha256', $r['selector']), 0, 16) === (string)$id) return $r['selector'];
    return null;
}

/* ---------- the cookie and the session ---------- */

function remember_https() {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '') === 'on');
}
function remember_set_cookie($value, $expires) {
    if (headers_sent()) return false;
    return setcookie(REMEMBER_COOKIE, $value, ['expires' => (int)$expires, 'path' => '/', 'secure' => remember_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function remember_clear_cookie() {
    if (headers_sent()) return false;
    return setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 86400, 'path' => '/', 'secure' => remember_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

/**
 * Called by session_boot() when there is no signed-in session: a remembered
 * device signs in again, the way the login form does, and gets a fresh
 * secret. Returns true when it signed someone in.
 */
function remember_boot() {
    if (!empty($_SESSION['email'])) return false;
    $cookie = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($cookie === '') return false;
    $t = remember_token_parse($cookie);
    if (!$t) { remember_clear_cookie(); return false; }
    $r = remember_open($t);
    if (!$r) { remember_clear_cookie(); return false; }
    $acct = $r['account'];
    session_regenerate_id(true);
    foreach (REMEMBER_FIELDS as $k) $_SESSION[$k] = $acct[$k] ?? null;
    $_SESSION['login_time']        = time();
    $_SESSION['last_seen']         = time();
    $_SESSION['remember_selector'] = $t['selector'];
    require_once __DIR__ . '/prefs.php';
    $saved = load_prefs((string)$acct['email']);
    if (!empty($saved['display_name'])) $_SESSION['display_name'] = $saved['display_name'];
    $new = remember_rotate($r['record'], $acct, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($new) remember_set_cookie($new, (int)$r['record']['expires']);
    app_log('info', 'remember: signed in from a remembered device', ['account' => $acct['email'], 'device' => remember_ua_hint((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))]);
    return true;
}

/** Logout: this device is forgotten, whichever way it was remembered. */
function remember_forget_current() {
    $sel = (string)($_SESSION['remember_selector'] ?? '');
    if ($sel === '') { $t = remember_token_parse((string)($_COOKIE[REMEMBER_COOKIE] ?? '')); $sel = $t ? $t['selector'] : ''; }
    if ($sel !== '') remember_revoke($sel);
    remember_clear_cookie();
    unset($_SESSION['remember_selector']);
    return $sel !== '';
}
