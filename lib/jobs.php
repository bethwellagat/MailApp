<?php
/**
 * The four unattended jobs, as functions.
 *
 * Each of these used to live inline in its endpoint, reading the signed-in
 * user's credentials from $_SESSION and answering with ok()/fail(). The
 * endpoints still do exactly that — they now call these and pass the result
 * through — and the scheduler (lib/cron.php) calls the same functions after
 * mirroring an opted-in account's sealed credentials into those session keys.
 * One body per job; two callers; no drift.
 *
 * Contract: each takes the account email and an $opts array, returns the
 * array the endpoint used to emit, and never exits or prints. An ['error']
 * key means the job could not run (the endpoint turns it into a 500); an
 * ['errors'] list means it ran and some items failed. $opts['cron'] skips the
 * cross-tab poll throttles, which exist to stop several browser tabs doing the
 * same sweep — the scheduler runs on its own cadence and is serialised by its
 * own lock.
 */

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/imap.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/outbox.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/snooze.php';
require_once __DIR__ . '/out_of_office.php';
require_once __DIR__ . '/log.php';

/* ---------- outbox: send what is due ---------- */

function job_outbox_process($email, array $opts = []) {
    // Guard against concurrent sweeps double-sending the same queued message
    // (e.g. the 60s poll firing while an Undo-commit also triggers a process,
    // or two open tabs, or the cron and a tab). A non-blocking exclusive lock
    // means a second sweep simply reports sent=0 rather than racing on files.
    $lock = @fopen(_outbox_dir($email) . '/.process.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) @fclose($lock);
        return ['ok' => true, 'sent' => 0, 'errors' => []];
    }

    $now    = gmdate('c');
    $nowTs  = time();
    $MAX_ATTEMPTS = 8;
    $messages = list_outbox_messages($email);
    $sent = 0;
    $errors = [];
    $purged = 0;
    // Retention: a message that gave up retrying stays on disk for ever, and a
    // queued record holds the FULL MIME body — message text plus any attachment
    // bytes — in the clear under data/. Long after the user has seen it fail and
    // moved on, that is just sensitive content sitting at rest. Keep failures long
    // enough to notice and act on (30 days), then drop them.
    $PURGE_FAILED_AFTER = 30 * 86400;
    foreach ($messages as $meta) {
        if (!empty($meta['failed'])) {
            $stamp = strtotime((string)($meta['queued_at'] ?? $meta['send_at'] ?? ''));
            if ($stamp !== false && ($nowTs - $stamp) > $PURGE_FAILED_AFTER) {
                if (delete_outbox_message($email, $meta['id'])) $purged++;
                continue;
            }
        }
        if (empty($meta['send_at']) || $meta['send_at'] > $now) continue;
        // Bounded retry: stop hammering a permanently-rejected message, and
        // honour exponential backoff between attempts. Without this a 5xx /
        // bad-credentials message retries every minute forever, silently.
        if (!empty($meta['failed'])) continue;
        if (!empty($meta['next_attempt_at']) && strtotime($meta['next_attempt_at']) > $nowTs) continue;
        $rec = load_outbox_message($email, $meta['id']);
        if (!$rec || empty($rec['rcpts']) || empty($rec['message'])) {
            delete_outbox_message($email, $meta['id']);
            continue;
        }
        // Already handed to the mail server on an earlier run that died before it
        // finished tidying up. Finish the bookkeeping — never transmit again.
        if (!empty($rec['sent_at'])) {
            append_to_sent(outbox_sent_copy($rec));
            delete_outbox_message($email, $rec['id']);
            continue;
        }
        $r = smtp_send($_SESSION['smtp_host'], $email, $rec['rcpts'], $rec['message'], $email, $_SESSION['password']);
        if ($r['ok']) {
            // Mark delivered BEFORE filing the Sent copy. Filing is an IMAP append
            // that can take seconds on a large message, and the record was only
            // removed afterwards — so a script killed in between (execution-time
            // limit, dropped connection) left a record the next sweep would send
            // AGAIN, delivering the message twice. Recording delivery first shrinks
            // that window to one small local write.
            $rec['sent_at'] = gmdate('c');
            save_outbox_message($email, $rec);
            append_to_sent(outbox_sent_copy($rec));
            delete_outbox_message($email, $rec['id']);
            $sent++;
        } else {
            // Record the attempt, schedule a backoff, and give up after the cap
            // (the file stays so the user can see it failed and cancel/retry).
            $rec['attempts']   = (int)($rec['attempts'] ?? 0) + 1;
            $rec['last_error'] = $r['error'];
            if ($rec['attempts'] >= $MAX_ATTEMPTS) {
                $rec['failed'] = true;
            } else {
                $delayMin = min(60, 1 << $rec['attempts']); // 2,4,8,… capped at 60 min
                $rec['next_attempt_at'] = gmdate('c', $nowTs + $delayMin * 60);
            }
            save_outbox_message($email, $rec);
            $errors[] = ['id' => $rec['id'], 'error' => $r['error'], 'attempts' => $rec['attempts'], 'failed' => !empty($rec['failed'])];
            app_log('warn', 'outbox: send failed', ['account' => $email, 'id' => $rec['id'], 'attempt' => $rec['attempts'], 'error' => $r['error']]);
        }
    }

    @flock($lock, LOCK_UN);
    @fclose($lock);
    return ['ok' => true, 'sent' => $sent, 'purged' => $purged, 'errors' => $errors];
}

/* ---------- rules: file what arrived since last time ---------- */

function job_rules_run_new($email, array $opts = []) {
    // Apply enabled rules to messages with UID > last_processed_uid in INBOX.
    $data = load_rules($email);
    if (empty($data['rules'])) return ['ok' => true, 'count' => 0];
    // Cross-tab throttle: open INBOX and sweep at most once every ~2 min total,
    // not once per tab per poll. The scheduler has its own cadence and lock.
    if (empty($opts['cron']) && !poll_gate($email, 'rules', 120)) return ['ok' => true, 'count' => 0, 'throttled' => true];

    // Serialize concurrent sweeps (two polls / two tabs / cron + tab): without
    // this, both can read the same last_processed_uid and apply every rule to
    // the same new messages twice. A non-blocking lock lets the loser bow out.
    $lock = @fopen(_rules_file($email) . '.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) @fclose($lock);
        return ['ok' => true, 'count' => 0, 'busy' => true];
    }
    // Re-read under the lock so we act on the freshest persisted watermark.
    $data = load_rules($email);

    $mbox = imap_open_box('INBOX');
    if (!$mbox) {
        @flock($lock, LOCK_UN); @fclose($lock);
        app_log('warn', 'rules: could not open Inbox', ['account' => $email]);
        return ['error' => 'Could not open Inbox'];
    }

    $check     = @imap_check($mbox);
    $highUid   = 0;
    if ($check && $check->Nmsgs > 0) {
        $highUid = (int)@imap_uid($mbox, $check->Nmsgs);
    }

    $minUid = (int)$data['last_processed_uid'];
    if ($minUid <= 0 && $highUid > 0) {
        // First-ever run on this account: don't sweep the whole inbox; just
        // mark current high-water and bail. Rules will start firing from the
        // next message that arrives.
        $fresh = load_rules($email); // reconcile — never persist our pre-IMAP rules snapshot
        $fresh['last_processed_uid'] = $highUid;
        save_rules($email, $fresh);
        @imap_close($mbox);
        @flock($lock, LOCK_UN); @fclose($lock);
        return ['ok' => true, 'count' => 0, 'first_run' => true];
    }

    $totalApplied = 0;
    foreach ($data['rules'] as $rule) {
        if (empty($rule['enabled'])) continue;
        $uids = rule_find_matches($mbox, $rule, $minUid, 500);
        if (!$uids) continue;
        $res = apply_rule_actions($mbox, 'INBOX', $uids, $rule['actions']);
        $totalApplied += $res['matched'] ?? 0;

        // If the rule moved/deleted messages, the inbox UID set has changed.
        // Re-checking imap_check after each rule keeps highUid correct.
        $check = @imap_check($mbox);
        if ($check && $check->Nmsgs > 0) {
            $highUid = max($highUid, (int)@imap_uid($mbox, $check->Nmsgs));
        }
    }

    // Reconcile instead of overwriting: re-read and write back ONLY the field this
    // sweep owns (the watermark). $data was loaded before the slow IMAP work above,
    // so persisting the whole blob would revert any rule the user added, edited, or
    // deleted in Settings while the sweep ran.
    $fresh = load_rules($email);
    // Monotonic. An empty INBOX at sweep time leaves $highUid at 0, and assigning
    // that would reset the watermark — the next sweep would then take the
    // first-run branch and silently skip filtering a whole batch of new mail.
    $fresh['last_processed_uid'] = max((int)$fresh['last_processed_uid'], (int)$highUid);
    save_rules($email, $fresh);
    @imap_close($mbox);
    @flock($lock, LOCK_UN); @fclose($lock);
    return ['ok' => true, 'count' => $totalApplied];
}

/* ---------- snooze: bring back what is due ---------- */

function job_snooze_wake($email, array $opts = []) {
    // Cross-tab throttle: the wake sweep + its IMAP work runs at most once every
    // ~2 min total, not once per tab per poll. The scheduler has its own cadence.
    if (empty($opts['cron']) && !poll_gate($email, 'snooze', 120)) return ['ok' => true, 'count' => 0, 'throttled' => true];
    // Process due snoozes and move them back to their original folder.
    // A due entry is dropped from the list only when it is resolved: either
    // moved back successfully, or no longer present in the Later folder. Entries
    // whose move fails (or whose Later folder can't be opened) are RETAINED so
    // the next sweep retries them, rather than stranding the message in Later.
    $count = 0;
    // Take a consistent snapshot under the lock, then release it for the slow
    // IMAP work — we must not hold the lock across multiple network round-trips
    // or an interactive add/cancel would block. We reconcile against a fresh
    // read at the end, so concurrent changes are preserved.
    $lock = snooze_lock($email);
    $data = load_snoozes($email);
    snooze_unlock($lock);
    $now  = gmdate('c');
    $byMove = []; // due now — attempt to move back
    foreach ($data['snoozes'] as $s) {
        if (empty($s['wake_at']) || $s['wake_at'] > $now) continue; // not yet due
        $byMove[] = $s;
    }
    if (empty($byMove)) return ['ok' => true, 'count' => 0];

    // Group by later_folder so we open it once
    $byLater = [];
    foreach ($byMove as $s) {
        $key = $s['later_folder'];
        if (!isset($byLater[$key])) $byLater[$key] = [];
        $byLater[$key][] = $s;
    }
    $resolved = []; // record-id => true for due entries that are done (moved back,
                    // or already gone from Later). Entries whose move fails / whose
                    // Later folder won't open are simply left out → retried next sweep.
    $movedBy  = []; // dest_folder => [message-id, ...] successfully moved
    foreach ($byLater as $laterFolder => $items) {
        $mbox = imap_open_box($laterFolder);
        if (!$mbox) { app_log('warn', 'snooze: could not open Later folder', ['account' => $email, 'folder' => $laterFolder]); continue; }
        $wokenUids = []; // everything this sweep moved out of Later, for the expunge
        foreach ($items as $s) {
            $foundUids = find_uids_by_message_id($mbox, $s['message_id']);
            if ($foundUids === null) continue;                               // couldn't read → retry next sweep
            if (!$foundUids) { $resolved[$s['id'] ?? ''] = true; continue; } // definitely gone → resolved
            $set = implode(',', $foundUids);
            $moved = @imap_mail_move($mbox, $set, $s['original_folder'], CP_UID);
            if ($moved) {
                $count++;
                $resolved[$s['id'] ?? ''] = true;
                $movedBy[$s['original_folder']][] = $s['message_id'];
                foreach ($foundUids as $fu) $wokenUids[] = $fu;
            }
            // move failed → not resolved; retained by the reconcile below
        }
        expunge_only($mbox, $wokenUids);
        @imap_close($mbox);
    }

    // Mark successfully-moved messages unread in their destination folders
    foreach ($movedBy as $dest => $ids) {
        $mbox = imap_open_box($dest);
        if (!$mbox) continue;
        foreach ($ids as $mid) {
            $uids = find_uids_by_message_id($mbox, $mid);
            if ($uids) @imap_clearflag_full($mbox, implode(',', $uids), '\\Seen', ST_UID);
        }
        @imap_close($mbox);
    }

    // Reconcile under the lock against the LATEST list: drop only the entries we
    // resolved, keeping everything else — including records another request may
    // have added/removed while we were doing IMAP. This is what prevents the
    // lost-update that a whole-list overwrite from our stale snapshot would cause.
    if ($resolved) {
        $lock = snooze_lock($email);
        $data = load_snoozes($email);
        $data['snoozes'] = array_values(array_filter(
            $data['snoozes'],
            fn($s) => empty($s['id']) || !isset($resolved[$s['id']])
        ));
        save_snoozes($email, $data);
        snooze_unlock($lock);
    }
    return ['ok' => true, 'count' => $count];
}

/* ---------- out of office: answer what arrived ---------- */

function job_ooo_process($email, array $opts = []) {
    $data = load_ooo($email);
    $cfg  = $data['config'];
    if (!ooo_is_active($cfg)) return ['ok' => true, 'replied' => 0, 'inactive' => true];
    // Cross-tab throttle: open INBOX and sweep at most once every ~2 min total,
    // not once per tab per poll. The scheduler has its own cadence.
    if (empty($opts['cron']) && !poll_gate($email, 'ooo', 120)) return ['ok' => true, 'replied' => 0, 'throttled' => true];

    // Serialize concurrent sweeps: without this, two polls can read the same
    // last_processed_uid and cooldown log and both fire auto-replies to the
    // same sender. A non-blocking lock lets the second sweep bow out cleanly.
    $lock = @fopen(_ooo_file($email) . '.lock', 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) @fclose($lock);
        return ['ok' => true, 'replied' => 0, 'busy' => true];
    }
    // Re-read under the lock so we act on the freshest persisted state (another
    // sweep may have just advanced last_processed_uid / replied before we won).
    $data = load_ooo($email);
    $cfg  = $data['config'];

    $mbox = imap_open_box('INBOX');
    if (!$mbox) {
        @flock($lock, LOCK_UN); @fclose($lock);
        app_log('warn', 'out-of-office: could not open Inbox', ['account' => $email]);
        return ['error' => 'Could not open Inbox'];
    }

    $check   = @imap_check($mbox);
    $highUid = $check && $check->Nmsgs > 0 ? (int)@imap_uid($mbox, $check->Nmsgs) : 0;
    $minUid  = (int)$data['last_processed_uid'];
    if ($minUid <= 0 && $highUid > 0) {
        $fresh = load_ooo($email); // reconcile — never persist our pre-IMAP config snapshot
        $fresh['last_processed_uid'] = $highUid;
        save_ooo($email, $fresh);
        @imap_close($mbox);
        @flock($lock, LOCK_UN); @fclose($lock);
        return ['ok' => true, 'replied' => 0, 'first_run' => true];
    }

    // Find UIDs > minUid
    $candidateUids = [];
    if ($highUid > $minUid) {
        $found = @imap_search($mbox, 'UID ' . ($minUid + 1) . ':*', SE_UID);
        if (is_array($found)) $candidateUids = array_map('intval', $found);
    }

    $repliedLog = (array)$data['replied'];
    $cooldownMs = max(0, (int)$cfg['cooldown_days']) * 86400 * 1000;
    $nowMs      = time() * 1000;

    // Drop cooldown entries that have already expired. Once an entry is older than
    // the cooldown it no longer suppresses anything, but it was kept for ever — so
    // a busy mailbox accumulated one line per correspondent, growing this file
    // (and every read of it) without limit. Keeps the sender's address on disk no
    // longer than it actually serves a purpose.
    if ($cooldownMs > 0) {
        $cutoff = $nowMs - $cooldownMs;
        foreach ($repliedLog as $addr => $iso) {
            $ts = strtotime((string)$iso);
            if ($ts === false || ($ts * 1000) < $cutoff) unset($repliedLog[$addr]);
        }
    }
    $myAddr     = strtolower($email);
    $sentCount  = 0;
    $errors     = [];
    $failedFloor = PHP_INT_MAX; // lowest UID whose reply failed — don't advance past it

    foreach ($candidateUids as $uid) {
        if ($uid <= $minUid) continue;
        $headersRaw = @imap_fetchheader($mbox, $uid, FT_UID);
        if (!$headersRaw) continue;

        // Parse From / Reply-To / Subject / Message-ID
        $info = @imap_headerinfo($mbox, @imap_msgno($mbox, $uid));
        if (!$info) continue;

        $fromAddr = '';
        if (!empty($info->reply_to[0])) {
            $r = $info->reply_to[0];
            $fromAddr = ($r->mailbox ?? '') . '@' . ($r->host ?? '');
        }
        if ((!$fromAddr || $fromAddr === '@') && !empty($info->from[0])) {
            $f = $info->from[0];
            $fromAddr = ($f->mailbox ?? '') . '@' . ($f->host ?? '');
        }
        $fromAddr = strtolower(trim($fromAddr, ' <>'));
        if (!$fromAddr || $fromAddr === '@') continue;
        if ($fromAddr === $myAddr) continue;

        if (ooo_should_skip($headersRaw, $fromAddr)) continue;

        // Cooldown: was this sender already auto-replied to recently?
        if (isset($repliedLog[$fromAddr])) {
            $lastTs = strtotime($repliedLog[$fromAddr]);
            if ($lastTs && ($nowMs - $lastTs * 1000) < $cooldownMs) continue;
        }

        $origSubject = '';
        if (preg_match('/^Subject:\s*(.+)$/im', $headersRaw, $m)) {
            $decoded = @imap_mime_header_decode($m[1]);
            if (is_array($decoded)) {
                foreach ($decoded as $p) $origSubject .= $p->text;
            } else {
                $origSubject = $m[1];
            }
            $origSubject = trim(preg_replace('/\s+/', ' ', $origSubject));
        }
        $origMsgId = '';
        if (preg_match('/^Message-ID:\s*(<[^>\r\n]+>)/im', $headersRaw, $m)) $origMsgId = trim($m[1]);

        $reply = ooo_build_reply_mime(
            $email,
            $_SESSION['display_name'] ?? '',
            $fromAddr,
            $origSubject,
            $cfg['body'],
            $origMsgId
        );

        $r = smtp_send($_SESSION['smtp_host'], $email, [$fromAddr], $reply['mime'], $email, $_SESSION['password']);
        if ($r['ok']) {
            // Persist the cooldown record immediately — if the sweep is killed
            // mid-loop (max_execution_time / dropped connection) we must not lose
            // the fact that this sender was already answered, or they'd get a
            // duplicate auto-reply on the next run.
            $repliedLog[$fromAddr] = gmdate('c');
            // Reconcile: persist only the cooldown log this sweep owns. $data was
            // read before these SMTP sends, so writing the whole blob would revert
            // a vacation message/date the user edited in Settings mid-sweep.
            $fresh = load_ooo($email);
            $fresh['replied'] = $repliedLog;
            save_ooo($email, $fresh);
            $data['replied'] = $repliedLog;
            $sentCount++;
        } else {
            $errors[] = ['to' => $fromAddr, 'error' => $r['error']];
            $failedFloor = min($failedFloor, $uid); // retry this sender next cycle
            app_log('warn', 'out-of-office: reply failed', ['account' => $email, 'to' => $fromAddr, 'error' => $r['error']]);
        }
    }

    // Advance the high-water mark, but never past a UID whose reply failed, so a
    // failed sender is retried. The persisted cooldown log prevents the
    // re-scanned successes in that range from being answered twice.
    $finalUid = ($failedFloor === PHP_INT_MAX) ? $highUid : min($highUid, $failedFloor - 1);
    // Reconcile against the latest state: this sweep owns the watermark and the
    // cooldown log; the user owns config. Writing our pre-send $data would silently
    // revert an out-of-office message or date range edited while the sweep ran.
    $fresh = load_ooo($email);
    $fresh['replied'] = $repliedLog;
    if ($finalUid > 0) $fresh['last_processed_uid'] = max((int)$fresh['last_processed_uid'], $finalUid);
    save_ooo($email, $fresh);
    @imap_close($mbox);
    @flock($lock, LOCK_UN); @fclose($lock);
    return ['ok' => true, 'replied' => $sentCount, 'errors' => $errors];
}
