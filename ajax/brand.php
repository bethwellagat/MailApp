<?php
/**
 * Workspace branding — read and write data/brand.json.
 *
 * SCOPE: this is INSTALL-WIDE, not per-user. resolve_brand() reads the same file
 * for the sign-in page (where nobody is logged in yet) and for the PWA manifest,
 * so a change here is visible to every account on this domain. That is the point
 * — it is the white-label identity of the deployment, not a personal preference.
 * The workspace LOGO is deliberately not handled here: that one is per-user and
 * already lives in prefs.
 *
 * WHO MAY WRITE: the same gate the updater uses. If data/update.json sets
 * "admin_email", only that address may save; otherwise any signed-in user may,
 * which keeps a fresh install zero-config. One admin concept, not two.
 *
 * Every field is validated before it is written, because resolve_brand() feeds
 * page titles, a <meta> tag and the manifest JSON. Anything stored here must be
 * safe in all three.
 */

require_once __DIR__ . '/../lib/session.php'; session_boot();
require_once __DIR__ . '/../lib/accounts.php';
accounts_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../lib/brand.php';
require_once __DIR__ . '/../lib/util.php';

if (empty($_SESSION['email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../lib/csrf.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$email = (string)$_SESSION['email'];
session_write_close();

const BRAND_FILE        = __DIR__ . '/../data/brand.json';
const BRAND_NAME_MAX    = 60;
const BRAND_TAGLINE_MAX = 80;

/**
 * Who is allowed to change the workspace identity. Mirrors ajax/update.php:
 * absent config or absent admin_email means anyone signed in, so the common
 * single-user install needs no setup at all.
 */
function brand_admin_email() {
    $f = __DIR__ . '/../data/update.json';
    if (!is_file($f)) return '';
    $raw = @file_get_contents($f);
    if ($raw === false) return '';
    $cfg = @json_decode($raw, true);
    if (!is_array($cfg)) return '';
    return strtolower(trim((string)($cfg['admin_email'] ?? '')));
}

function brand_may_edit($email) {
    $admin = brand_admin_email();
    return $admin === '' || strtolower($email) === $admin;
}

/** Read the raw override file, or an empty array if there is not one yet. */
function brand_raw() {
    if (!is_file(BRAND_FILE)) return [];
    $raw = @file_get_contents(BRAND_FILE);
    if ($raw === false) return [];
    $cfg = @json_decode($raw, true);
    return is_array($cfg) ? $cfg : [];
}

/**
 * A display string bound for a <title>, a meta tag and the manifest. Control
 * characters are stripped rather than escaped: they have no legitimate place in
 * a workspace name and every consumer would have to remember to handle them.
 */
function brand_clean_text($v, $max) {
    $v = (string)$v;
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
    $v = preg_replace('/\s+/u', ' ', $v);
    $v = trim($v);
    if ($v === '') return '';
    if (function_exists('mb_substr')) return mb_substr($v, 0, $max, 'UTF-8');
    return substr($v, 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stored  = brand_raw();
    // What the name/tagline WOULD be with no override, so the UI can show the
    // automatic value as a placeholder and label the reset honestly. This has to
    // be the DERIVED value, not the resolved one — resolve_brand() already has
    // the override applied, so using it would report the current name as the
    // thing you would fall back to if you cleared it.
    $derived = brand_derived();
    echo json_encode([
        'ok'          => true,
        'may_edit'    => brand_may_edit($email),
        'admin_email' => brand_admin_email(),
        // 'derived' is what you get if you clear the override — the placeholder
        // and the "leave empty to use…" hint both quote it.
        'derived'     => [
            'name'    => $derived['name'],
            'tagline' => $derived['tagline'],
            'color'   => $derived['color'],
            'domain'  => $derived['domain'],
        ],
        // '' here means "not overridden — derived from the domain".
        'override'    => [
            'name'    => (string)($stored['name'] ?? ''),
            'tagline' => (string)($stored['tagline'] ?? ''),
            'color'   => (string)($stored['color'] ?? ''),
        ],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

if (!brand_may_edit($email)) {
    http_response_code(403);
    echo json_encode(['error' => 'Only ' . brand_admin_email() . ' can change the workspace for this installation.']);
    exit;
}

$stored = brand_raw();

// Reset clears the overrides and lets resolve_brand() derive from the domain
// again. The logo key is preserved either way — it is a separate setting and
// this endpoint has no business dropping it.
if (isset($_POST['reset'])) {
    $next = ['name' => '', 'tagline' => '', 'color' => ''];
    if (isset($stored['logo'])) $next['logo'] = (string)$stored['logo'];
} else {
    $next = $stored;

    if (isset($_POST['name'])) {
        $next['name'] = brand_clean_text($_POST['name'], BRAND_NAME_MAX);
    }
    if (isset($_POST['tagline'])) {
        $next['tagline'] = brand_clean_text($_POST['tagline'], BRAND_TAGLINE_MAX);
    }
    if (isset($_POST['color'])) {
        $color = trim((string)$_POST['color']);
        if ($color === '') {
            $next['color'] = '';                       // fall back to the default
        } elseif (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $next['color'] = strtolower($color);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Colour must be a 6-digit hex value such as #0078d4.']);
            exit;
        }
    }
}

if (!is_dir(dirname(BRAND_FILE))) {
    http_response_code(500);
    echo json_encode(['error' => 'The data folder is missing.']);
    exit;
}
if (!atomic_write_json(BRAND_FILE, $next)) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save — the data folder is not writable by the web server.']);
    exit;
}

// Report what the app will actually use now, so the page can update without a
// reload and without duplicating the fallback logic in JS.
$effective = resolve_brand();
$derived   = brand_derived();
echo json_encode([
    'ok'        => true,
    'derived'   => [
        'name'    => $derived['name'],
        'tagline' => $derived['tagline'],
        'color'   => $derived['color'],
        'domain'  => $derived['domain'],
    ],
    'effective' => [
        'name'    => $effective['name'],
        'tagline' => $effective['tagline'],
        'color'   => $effective['color'],
    ],
    'override'  => [
        'name'    => (string)($next['name'] ?? ''),
        'tagline' => (string)($next['tagline'] ?? ''),
        'color'   => (string)($next['color'] ?? ''),
    ],
]);
