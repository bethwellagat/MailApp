<?php
/**
 * The registry, from the inside: the fleet list, the update button, and the
 * setting that points this install at a registry.
 *
 *   GET  ?action=status          what this install is (registry? reporting?) and the fleet
 *   POST action=become_registry  make this install the registry
 *   POST action=stop_registry    stop being one (records are kept)
 *   POST action=report_to        url + secret + allow-remote-updates for THIS install
 *   POST action=stop_reporting   forget the registry it reports to
 *   POST action=update_all       ask every install to update
 *   POST action=update_one       ask one install to update
 *   POST action=forget           drop one install's record
 *   POST action=checkin_now      report in immediately (so the page is not a lie)
 *
 * Every write is the admin's: this is the one place in the app where one
 * install can set another in motion.
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
require_once __DIR__ . '/../lib/registry.php';
require_once __DIR__ . '/../lib/updater.php';
require_once __DIR__ . '/../lib/cron.php';

$me      = (string)$_SESSION['email'];
$isAdmin = app_is_admin($me);
$action  = (string)($_GET['action'] ?? $_POST['action'] ?? 'status');

function reg_ok($d)  { echo json_encode($d, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function reg_err($m, $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }
function reg_admin_only($isAdmin) { if (!$isAdmin) reg_err('Only ' . (app_admin_email() ?: 'the administrator') . ' can manage installations.', 403); }

/** This install's own URL for registry.php — the line other installs need. */
function reg_self_url() {
    $base = function_exists('cron_request_base_url') ? cron_request_base_url() : '';
    if ($base === '') {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $base  = ($https ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return rtrim($base, '/') . '/registry.php';
}

function reg_payload($isAdmin) {
    $server = registry_server();
    $client = registry_client();
    $fleet  = $server ? registry_all() : ['newest' => '', 'installs' => []];
    return [
        'ok'          => true,
        'is_admin'    => $isAdmin,
        'admin_email' => app_admin_email(),
        'is_registry' => $server !== null,
        'secret'      => ($server && $isAdmin) ? $server['secret'] : '',
        'url'         => reg_self_url(),
        'update_at'   => $server ? (int)$server['update_at'] : 0,
        'reporting'   => registry_configured($client),
        'report_url'  => $isAdmin ? (string)$client['url'] : '',
        'auto_update' => !empty($client['auto_update']),
        'last_checkin'=> (int)$client['last_checkin'],
        'last_error'  => (string)$client['last_error'],
        'last_result' => (string)$client['last_result'],
        'install_id'  => registry_configured($client) ? registry_install_id() : '',
        'here'        => ['version' => update_app_version()['version'] ?? '', 'php' => PHP_VERSION],
        'fleet'       => $fleet,
    ];
}

if ($action === 'status') reg_ok(reg_payload($isAdmin));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reg_err('POST required', 405);
reg_admin_only($isAdmin);

if ($action === 'become_registry') {
    if (!registry_server_enable($me)) reg_err('Could not write the registry key — is the data folder writable?', 500);
    app_log('info', 'registry: this install is now the registry', ['by' => $me]);
    reg_ok(reg_payload($isAdmin));
}

if ($action === 'stop_registry') {
    registry_server_disable();
    app_log('info', 'registry: this install is no longer the registry', ['by' => $me]);
    reg_ok(reg_payload($isAdmin));
}

if ($action === 'report_to') {
    $url    = trim((string)($_POST['url'] ?? ''));
    $secret = trim((string)($_POST['secret'] ?? ''));
    // https everywhere, because the key travels with the request — except on a
    // loopback or .test host, where there is no wire to listen on.
    $local = preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\]|[A-Za-z0-9.-]+\.(localhost|test))(:\d{1,5})?/#', $url) === 1;
    if (!preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+(:\d{1,5})?/[\w./-]*registry\.php$#', $url)
        || (strpos($url, 'https://') !== 0 && !$local)) {
        reg_err('That is not a registry address. It looks like https://your-domain/registry.php — and it must be https.');
    }
    if (!preg_match('/^[a-f0-9]{24,64}$/', $secret)) reg_err('That key does not look like the one the registry shows.');
    $c = registry_client();
    $c['url']         = $url;
    $c['secret']      = $secret;
    $c['auto_update'] = !empty($_POST['auto_update']);
    $c['last_error']  = '';
    if (!registry_client_save($c)) reg_err('Could not save — is the data folder writable?', 500);
    $r = registry_checkin(true);        // prove it works now, rather than in a quarter of an hour
    app_log('info', 'registry: this install now reports in', ['by' => $me, 'url' => $url]);
    reg_ok(reg_payload($isAdmin) + ['checkin' => $r]);
}

if ($action === 'stop_reporting') {
    $c = registry_client();
    $c['url'] = ''; $c['secret'] = ''; $c['last_error'] = '';
    registry_client_save($c);
    reg_ok(reg_payload($isAdmin));
}

if ($action === 'checkin_now') {
    if (!registry_configured()) reg_err('This install does not report to a registry.', 400);
    reg_ok(reg_payload($isAdmin) + ['checkin' => registry_checkin(true)]);
}

if ($action === 'update_all' || $action === 'update_one') {
    if (!registry_server()) reg_err('This install is not the registry.', 400);
    $id = $action === 'update_one' ? (string)($_POST['id'] ?? '') : '';
    if ($action === 'update_one' && !registry_record($id)) reg_err('That installation is no longer listed.', 404);
    $at = registry_request_update($id, $me);
    if (!$at) reg_err('Could not record the request.', 500);
    app_log('info', 'registry: update requested', ['by' => $me, 'scope' => $id === '' ? 'every install' : $id]);
    // This install is part of its own fleet: act on it here rather than making
    // the admin wonder why the registry itself stayed behind.
    if (registry_configured()) registry_checkin(true);
    reg_ok(reg_payload($isAdmin) + ['requested_at' => $at]);
}

if ($action === 'forget') {
    $id = (string)($_POST['id'] ?? '');
    $rec = registry_record($id);
    if (!$rec) reg_err('That installation is no longer listed.', 404);
    @unlink(registry_record_path($rec['install_id']));
    reg_ok(reg_payload($isAdmin));
}

reg_err('Unknown action', 400);
