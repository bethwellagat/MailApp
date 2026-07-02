<?php
require_once __DIR__ . '/util.php';
/**
 * Per-user calendar storage.
 *
 * Mirrors the prefs.php pattern: one JSON file per email, keyed by sha256.
 * Stored under data/calendars/. The data/ dir already has an .htaccess
 * denying web access.
 */

function _calendar_dir() {
    return __DIR__ . '/../data/calendars';
}

function _calendar_file($email) {
    $dir = _calendar_dir();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/' . hash('sha256', strtolower(trim($email))) . '.json';
}

function default_calendar() {
    return [
        'events'      => [],   // local events created by the user
        'feeds'       => [],   // configured ICS feeds (metadata only)
        'feed_events' => (object)[], // events parsed from each feed, keyed by feed id
    ];
}

function load_calendar($email) {
    $defaults = default_calendar();
    if (!$email) return $defaults;
    $file = _calendar_file($email);
    if (!is_file($file)) return $defaults;
    $raw = @file_get_contents($file);
    if ($raw === false) return $defaults;
    $data = @json_decode($raw, true);
    if (!is_array($data)) return $defaults;
    return array_merge($defaults, $data);
}

function save_calendar($email, $data) {
    if (!$email || !is_array($data)) return false;
    $file = _calendar_file($email);
    $tmp  = $file . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return @rename($tmp, $file);
}

function calendar_uuid() { return gen_uuid(); }

/**
 * Serialize a load→modify→save on the calendar file so concurrent writes (two
 * tabs, or a poll-driven op racing an interactive edit) can't clobber each
 * other's events/feeds. Same discipline as lib/prefs.php. Callers doing slow
 * network I/O (feed_sync) must fetch FIRST, then lock→reload→save.
 */
function calendar_lock($email) {
    $lock = @fopen(_calendar_file($email) . '.lock', 'c');
    if ($lock) @flock($lock, LOCK_EX);
    return $lock;
}
function calendar_unlock($lock) {
    if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
}

/**
 * SSRF guard: true if $ip is an address we must never fetch from — loopback,
 * RFC1918 private, link-local (which includes the cloud-metadata endpoint
 * 169.254.169.254), CGNAT shared space, or otherwise reserved. Public IPs
 * (v4 or v6) return false.
 */
function _calendar_ip_blocked($ip) {
    // filter_var rejects loopback, private, link-local and reserved ranges for
    // both IPv4 and IPv6 when these flags are combined.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    // filter_var misses CGNAT shared space (RFC 6598, 100.64.0.0/10).
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
            return true;
        }
    }
    return false;
}

/**
 * Confirm $host resolves only to public addresses. A literal IP is checked
 * directly; a name is resolved to every A/AAAA record and each is validated.
 * Returns [true, null] when safe, else [false, reason]. Unresolvable -> unsafe.
 */
function _calendar_host_is_safe($host) {
    $host = trim($host, '[]'); // unwrap IPv6 literal brackets
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return _calendar_ip_blocked($host)
            ? [false, 'Refusing to fetch from a private or reserved address']
            : [true, null];
    }
    $ips = [];
    $a = @gethostbynamel($host);
    if (is_array($a)) $ips = $a;
    if (function_exists('dns_get_record')) {
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) foreach ($aaaa as $rec) if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
    }
    if (!$ips) return [false, 'Could not resolve calendar host'];
    foreach ($ips as $ip) {
        if (_calendar_ip_blocked($ip)) return [false, 'Calendar host resolves to a private or reserved address'];
    }
    return [true, null];
}

/** Resolve a redirect Location (absolute, root-relative, or path-relative) against $base. */
function _calendar_resolve_redirect($base, $location) {
    if (preg_match('#^https?://#i', $location)) return $location;
    $b = @parse_url($base);
    if (!$b || empty($b['scheme']) || empty($b['host'])) return null;
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if ($location !== '' && $location[0] === '/') return $origin . $location;
    $path = $b['path'] ?? '/';
    $dir  = substr($path, 0, strrpos($path, '/') + 1);
    return $origin . $dir . $location;
}

/** Single HTTP GET with redirects DISABLED. Returns [body, status, location, error]. */
function _calendar_http_request($url, $timeout) {
    $maxBytes = 10 * 1024 * 1024; // cap so a huge/hostile feed can't exhaust memory
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // we follow manually so every hop is re-validated
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'WebMail-Calendar/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => ['Accept: text/calendar, text/plain, */*'],
            CURLOPT_NOPROGRESS     => false,
            // Abort the transfer (return non-zero) once it exceeds the cap.
            CURLOPT_PROGRESSFUNCTION => function ($c, $dlTotal, $dlNow) use ($maxBytes) {
                return ($dlNow > $maxBytes || $dlTotal > $maxBytes) ? 1 : 0;
            },
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $errno = curl_errno($ch); $e = curl_error($ch); curl_close($ch);
            if ($errno === CURLE_ABORTED_BY_CALLBACK) return [null, 0, null, 'Calendar feed too large'];
            return [null, 0, null, $e ?: 'Fetch failed'];
        }
        $hdrLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $rawHeaders = substr($resp, 0, $hdrLen);
        $body       = substr($resp, $hdrLen);
        $location   = null;
        if (preg_match('/^Location:\s*(.+)$/im', $rawHeaders, $m)) $location = trim($m[1]);
        return [$body, $code, $location, null];
    }
    $ctx = stream_context_create(['http' => [
        'timeout'         => $timeout,
        'follow_location' => 0,
        'ignore_errors'   => true,
        'user_agent'      => 'WebMail-Calendar/1.0',
        'header'          => "Accept: text/calendar, text/plain, */*\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
    if ($body === false && empty($http_response_header)) {
        return [null, 0, null, 'Fetch failed (allow_url_fopen disabled?)'];
    }
    if ($body !== false && strlen($body) > $maxBytes) {
        return [null, 0, null, 'Calendar feed too large'];
    }
    $code = 0; $location = null;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];
        elseif (stripos($h, 'Location:') === 0) $location = trim(substr($h, 9));
    }
    return [$body === false ? '' : $body, $code, $location, null];
}

/**
 * Fetch an ICS feed with SSRF protection: only http/https, and every hop
 * (including each redirect target) must resolve to a public address. Redirects
 * are followed manually — up to 5 — so a public URL cannot bounce the request
 * to an internal/metadata address. Returns [body, null] or [false, error].
 *
 * Residual risk: a DNS-rebinding host could resolve to a public IP during the
 * safety check and a private IP when curl connects. Closing that fully needs
 * IP pinning, which we skip to keep TLS/vhost handling simple on shared hosts.
 */
function calendar_fetch_url($url, $timeout = 15) {
    $maxHops = 5;
    for ($hop = 0; $hop <= $maxHops; $hop++) {
        $parts = @parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return [false, 'Invalid calendar URL'];
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return [false, 'Only http/https URLs are supported'];
        }
        [$safe, $why] = _calendar_host_is_safe($parts['host']);
        if (!$safe) return [false, $why];

        [$body, $status, $location, $err] = _calendar_http_request($url, $timeout);
        if ($err !== null) return [false, $err];

        if ($status >= 300 && $status < 400 && $location) {
            $next = _calendar_resolve_redirect($url, $location);
            if (!$next) return [false, 'Bad redirect target'];
            $url = $next;
            continue; // re-validate the new host on the next iteration
        }
        if ($status < 200 || $status >= 300) return [false, 'HTTP ' . $status];
        return [$body, null];
    }
    return [false, 'Too many redirects'];
}

function sanitize_event_input($e) {
    $title = trim((string)($e['title'] ?? ''));
    $start = trim((string)($e['start'] ?? ''));
    $end   = trim((string)($e['end'] ?? ''));
    if ($title === '' || $start === '') return null;
    $allDay = !empty($e['all_day']);
    if ($end === '') $end = $start;
    return [
        'id'          => $e['id'] ?? calendar_uuid(),
        'source'      => 'local',
        'title'       => mb_substr($title, 0, 200),
        'start'       => $start,
        'end'         => $end,
        'all_day'     => (bool)$allDay,
        'location'    => mb_substr(trim((string)($e['location'] ?? '')), 0, 200),
        'notes'       => mb_substr(trim((string)($e['notes'] ?? '')), 0, 4000),
        'updated_at'  => gmdate('c'),
    ];
}
