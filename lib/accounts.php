<?php
/**
 * Multi-account session model.
 *
 * Credentials for every signed-in account live ONLY in $_SESSION — never on
 * disk, in cookies, or in logs. This file layers an additive account model on
 * top of the original flat session keys, with full backward compatibility:
 *
 *   $_SESSION['accounts']        => [ id => account[], ... ]
 *   $_SESSION['active_account']  => id of the account currently in focus
 *   $_SESSION['primary_account'] => id of the login account (cannot be removed)
 *
 * Each account[] holds: id, email, password, imap_host, imap_port, imap_ssl,
 * smtp_host, display_name.
 *
 * accounts_boot() runs at the top of every entry point (right after
 * session_start(), before the auth check). It (1) migrates a legacy
 * single-account session into the accounts array, then (2) mirrors the
 * *effective* account's credentials back into the original flat $_SESSION keys
 * ($_SESSION['email'] etc.). Every existing endpoint reads those flat keys, so
 * all mail/SMTP work transparently targets the effective account with no other
 * code changes.
 *
 * The effective account is chosen per request: a validated 'acct' parameter
 * (GET or POST) wins — letting the UI act on any signed-in account without
 * mutating global state — otherwise the persisted active account is used.
 */

if (!function_exists('account_id_for')) {
    function account_id_for($email) {
        return sha1(strtolower(trim((string)$email)));
    }
}

if (!function_exists('account_fields')) {
    /** The flat credential keys that constitute an account. */
    function account_fields() {
        return ['email', 'password', 'imap_host', 'imap_port', 'imap_ssl', 'smtp_host', 'display_name'];
    }
}

if (!function_exists('account_mirror')) {
    /**
     * Copy an account's credentials into the flat session keys every existing
     * endpoint reads. This is what makes per-account routing work without
     * rewriting endpoint code.
     */
    function account_mirror($acct) {
        if (!is_array($acct)) return;
        foreach (account_fields() as $k) {
            if (array_key_exists($k, $acct)) $_SESSION[$k] = $acct[$k];
        }
    }
}

if (!function_exists('account_first_id')) {
    function account_first_id() {
        if (empty($_SESSION['accounts']) || !is_array($_SESSION['accounts'])) return null;
        foreach ($_SESSION['accounts'] as $id => $_) return $id;
        return null;
    }
}

if (!function_exists('accounts_boot')) {
    function accounts_boot() {
        // (1) Migrate a legacy flat session into the accounts array.
        if (empty($_SESSION['accounts']) || !is_array($_SESSION['accounts'])) {
            if (!empty($_SESSION['email']) && !empty($_SESSION['imap_host'])) {
                $id   = account_id_for($_SESSION['email']);
                $acct = ['id' => $id];
                foreach (account_fields() as $k) {
                    $acct[$k] = $_SESSION[$k] ?? null;
                }
                $_SESSION['accounts']        = [$id => $acct];
                $_SESSION['active_account']  = $id;
                $_SESSION['primary_account'] = $id;
            } else {
                // No session at all — nothing to mirror; the caller's own auth
                // check will reject the request.
                return;
            }
        }

        $accounts = $_SESSION['accounts'];

        // (2) Heal a dangling active pointer (e.g. after an account removal).
        $activeId = $_SESSION['active_account'] ?? null;
        if (!isset($accounts[$activeId])) {
            $activeId = $_SESSION['primary_account'] ?? null;
            if (!isset($accounts[$activeId])) $activeId = account_first_id();
            $_SESSION['active_account'] = $activeId;
        }

        // (3) A validated per-request 'acct' override wins but does NOT persist.
        $req   = $_GET['acct'] ?? $_POST['acct'] ?? null;
        $effId = ($req !== null && isset($accounts[$req])) ? $req : $activeId;

        if (isset($accounts[$effId])) {
            account_mirror($accounts[$effId]);
        }
    }
}

if (!function_exists('account_list')) {
    /** Account summaries safe to expose to the client — NO passwords. */
    function account_list() {
        $out       = [];
        $accounts  = $_SESSION['accounts'] ?? [];
        $activeId  = $_SESSION['active_account'] ?? null;
        $primaryId = $_SESSION['primary_account'] ?? null;
        foreach ($accounts as $id => $a) {
            $email = (string)($a['email'] ?? '');
            $name  = trim((string)($a['display_name'] ?? ''));
            $out[] = [
                'id'      => $id,
                'email'   => $email,
                'name'    => ($name !== '' && strcasecmp($name, $email) !== 0) ? $name : '',
                'active'  => ($id === $activeId),
                'primary' => ($id === $primaryId),
            ];
        }
        return $out;
    }
}

if (!function_exists('account_get')) {
    function account_get($id) {
        return $_SESSION['accounts'][$id] ?? null;
    }
}

if (!function_exists('account_active_id')) {
    function account_active_id() {
        return $_SESSION['active_account'] ?? null;
    }
}

if (!function_exists('account_effective_id')) {
    /**
     * The account this request is actually operating as — the same resolution
     * accounts_boot() uses to pick which credentials to mirror: a validated
     * per-request 'acct' override wins, otherwise the persisted active account.
     * Endpoints embed this into the URLs they hand back to the client (e.g.
     * inline-image src) so a later request lands on the same mailbox even when
     * the focused account differs from the persisted active one.
     */
    function account_effective_id() {
        $accounts = $_SESSION['accounts'] ?? [];
        $req = $_GET['acct'] ?? $_POST['acct'] ?? null;
        if ($req !== null && isset($accounts[$req])) return $req;
        return $_SESSION['active_account'] ?? null;
    }
}

if (!function_exists('account_set_active')) {
    function account_set_active($id) {
        if (!isset($_SESSION['accounts'][$id])) return false;
        $_SESSION['active_account'] = $id;
        account_mirror($_SESSION['accounts'][$id]);
        return true;
    }
}

if (!function_exists('account_add')) {
    /**
     * Add or update an account. The caller MUST have already proven the
     * credentials (imap_open) before calling this. Re-adding an existing email
     * updates it in place (keyed by a hash of the email). Returns the id.
     */
    function account_add($fields) {
        $email = strtolower(trim((string)($fields['email'] ?? '')));
        if ($email === '') return null;
        $id   = account_id_for($email);
        $acct = ['id' => $id];
        foreach (account_fields() as $k) {
            $acct[$k] = $fields[$k] ?? null;
        }
        $acct['email'] = $email;

        if (empty($_SESSION['accounts']) || !is_array($_SESSION['accounts'])) {
            $_SESSION['accounts'] = [];
        }
        $_SESSION['accounts'][$id] = $acct;
        if (empty($_SESSION['primary_account'])) $_SESSION['primary_account'] = $id;
        if (empty($_SESSION['active_account']))  $_SESSION['active_account']  = $id;
        return $id;
    }
}

if (!function_exists('account_remove')) {
    /**
     * Remove a non-primary account. The primary (login) account cannot be
     * removed — logging out destroys the whole session instead.
     */
    function account_remove($id) {
        if (!isset($_SESSION['accounts'][$id])) return false;
        if (($_SESSION['primary_account'] ?? null) === $id) return false;

        unset($_SESSION['accounts'][$id]);

        if (($_SESSION['active_account'] ?? null) === $id) {
            $fallback = $_SESSION['primary_account'] ?? account_first_id();
            $_SESSION['active_account'] = $fallback;
            if (isset($_SESSION['accounts'][$fallback])) {
                account_mirror($_SESSION['accounts'][$fallback]);
            }
        }
        return true;
    }
}
