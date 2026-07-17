<?php
require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../lib/prefs.php';

if (empty($_SESSION['email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();
session_write_close(); // release the session lock early — avoids request serialization (see fetch.php)

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['ok' => true, 'prefs' => load_prefs($_SESSION['email'])]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$updates = [];

if (isset($_POST['signature'])) {
    $rawSig = (string)$_POST['signature'];
    if (strlen($rawSig) > SIGNATURE_MAX_BYTES) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Signature is too large (' . round(strlen($rawSig) / 1024) . ' KB). Try a smaller image — max ~1 MB total.',
        ]);
        exit;
    }
    $updates['signature'] = sanitize_signature_html($rawSig);
}

if (isset($_POST['auto_append'])) {
    $updates['auto_append'] = !empty($_POST['auto_append']);
}

if (isset($_POST['append_on_replies'])) {
    $updates['append_on_replies'] = !empty($_POST['append_on_replies']);
}

if (isset($_POST['density'])) {
    $d = (string)$_POST['density'];
    if (!in_array($d, ['comfortable', 'cozy', 'compact'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid density value']);
        exit;
    }
    $updates['density'] = $d;
}

if (isset($_POST['theme'])) {
    $t = (string)$_POST['theme'];
    if (!in_array($t, ['system', 'light', 'dark'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid theme value']);
        exit;
    }
    $updates['theme'] = $t;
}

if (isset($_POST['notifications'])) {
    $updates['notifications'] = filter_var($_POST['notifications'], FILTER_VALIDATE_BOOLEAN);
}

if (isset($_POST['display_name'])) {
    // This becomes the From-name on every message the user sends, so it must not
    // carry CR/LF (header injection) or control characters. Collapse whitespace,
    // trim, and cap the length. Empty is allowed — it means "send with the bare
    // address" (send.php falls back to the address when the name is blank).
    $dn = (string)$_POST['display_name'];
    $dn = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $dn); // strip CR/LF/TAB/other control
    $dn = trim(preg_replace('/\s+/', ' ', $dn));
    $dn = mb_substr($dn, 0, 100);
    $updates['display_name'] = $dn;

    // Mirror into the live session so the next Send uses the new name without a
    // re-login. prefs.php closed the session early for concurrency (line 18);
    // reopen just long enough to write the effective account's name, then release.
    if (@session_start()) {
        $effId = account_effective_id();
        if ($effId !== null && isset($_SESSION['accounts'][$effId]) && is_array($_SESSION['accounts'][$effId])) {
            $_SESSION['accounts'][$effId]['display_name'] = $dn;
        }
        $_SESSION['display_name'] = $dn;
        session_write_close();
    }
}

if (isset($_POST['workspace_logo'])) {
    $rawLogo = (string)$_POST['workspace_logo'];
    if (strlen($rawLogo) > LOGO_MAX_BYTES) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Logo is too large (' . round(strlen($rawLogo) / 1024) . ' KB). Try a smaller image — max ~512 KB.',
        ]);
        exit;
    }
    $cleaned = sanitize_logo_data_uri($rawLogo);
    if ($cleaned === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Logo must be a PNG, JPEG, GIF, or WebP image.']);
        exit;
    }
    $updates['workspace_logo'] = $cleaned;
}

if (empty($updates)) {
    http_response_code(400);
    echo json_encode(['error' => 'No fields to update']);
    exit;
}

$ok = save_prefs($_SESSION['email'], $updates);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save preferences. Check server permissions on data/prefs/.']);
    exit;
}

echo json_encode(['ok' => true]);
