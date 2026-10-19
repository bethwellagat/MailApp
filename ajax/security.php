<?php
/**
 * Security — remembered devices.
 *   GET  ?action=devices            the account's remembered devices
 *   POST action=remember_this       remember the device making this request
 *   POST action=forget   id=…       forget one device
 *   POST action=forget_all          forget every device (this session stays signed in)
 */
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../lib/util.php';
json_fatal_guard();

if (empty($_SESSION['email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();
require_once __DIR__ . '/../lib/remember.php';

$email  = (string)$_SESSION['email'];
$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'devices');
$cur    = (string)($_SESSION['remember_selector'] ?? '');

function sec_ok($d)  { echo json_encode($d, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function sec_err($m, $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }
function sec_payload($email, $cur) {
    return ['ok' => true, 'devices' => remember_list($email, $cur), 'this_device' => $cur !== '' && remember_read($cur) !== null, 'backend' => cred_backend()];
}

if ($action === 'devices') sec_ok(sec_payload($email, $cur));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sec_err('POST required', 405);

if ($action === 'remember_this') {
    if (cred_backend() === null) sec_err('This server\'s PHP has neither libsodium nor OpenSSL, so a device cannot be remembered.', 500);
    if ($cur !== '' && remember_read($cur)) sec_ok(sec_payload($email, $cur));
    $issued = remember_issue($_SESSION, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if (!$issued) sec_err('Could not remember this device — is the data folder writable?', 500);
    remember_set_cookie($issued['cookie'], $issued['expires']);
    $_SESSION['remember_selector'] = $issued['selector'];
    app_log('info', 'remember: device remembered from Settings', ['account' => $email]);
    sec_ok(sec_payload($email, $issued['selector']));
}

if ($action === 'forget') {
    $sel = remember_selector_for_id($email, (string)($_POST['id'] ?? ''));
    if (!$sel) sec_err('That device is no longer remembered.', 404);
    remember_revoke($sel);
    if ($sel === $cur) { remember_clear_cookie(); unset($_SESSION['remember_selector']); $cur = ''; }
    sec_ok(sec_payload($email, $cur));
}

if ($action === 'forget_all') {
    remember_revoke_all($email);
    remember_clear_cookie();
    unset($_SESSION['remember_selector']);
    app_log('info', 'remember: every device forgotten', ['account' => $email]);
    sec_ok(sec_payload($email, ''));
}

sec_err('Unknown action', 400);
