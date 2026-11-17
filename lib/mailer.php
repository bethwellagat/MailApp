<?php
require_once __DIR__ . '/util.php'; // imap_tls_flags(), tls_verify_enabled(), tls_remember_relaxed()
/**
 * Shared SMTP / outbox helpers.
 *
 * Extracted from ajax/send.php so the outbox processor and the vacation
 * responder can call the same code paths. Behaviour is unchanged from the
 * original send.php — moved here verbatim.
 */

if (!function_exists('mime_header_encode')) {
    function mime_header_encode($s) {
        if ($s === '' || preg_match('/^[\x20-\x7e]*$/', $s)) return $s;
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
}

if (!function_exists('addr_list_format')) {
    /**
     * Turn what the user typed in To/Cc/Bcc into a valid RFC 5322 address list.
     *
     * The field held the raw text, so autocomplete's `Lagat, Bethwel <b@x.com>, `
     * went out unquoted with a trailing comma. Receivers read that as two
     * recipients ("Lagat" plus Bethwel), Gmail showed "to Lagat, me", and a
     * broken address header is a spam signal. Here a piece with no address that
     * precedes a `Name <addr>` piece is folded back into that name, names are
     * quoted (or encoded) when they need it, and stray separators are dropped.
     *
     * Returns ['header' => string, 'emails' => [addr,...], 'bad' => [piece,...]].
     */
    function addr_list_format($raw) {
        $out = ['header' => '', 'emails' => [], 'bad' => []];
        $raw = preg_replace('/[\r\n]+/', ' ', (string)$raw);
        if (trim($raw) === '') return $out;

        // Split on , or ; outside quotes and angle brackets.
        $tokens = []; $buf = ''; $inQ = false; $inA = false; $esc = false;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($esc)                              { $buf .= $ch; $esc = false; continue; }
            if ($ch === '\\' && $inQ)              { $buf .= $ch; $esc = true;  continue; }
            if ($ch === '"' && !$inA)              { $inQ = !$inQ; $buf .= $ch; continue; }
            if ($ch === '<' && !$inQ)              { $inA = true;  $buf .= $ch; continue; }
            if ($ch === '>' && !$inQ)              { $inA = false; $buf .= $ch; continue; }
            if (($ch === ',' || $ch === ';') && !$inQ && !$inA) { $tokens[] = $buf; $buf = ''; continue; }
            $buf .= $ch;
        }
        $tokens[] = $buf;
        $tokens = array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));

        $entries = []; $pending = [];
        foreach ($tokens as $tok) {
            if (preg_match('/^(.*)<\s*([^<>\s]+)\s*>$/s', $tok, $m)) {
                $name = trim($m[1]); $email = trim($m[2]);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    // "Lagat, Bethwel <b@x>": the address-less pieces before it were the name.
                    if ($pending) { $name = trim(implode(', ', $pending) . ($name !== '' ? ', ' . $name : '')); $pending = []; }
                    $entries[] = [$name, $email];
                    continue;
                }
                foreach ($pending as $p) $out['bad'][] = $p; $pending = [];
                $out['bad'][] = $tok;
                continue;
            }
            $bare = trim($tok, " \t<>");
            if (filter_var($bare, FILTER_VALIDATE_EMAIL)) {
                foreach ($pending as $p) $out['bad'][] = $p; $pending = [];
                $entries[] = ['', $bare];
                continue;
            }
            $pending[] = $tok;
        }
        foreach ($pending as $p) $out['bad'][] = $p;

        $seen = []; $parts = [];
        foreach ($entries as [$name, $email]) {
            $key = strtolower($email);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out['emails'][] = $email;
            // Unwrap a name that was already quoted, then re-quote it correctly.
            if (strlen($name) >= 2 && $name[0] === '"' && substr($name, -1) === '"') {
                $name = stripcslashes(substr($name, 1, -1));
            }
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if ($name === '' || strcasecmp($name, $email) === 0) { $parts[] = $email; continue; }
            if (!preg_match('/^[\x20-\x7e]*$/', $name)) {
                $parts[] = mime_header_encode($name) . ' <' . $email . '>';
            } elseif (preg_match('/[()<>\[\]:;@\\\\,."]/', $name)) {
                $parts[] = '"' . addcslashes($name, '"\\') . '" <' . $email . '>';
            } else {
                $parts[] = $name . ' <' . $email . '>';
            }
        }
        $out['header'] = implode(', ', $parts);
        return $out;
    }
}

if (!function_exists('message_restamp_date')) {
    /**
     * Set a built message's Date header to now. A queued send (Undo Send,
     * Schedule Send) was built, and stamped, when the user pressed Send, then
     * left minutes later, so receivers saw a Date older than the Received
     * hops. Only the top-level header block is touched.
     */
    function message_restamp_date($message) {
        $message = (string)$message;
        $cut = strpos($message, "\r\n\r\n");
        if ($cut === false) return $message;
        $head = substr($message, 0, $cut);
        $new  = preg_replace('/^Date: [^\r\n]*/mi', 'Date: ' . date('r'), $head, 1, $n);
        return $n ? $new . substr($message, $cut) : $message;
    }
}

if (!function_exists('html_to_text')) {
    function html_to_text($html) {
        $t = preg_replace('#<br\s*/?>#i', "\n", $html);
        $t = preg_replace('#</p>|</div>|</h\d>|</li>#i', "\n", $t);
        $t = preg_replace('#<li[^>]*>#i', '• ', $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t);
    }
}

if (!function_exists('wrap_html')) {
    function wrap_html($body) {
        return '<!doctype html><html><head><meta charset="utf-8"></head>'
             . '<body style="font-family:Inter,sans-serif;font-size:14px;line-height:1.5;color:#1a1d29;">'
             . $body
             . '</body></html>';
    }
}

if (!function_exists('smtp_send')) {
    function smtp_send($host, $from, $rcpts, $message, $user, $pass) {
        // Certificates are verified for remote hosts (see tls_verify_enabled).
        // If that is the only reason the send fails, retry once WITHOUT
        // verification and remember this host needs it — a self-signed mail
        // server must never stop a user sending mail.
        $verify = tls_verify_enabled($host);

        $errSsl = '';
        $r = smtp_attempt('ssl://' . $host . ':465', $host, $from, $rcpts, $message, $user, $pass, false, $verify);
        if ($r['ok']) return $r;
        $errSsl = $r['error'];

        $r2 = smtp_attempt('tcp://' . $host . ':587', $host, $from, $rcpts, $message, $user, $pass, true, $verify);
        if ($r2['ok']) return $r2;

        if ($verify && (smtp_is_tls_error($errSsl) || smtp_is_tls_error($r2['error']))) {
            // Remember the host ONLY once the relaxed attempt has actually
            // succeeded. Recording it up-front meant a host that was simply down,
            // or refusing STARTTLS, got permanently marked as needing relaxed TLS
            // — silently disabling certificate verification for IMAP too, since
            // both protocols read the same data/tls.json.
            $r3 = smtp_attempt('ssl://' . $host . ':465', $host, $from, $rcpts, $message, $user, $pass, false, false);
            if ($r3['ok']) { tls_remember_relaxed($host); return $r3; }
            $r4 = smtp_attempt('tcp://' . $host . ':587', $host, $from, $rcpts, $message, $user, $pass, true, false);
            if ($r4['ok']) { tls_remember_relaxed($host); return $r4; }
        }
        return ['ok' => false, 'error' => "ssl/465: $errSsl ; starttls/587: " . $r2['error']];
    }
}

if (!function_exists('smtp_is_tls_error')) {
    /**
     * Does this failure look like CERTIFICATE VERIFICATION specifically?
     *
     * Deliberately narrow. Matching bare "ssl"/"tls" also matched this file's own
     * "STARTTLS 502" and "TLS upgrade failed" — a server that merely doesn't offer
     * usable STARTTLS would then be recorded as needing relaxed TLS, turning
     * verification off install-wide for both protocols. A server we cannot reach
     * should fail, not silently downgrade everyone's security.
     */
    function smtp_is_tls_error($err) {
        return (bool)preg_match('#certificate|self[ -]?signed|verify failed|unable to get local issuer|CN.{0,12}mismatch#i', (string)$err);
    }
}

if (!function_exists('smtp_attempt')) {
    function smtp_attempt($conn, $host, $from, $rcpts, $message, $user, $pass, $starttls, $verify = false) {
        $errno = 0; $errstr = '';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => (bool)$verify,
            'verify_peer_name'  => (bool)$verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);
        $sock = @stream_socket_client($conn, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) return ['ok' => false, 'error' => "connect: $errstr"];
        stream_set_timeout($sock, 15);

        $read = function () use ($sock) {
            $resp = '';
            while (!feof($sock)) {
                $line = fgets($sock, 2048);
                if ($line === false) break;
                $resp .= $line;
                if (preg_match('/^\d{3} /', $line)) break;
            }
            return [(int)substr($resp, 0, 3), $resp];
        };
        $write = function ($cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };

        [$code,] = $read();
        if ($code !== 220) { fclose($sock); return ['ok' => false, 'error' => "greeting $code"]; }

        $write('EHLO ' . (gethostname() ?: ($_SERVER['SERVER_NAME'] ?? 'localhost')));
        [$code,] = $read();
        if ($code !== 250) { fclose($sock); return ['ok' => false, 'error' => "EHLO $code"]; }

        if ($starttls) {
            $write('STARTTLS');
            [$code,] = $read();
            if ($code !== 220) { fclose($sock); return ['ok' => false, 'error' => "STARTTLS $code"]; }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($sock, true, $crypto)) {
                fclose($sock); return ['ok' => false, 'error' => 'TLS upgrade failed'];
            }
            $write('EHLO ' . (gethostname() ?: ($_SERVER['SERVER_NAME'] ?? 'localhost')));
            [$code,] = $read();
            if ($code !== 250) { fclose($sock); return ['ok' => false, 'error' => "EHLO2 $code"]; }
        }

        $write('AUTH LOGIN');
        [$code,] = $read();
        if ($code !== 334) { fclose($sock); return ['ok' => false, 'error' => "AUTH $code"]; }

        $write(base64_encode($user));
        [$code,] = $read();
        if ($code !== 334) { fclose($sock); return ['ok' => false, 'error' => "username $code"]; }

        $write(base64_encode($pass));
        [$code, $resp] = $read();
        if ($code !== 235) { fclose($sock); return ['ok' => false, 'error' => "auth rejected $code"]; }

        $write('MAIL FROM:<' . $from . '>');
        [$code,] = $read();
        if ($code !== 250) { fclose($sock); return ['ok' => false, 'error' => "MAIL FROM $code"]; }

        foreach ($rcpts as $r) {
            $write('RCPT TO:<' . $r . '>');
            [$code,] = $read();
            if ($code !== 250 && $code !== 251) {
                fclose($sock);
                return ['ok' => false, 'error' => "RCPT $r $code"];
            }
        }

        $write('DATA');
        [$code,] = $read();
        if ($code !== 354) { fclose($sock); return ['ok' => false, 'error' => "DATA $code"]; }

        $message = preg_replace('/^\./m', '..', $message);
        fwrite($sock, $message);
        fwrite($sock, "\r\n.\r\n");
        [$code,] = $read();
        if ($code !== 250) { fclose($sock); return ['ok' => false, 'error' => "data accept $code"]; }

        $write('QUIT');
        @fclose($sock);
        return ['ok' => true];
    }
}

if (!function_exists('append_to_sent')) {
    function append_to_sent($message) {
        if (!function_exists('imap_open')) return false;
        if (empty($_SESSION['email']) || empty($_SESSION['password']) || empty($_SESSION['imap_host'])) return false;

        $ssl   = !empty($_SESSION['imap_ssl']);
        $port  = (int)($_SESSION['imap_port'] ?? 993);
        $host  = $_SESSION['imap_host'];
        $flags = imap_tls_flags($_SESSION['imap_host'] ?? '', $ssl);
        $ref   = '{' . $host . ':' . $port . $flags . '}';

        $mbox = imap_open_tls($_SESSION['imap_host'], (int)($_SESSION['imap_port'] ?? 993), !empty($_SESSION['imap_ssl']),
                              'INBOX', $_SESSION['email'], $_SESSION['password'], OP_HALFOPEN, 1);
        if (!$mbox) { @imap_errors(); @imap_alerts(); return false; }

        $list = @imap_list($mbox, $ref, '*');
        $sent = null;
        if ($list) {
            foreach ($list as $raw) {
                $name = mb_convert_encoding(str_replace($ref, '', $raw), 'UTF-8', 'UTF7-IMAP');
                if (stripos($name, 'sent') !== false) { $sent = $name; break; }
            }
        }

        $ok = false;
        if ($sent) {
            $ok = @imap_append($mbox, $ref . $sent, $message, '\\Seen');
        }
        @imap_close($mbox);
        @imap_errors();
        @imap_alerts();
        return (bool)$ok;
    }
}
