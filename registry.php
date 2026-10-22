<?php
/**
 * registry.php — where the other installs report in.
 *
 * Inert unless this install has been made the registry (Settings →
 * Installations writes data/registry-server.json). Then it accepts a signed
 * POST from an install, stores what it said, and answers — signed in return —
 * whether a fleet update has been asked for.
 *
 * Nothing here opens a session, reads mail, or holds a credential for anyone
 * else's server: the registry only ever answers questions.
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/lib/util.php';
json_fatal_guard();
require_once __DIR__ . '/lib/log.php';
require_once __DIR__ . '/lib/registry.php';

$server = registry_server();
if (!$server) {
    // One answer for "not a registry" and "wrong secret": a stranger learns nothing by asking.
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = (string)file_get_contents('php://input');
if (strlen($body) > 8192) { http_response_code(413); echo json_encode(['error' => 'Too large']); exit; }

$ts  = (int)($_SERVER['HTTP_X_REGISTRY_TIME'] ?? 0);
$sig = (string)($_SERVER['HTTP_X_REGISTRY_SIG'] ?? '');
if (!registry_sig_ok($body, $server['secret'], $ts, $sig)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$payload = @json_decode($body, true);
if (!is_array($payload) || empty($payload['install_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad payload']);
    exit;
}

$rec = registry_store($payload);
if (!$rec) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not record this install']);
    exit;
}

// Signed, so that only this registry can ask an install to run its updater.
$reply = registry_reply_for($server, $rec);
$canon = json_encode($reply, JSON_UNESCAPED_SLASHES);
$reply['sig'] = registry_sign($canon, $server['secret'], $reply['ts']);
echo json_encode($reply, JSON_UNESCAPED_SLASHES);
