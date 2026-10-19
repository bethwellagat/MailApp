<?php
/**
 * Templates — list, save, delete, and render one for the compose window.
 *
 *   GET  ?action=list                       the account's templates
 *   POST action=save    (JSON body)         {id?, name, subject, body}
 *   POST action=delete  id=…
 *   POST action=render  id=…&to=…&subject=… the template with the recipient's
 *                                           details filled in, ready to insert
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
require_once __DIR__ . '/../lib/templates.php';

$email  = (string)$_SESSION['email'];
$myName = (string)($_SESSION['display_name'] ?? '');
session_write_close();

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');
function tpl_ok($d)  { echo json_encode($d, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function tpl_err($m, $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }

if ($action === 'list') {
    tpl_ok(['ok' => true, 'templates' => templates_list($email)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') tpl_err('POST required', 405);

if ($action === 'save') {
    $raw = (string)file_get_contents('php://input');
    $in  = json_decode($raw, true);
    if (!is_array($in)) $in = $_POST;
    $r = template_save($email, $in);
    if (!empty($r['error'])) tpl_err($r['error']);
    tpl_ok(['ok' => true, 'template' => $r, 'templates' => templates_list($email)]);
}

if ($action === 'delete') {
    if (!template_delete($email, (string)($_POST['id'] ?? ''))) tpl_err('That template no longer exists.', 404);
    tpl_ok(['ok' => true, 'templates' => templates_list($email)]);
}

if ($action === 'render') {
    $t = template_get($email, (string)($_POST['id'] ?? ''));
    if (!$t) tpl_err('That template no longer exists.', 404);
    $vars = template_vars_for((string)($_POST['to'] ?? ''), (string)($_POST['subject'] ?? ''), $myName, $email);
    tpl_ok(['ok' => true, 'name' => $t['name'], 'subject' => template_render_text($t['subject'], $vars), 'body' => template_render($t['body'], $vars)]);
}

tpl_err('Unknown action', 400);
