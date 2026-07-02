<?php
/**
 * In-app software update.
 *
 * Pulls the latest CODE from a configured git repository (via the GitHub zipball
 * — no `git` CLI or shell access required, just curl + ZipArchive) and copies it
 * over the application, EXCLUDING data/. Because every per-user thing — signatures,
 * workspace logos, brand override, filters, snoozes, contacts, outbox — lives under
 * data/, an update refreshes the app without disturbing any of it.
 *
 * Configuration lives in data/update.json (git-ignored, web-denied):
 *   {
 *     "repo":        "owner/name",   // required — the upstream repo
 *     "branch":      "main",         // optional — defaults to main
 *     "token":       "github_pat…",  // optional — required only for PRIVATE repos
 *     "admin_email": "boss@acme.com" // optional — if set, only this user may update
 *   }
 * Use a fine-grained, READ-ONLY, single-repo token. The feature is disabled until
 * this file exists and names a valid repo.
 */

function _update_dir()          { return __DIR__ . '/../data/.update'; }
function _update_config_file()  { return __DIR__ . '/../data/update.json'; }
function _update_version_file() { return __DIR__ . '/../data/version.json'; }
function _app_root()            { return dirname(__DIR__); }

/** Parsed config, or null when the feature is not configured. */
function update_config() {
    $f = _update_config_file();
    if (!is_file($f)) return null;
    $c = @json_decode((string)@file_get_contents($f), true);
    if (!is_array($c) || empty($c['repo']) || !preg_match('#^[\w.-]+/[\w.-]+$#', (string)$c['repo'])) return null;
    $branch = (string)($c['branch'] ?? 'main');
    if (!preg_match('#^[\w./-]+$#', $branch)) $branch = 'main';
    return [
        'repo'        => (string)$c['repo'],
        'branch'      => $branch,
        'token'       => is_string($c['token'] ?? null) ? trim($c['token']) : '',
        'admin_email' => strtolower(trim((string)($c['admin_email'] ?? ''))),
    ];
}

/** The commit sha currently deployed (recorded after the last successful update). */
function update_current_version() {
    $v = @json_decode((string)@file_get_contents(_update_version_file()), true);
    return is_array($v) ? (string)($v['sha'] ?? '') : '';
}

/** HTTPS GET against the GitHub API with auth + UA. Returns [body, httpCode, error]. */
function _update_api_get($url, $token, $accept = 'application/vnd.github+json') {
    if (!function_exists('curl_init')) return [null, 0, 'curl is not available on this server'];
    $ch = curl_init($url);
    $headers = ['User-Agent: WebMail-Updater', 'Accept: ' . $accept, 'X-GitHub-Api-Version: 2022-11-28'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,   // the zipball 302-redirects to codeload
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_TIMEOUT         => 90,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); return [null, 0, $e ?: 'request failed']; }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body, $code, null];
}

/** Look up the branch tip; compare with what's deployed. */
function update_check() {
    $cfg = update_config();
    if (!$cfg) return ['error' => 'Updates are not configured on this server.'];
    $url = 'https://api.github.com/repos/' . $cfg['repo'] . '/commits/' . rawurlencode($cfg['branch']);
    [$body, $code, $err] = _update_api_get($url, $cfg['token']);
    if ($err)            return ['error' => 'Could not reach GitHub: ' . $err];
    if ($code === 404)   return ['error' => 'Repository or branch not found — check repo / branch / token.'];
    if ($code === 401 || $code === 403) return ['error' => 'GitHub rejected the request (bad or missing token?).'];
    if ($code !== 200)   return ['error' => 'GitHub returned HTTP ' . $code . '.'];
    $data = @json_decode($body, true);
    $sha  = is_array($data) ? (string)($data['sha'] ?? '') : '';
    if ($sha === '') return ['error' => 'Unexpected response from GitHub.'];
    $cur = update_current_version();
    return [
        'ok'               => true,
        'repo'             => $cfg['repo'],
        'branch'           => $cfg['branch'],
        'current'          => $cur,
        'latest'           => $sha,
        'update_available' => ($cur === '' || strncmp($cur, $sha, 40) !== 0),
        'committed_at'     => (string)($data['commit']['committer']['date'] ?? ''),
        'message'          => isset($data['commit']['message']) ? substr((string)$data['commit']['message'], 0, 160) : '',
    ];
}

/** Recursively delete a directory — only ever called on data/.update. */
function _update_rrmdir($dir) {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if (!is_array($items)) return;
    foreach ($items as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . '/' . $e;
        if (is_dir($p) && !is_link($p)) _update_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Copy the $src tree onto $dst, writing each file atomically (temp + rename) so a
 * file is never seen half-written, and skipping the excluded top-level names.
 * Returns [filesCopied, errorOrNull].
 */
function _update_copy_tree($src, $dst, array $exclude) {
    $copied = 0;
    $stack  = [['s' => $src, 'rel' => '']];
    while ($stack) {
        $frame = array_pop($stack);
        $items = @scandir($frame['s']);
        if (!is_array($items)) continue;
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') continue;
            $rel = $frame['rel'] === '' ? $name : ($frame['rel'] . '/' . $name);
            if (in_array(explode('/', $rel)[0], $exclude, true)) continue; // exclude by top segment
            $sp = $frame['s'] . '/' . $name;
            $dp = $dst . '/' . $rel;
            if (is_dir($sp)) {
                if (!is_dir($dp) && !@mkdir($dp, 0755, true)) return [$copied, 'could not create ' . $rel];
                $stack[] = ['s' => $sp, 'rel' => $rel];
            } else {
                $data = @file_get_contents($sp);
                if ($data === false) return [$copied, 'could not read ' . $rel];
                $tmp = $dp . '.up.' . bin2hex(random_bytes(4));
                if (@file_put_contents($tmp, $data) === false) return [$copied, 'could not write ' . $rel . ' (is the app dir writable?)'];
                @chmod($tmp, is_file($dp) ? (fileperms($dp) & 0777) : 0644);
                if (!@rename($tmp, $dp)) { @unlink($tmp); return [$copied, 'could not replace ' . $rel]; }
                $copied++;
            }
        }
    }
    return [$copied, null];
}

/**
 * Download the latest code and copy it over the app — never outside the app root,
 * never into data/. Returns ['ok'=>true, 'files'=>N, 'version'=>sha] or ['error'=>…].
 */
function update_apply() {
    $cfg = update_config();
    if (!$cfg) return ['error' => 'Updates are not configured on this server.'];
    if (!class_exists('ZipArchive')) return ['error' => 'The PHP ZipArchive extension is required for updates.'];

    // Record the sha we're about to deploy (before touching anything).
    $chk = update_check();
    if (!is_array($chk) || empty($chk['ok'])) return is_array($chk) ? $chk : ['error' => 'Update check failed.'];
    $targetSha = (string)$chk['latest'];

    $work = _update_dir();
    _update_rrmdir($work);
    if (!@mkdir($work, 0700, true)) return ['error' => 'Could not create the update workspace — is data/ writable?'];

    // 1) Download the zipball.
    $url = 'https://api.github.com/repos/' . $cfg['repo'] . '/zipball/' . rawurlencode($cfg['branch']);
    [$zipData, $code, $err] = _update_api_get($url, $cfg['token']);
    if ($err)          { _update_rrmdir($work); return ['error' => 'Download failed: ' . $err]; }
    if ($code !== 200) { _update_rrmdir($work); return ['error' => 'GitHub returned HTTP ' . $code . ' for the download.']; }
    $zipFile = $work . '/repo.zip';
    if (@file_put_contents($zipFile, $zipData) === false) { _update_rrmdir($work); return ['error' => 'Could not save the download.']; }
    unset($zipData);

    // 2) Extract.
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) { _update_rrmdir($work); return ['error' => 'The downloaded archive is not a valid zip.']; }
    $extract = $work . '/x';
    @mkdir($extract, 0700, true);
    if (!$zip->extractTo($extract)) { $zip->close(); _update_rrmdir($work); return ['error' => 'Could not extract the archive.']; }
    $zip->close();

    // 3) GitHub wraps everything in one top-level directory.
    $tops = array_values(array_filter((array)@scandir($extract), fn($e) => $e !== '.' && $e !== '..'));
    if (count($tops) !== 1 || !is_dir($extract . '/' . $tops[0])) { _update_rrmdir($work); return ['error' => 'Unexpected archive layout.']; }
    $newRoot = $extract . '/' . $tops[0];

    // 4) Sanity-check it's really this app before overwriting a single file.
    if (!is_file($newRoot . '/inbox.php') || !is_file($newRoot . '/assets/app.js')) {
        _update_rrmdir($work);
        return ['error' => "The downloaded repository doesn't look like this app — aborting."];
    }

    // 5) Copy over the app root (per-file-atomic), skipping data/ and repo metadata.
    $exclude = ['data', '.git', '.github', '.gitignore', 'tests', 'cgi-bin', '.well-known'];
    [$copied, $cErr] = _update_copy_tree($newRoot, _app_root(), $exclude);
    if ($cErr) { _update_rrmdir($work); return ['error' => 'Update stopped: ' . $cErr]; }

    // 6) Record the deployed version + clean up.
    @file_put_contents(_update_version_file(), json_encode(['sha' => $targetSha, 'applied_at' => gmdate('c')]));
    @chmod(_update_version_file(), 0600);
    _update_rrmdir($work);

    return ['ok' => true, 'files' => $copied, 'version' => $targetSha];
}
