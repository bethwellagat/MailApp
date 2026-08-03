<?php
/**
 * One place to build an IMAP connection reference and open a mailbox.
 *
 * These helpers were copy-pasted into six endpoints under six different names
 * (imap_ref, imap_ref_r, imap_ref_snz, imap_ref_o, _cal_imap_ref, _d_ref) with
 * four open_box variants beside them. The copies then DRIFTED: open_box_r in
 * ajax/rules.php never received the folder-name validation its siblings carry,
 * which is exactly the kind of gap duplication produces — a security check
 * present in four places and silently missing from the fifth.
 *
 * Everything here reads the flat $_SESSION keys that lib/accounts.php mirrors
 * for the account this request is acting as, so per-account routing keeps
 * working with no extra plumbing.
 */

require_once __DIR__ . '/util.php'; // imap_tls_flags(), imap_open_tls()

if (!function_exists('imap_valid_mailbox')) {
    /**
     * A mailbox name must not carry c-client connection metacharacters ('{' '}')
     * or control characters: a '}' could rewrite the {host:port} portion of the
     * connection reference and redirect the IMAP session to an attacker-chosen
     * server, and control bytes could smuggle protocol data. Legitimate folder
     * names (letters, digits, spaces, the hierarchy delimiter) never contain
     * these.
     */
    function imap_valid_mailbox($name) {
        return is_string($name) && $name !== '' && !preg_match('/[{}\x00-\x1F\x7F]/', $name);
    }
}

if (!function_exists('imap_session_ref')) {
    /** The {host:port/flags} reference for the account this request acts as. */
    function imap_session_ref() {
        $host = (string)($_SESSION['imap_host'] ?? '');
        $port = (int)($_SESSION['imap_port'] ?? 993);
        return '{' . $host . ':' . $port . imap_tls_flags($host, !empty($_SESSION['imap_ssl'])) . '}';
    }
}

if (!function_exists('imap_open_box')) {
    /**
     * Open a mailbox for the current session account. Returns false for an
     * invalid folder name — the guard is applied HERE so no caller can forget it.
     * Certificate failures are healed once by imap_open_tls().
     */
    function imap_open_box($folder = 'INBOX', $opts = 0) {
        if (!imap_valid_mailbox($folder)) return false;
        return imap_open_tls(
            (string)($_SESSION['imap_host'] ?? ''),
            (int)($_SESSION['imap_port'] ?? 993),
            !empty($_SESSION['imap_ssl']),
            $folder,
            (string)($_SESSION['email'] ?? ''),
            (string)($_SESSION['password'] ?? ''),
            $opts,
            1
        );
    }
}
