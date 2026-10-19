<?php
/**
 * Templates and canned replies.
 *
 * A recruiter answers the same twenty questions; a support desk closes
 * tickets with the same paragraph. A template is a named subject + body,
 * stored per account under data/templates/, inserted from compose with the
 * recipient's details filled in:
 *
 *   {first_name}  {name}  {email}     the first recipient in To
 *   {subject}                          the compose subject
 *   {date}                             today, e.g. 19 October 2026
 *   {my_name}  {my_email}              the sender
 *   {first_name|there}                 any placeholder with a fallback
 *
 * Values are HTML-escaped as they go in — a recipient's display name is
 * untrusted text and the body is HTML. Unknown placeholders are left as
 * typed, so a literal "{ticket}" in a template survives.
 *
 * Bodies pass through the signature sanitiser (lib/prefs.php): same rules,
 * same reasons — a template is pasted into the compose editor and sent.
 */

require_once __DIR__ . '/log.php';     // app_data_root()
require_once __DIR__ . '/util.php';    // atomic_write_json, gen_uuid
require_once __DIR__ . '/prefs.php';   // sanitize_signature_html

const TEMPLATES_MAX        = 100;
const TEMPLATE_NAME_MAX    = 80;
const TEMPLATE_SUBJECT_MAX = 200;
const TEMPLATE_BODY_MAX    = 262144;   // 256 KB of HTML is a very long canned reply

function _templates_dir()        { return app_data_root() . '/templates'; }
function _templates_file($email) { return _templates_dir() . '/' . hash('sha256', strtolower(trim((string)$email))) . '.json'; }

function load_templates($email) {
    $f = _templates_file($email);
    $d = is_file($f) ? @json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d) || !isset($d['templates']) || !is_array($d['templates'])) $d = ['templates' => []];
    $d['templates'] = array_values(array_filter($d['templates'], 'is_array'));
    return $d;
}

function save_templates($email, array $d) {
    $dir = _templates_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    return (bool)atomic_write_json(_templates_file($email), ['templates' => array_values($d['templates'] ?? [])]);
}

/** One clean record, or null when it has no name. */
function template_sanitize(array $t) {
    $name = trim(preg_replace('/\s+/', ' ', (string)($t['name'] ?? '')));
    if ($name === '') return null;
    if (function_exists('mb_substr')) $name = mb_substr($name, 0, TEMPLATE_NAME_MAX); else $name = substr($name, 0, TEMPLATE_NAME_MAX);
    $subject = trim(preg_replace('/[\r\n]+/', ' ', (string)($t['subject'] ?? '')));
    if (function_exists('mb_substr')) $subject = mb_substr($subject, 0, TEMPLATE_SUBJECT_MAX); else $subject = substr($subject, 0, TEMPLATE_SUBJECT_MAX);
    $body = (string)($t['body'] ?? '');
    if (strlen($body) > TEMPLATE_BODY_MAX) return ['error' => 'The template body is too large (' . round(strlen($body) / 1024) . ' KB; the limit is 256 KB).'];
    $id = (string)($t['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $id)) $id = gen_uuid();
    return [
        'id'         => $id,
        'name'       => $name,
        'subject'    => $subject,
        'body'       => sanitize_signature_html($body),
        'updated_at' => gmdate('c'),
    ];
}

/** Templates for the pane and the compose menu, newest edit first. */
function templates_list($email) {
    $list = load_templates($email)['templates'];
    usort($list, fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
    return $list;
}

function template_get($email, $id) {
    foreach (load_templates($email)['templates'] as $t) if (($t['id'] ?? '') === (string)$id) return $t;
    return null;
}

/** Create or update. Returns the saved record, or ['error' => …]. */
function template_save($email, array $t) {
    $clean = template_sanitize($t);
    if ($clean === null) return ['error' => 'Give the template a name.'];
    if (!empty($clean['error'])) return $clean;
    $d = load_templates($email);
    $found = false;
    foreach ($d['templates'] as $i => $old) {
        if (($old['id'] ?? '') === $clean['id']) { $d['templates'][$i] = $clean; $found = true; break; }
    }
    if (!$found) {
        if (count($d['templates']) >= TEMPLATES_MAX) return ['error' => 'You already have ' . TEMPLATES_MAX . ' templates; delete one first.'];
        $d['templates'][] = $clean;
    }
    if (!save_templates($email, $d)) return ['error' => 'Could not save the template — is the data folder writable?'];
    return $clean;
}

function template_delete($email, $id) {
    $d = load_templates($email);
    $before = count($d['templates']);
    $d['templates'] = array_values(array_filter($d['templates'], fn($t) => ($t['id'] ?? '') !== (string)$id));
    if (count($d['templates']) === $before) return false;
    return save_templates($email, $d);
}

/* ---------- rendering: pure ---------- */

/**
 * The values a template can use, from the compose fields. The first To
 * recipient supplies name/first_name/email; "Name <addr>" and bare addresses
 * both work. A bare address gives no name, so {first_name|there} applies.
 */
function template_vars_for($to, $subject, $myName = '', $myEmail = '', $now = null) {
    $now   = $now ?? time();
    // The first recipient: a comma inside a quoted display name is not a separator.
    $first = preg_match('/^\s*((?:"[^"]*"|[^,<])*(?:<[^>]*>)?)/', (string)$to, $fm) ? trim($fm[1]) : trim((string)$to);
    $name  = ''; $email = $first;
    if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $first, $m)) { $name = trim($m[1]); $email = trim($m[2]); }
    $name = trim($name, " \t\"'");
    $firstName = $name === '' ? '' : preg_split('/\s+/', $name)[0];
    return [
        'first_name' => $firstName,
        'name'       => $name,
        'email'      => $email,
        'subject'    => trim((string)$subject),
        'date'       => date('j F Y', $now),
        'my_name'    => trim((string)$myName),
        'my_email'   => trim((string)$myEmail),
    ];
}

/** Replace {var} and {var|fallback}; values are escaped for HTML. Unknown names stay. */
function template_render($html, array $vars) {
    return preg_replace_callback('/\{([a-z_]+)(?:\|([^{}]*))?\}/i', function ($m) use ($vars) {
        $key = strtolower($m[1]);
        if (!array_key_exists($key, $vars)) return $m[0];
        $v = (string)$vars[$key];
        if ($v === '' && isset($m[2])) $v = $m[2];
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }, (string)$html);
}

/** The subject line gets the same treatment, without HTML escaping. */
function template_render_text($text, array $vars) {
    return preg_replace_callback('/\{([a-z_]+)(?:\|([^{}]*))?\}/i', function ($m) use ($vars) {
        $key = strtolower($m[1]);
        if (!array_key_exists($key, $vars)) return $m[0];
        $v = (string)$vars[$key];
        if ($v === '' && isset($m[2])) $v = $m[2];
        return $v;
    }, (string)$text);
}
