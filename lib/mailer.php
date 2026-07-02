<?php
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
        $errSsl = '';
        $r = smtp_attempt('ssl://' . $host . ':465', $host, $from, $rcpts, $message, $user, $pass, false);
        if ($r['ok']) return $r;
        $errSsl = $r['error'];

        $r = smtp_attempt('tcp://' . $host . ':587', $host, $from, $rcpts, $message, $user, $pass, true);
        if ($r['ok']) return $r;
        return ['ok' => false, 'error' => "ssl/465: $errSsl ; starttls/587: " . $r['error']];
    }
}

if (!function_exists('smtp_attempt')) {
    function smtp_attempt($conn, $host, $from, $rcpts, $message, $user, $pass, $starttls) {
        $errno = 0; $errstr = '';
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
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
        $flags = $ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls';
        $ref   = '{' . $host . ':' . $port . $flags . '}';

        $mbox = @imap_open($ref . 'INBOX', $_SESSION['email'], $_SESSION['password'], OP_HALFOPEN, 1);
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
