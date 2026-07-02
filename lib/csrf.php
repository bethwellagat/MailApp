<?php
/**
 * Per-session CSRF protection.
 *
 * A random token is minted once per session (lazily, the first time a page
 * asks for it) and required on every state-changing request. The browser
 * echoes it back via the X-CSRF-Token header (preferred) or a `csrf` form
 * field — the latter so multipart/form-data sends (attachments) can carry it
 * without a custom header.
 *
 * We deliberately never read php://input here: several AJAX handlers consume
 * the raw JSON request body themselves, and reading the stream twice would
 * leave them with nothing. The header/field channels are sufficient.
 *
 * Requires an already-started session (every caller runs session_start()
 * before including this file).
 */

function csrf_token() {
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_provided_token() {
    $h = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($h) && $h !== '') return $h;
    $p = $_POST['csrf'] ?? '';
    return is_string($p) ? $p : '';
}

function csrf_verify() {
    $stored = $_SESSION['csrf'] ?? '';
    $given  = csrf_provided_token();
    if (!is_string($stored) || $stored === '' || $given === '') return false;
    return hash_equals($stored, $given);
}

/** Abort with 403 JSON unless a valid token accompanies the request. */
function csrf_require() {
    if (!csrf_verify()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid or missing security token. Reload the page and try again.']);
        exit;
    }
}
