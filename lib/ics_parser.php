<?php
/**
 * Minimal iCalendar (RFC 5545) parser.
 *
 * Handles the parts that 95% of consumer calendars actually use:
 *   - VEVENT blocks with SUMMARY, DTSTART, DTEND, LOCATION, DESCRIPTION, UID
 *   - Date-only (all-day) and date-time forms
 *   - TZID parameters → normalized to UTC ISO 8601
 *   - Folded continuation lines (CRLF + space/tab)
 *   - Basic RRULE expansion (FREQ=DAILY|WEEKLY|MONTHLY|YEARLY,
 *     INTERVAL, COUNT, UNTIL, BYDAY for WEEKLY)
 *   - EXDATE exclusions
 *
 * Skipped (rarely critical for previewing): RECURRENCE-ID overrides,
 * BYMONTH / BYMONTHDAY / BYSETPOS, VTIMEZONE definitions (we trust the
 * named TZID), VTODO / VJOURNAL.
 */

function ics_parse($ics) {
    if (!is_string($ics) || $ics === '') return [];
    // Normalize line endings, then unfold continuation lines (folded with
    // CRLF + space/tab per spec).
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    $ics = preg_replace("/\n[ \t]/", '', $ics);
    $lines = explode("\n", $ics);

    $events    = [];
    $cur       = null;
    $inAlarm   = false;

    foreach ($lines as $line) {
        if ($line === '') continue;
        $colon = strpos($line, ':');
        if ($colon === false) continue;
        $keypart = substr($line, 0, $colon);
        $val     = substr($line, $colon + 1);
        $segs    = explode(';', $keypart);
        $key     = strtoupper(array_shift($segs));
        $params  = [];
        foreach ($segs as $seg) {
            $eq = strpos($seg, '=');
            if ($eq === false) continue;
            $params[strtoupper(substr($seg, 0, $eq))] = substr($seg, $eq + 1);
        }

        if ($key === 'BEGIN') {
            $u = strtoupper($val);
            if ($u === 'VEVENT') { $cur = ['_raw' => [], 'exdates' => []]; $inAlarm = false; }
            elseif ($u === 'VALARM') { $inAlarm = true; }
            continue;
        }
        if ($key === 'END') {
            $u = strtoupper($val);
            if ($u === 'VEVENT' && $cur) {
                $ev = ics_normalize_event($cur);
                if ($ev) $events[] = $ev;
                $cur = null;
            } elseif ($u === 'VALARM') {
                $inAlarm = false;
            }
            continue;
        }
        if ($cur === null || $inAlarm) continue;

        // Multi-value support for EXDATE
        if ($key === 'EXDATE') {
            foreach (explode(',', $val) as $v) {
                $dt = ics_parse_dt(['value' => $v, 'params' => $params]);
                if ($dt) $cur['exdates'][] = $dt['utc'];
            }
            continue;
        }
        $cur['_raw'][$key] = ['value' => $val, 'params' => $params];
    }
    return $events;
}

function ics_normalize_event($raw) {
    $r = $raw['_raw'];
    if (!isset($r['DTSTART'])) return null;

    $start = ics_parse_dt($r['DTSTART']);
    if (!$start) return null;

    $end = isset($r['DTEND']) ? ics_parse_dt($r['DTEND']) : null;
    if (!$end && isset($r['DURATION'])) {
        $end = ics_apply_duration($start, $r['DURATION']['value']);
    }
    if (!$end) {
        // No DTEND/DURATION → for all-day, span the day; for timed, treat as
        // a 30-minute placeholder so it shows up in the agenda.
        $end = $start;
        if (!$start['date_only']) {
            try {
                $dt = new DateTime($end['utc']);
                $dt->modify('+30 minutes');
                $end['utc'] = $dt->format('Y-m-d\TH:i:s\Z');
            } catch (Exception $e) {}
        }
    }

    $rrule = isset($r['RRULE']) ? $r['RRULE']['value'] : null;

    return [
        'uid'         => $r['UID']['value'] ?? '',
        'title'       => ics_unescape($r['SUMMARY']['value'] ?? '(no title)'),
        'description' => ics_unescape($r['DESCRIPTION']['value'] ?? ''),
        'location'    => ics_unescape($r['LOCATION']['value'] ?? ''),
        'start'       => $start['utc'],
        'end'         => $end['utc'],
        'all_day'     => $start['date_only'],
        'rrule'       => $rrule,
        'exdates'     => $raw['exdates'] ?? [],
    ];
}

function ics_parse_dt($field) {
    $val    = $field['value'];
    $params = $field['params'];
    $isUtc  = substr($val, -1) === 'Z';
    $tz     = $params['TZID'] ?? ($isUtc ? 'UTC' : null);
    $value  = rtrim($val, 'Z');

    // Date-time form: YYYYMMDDTHHMMSS
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $value, $m)) {
        try {
            $tzObj = ics_safe_tz($tz);
            $dt = new DateTime("$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]", $tzObj);
            $dt->setTimezone(new DateTimeZone('UTC'));
            return ['utc' => $dt->format('Y-m-d\TH:i:s\Z'), 'date_only' => false];
        } catch (Exception $e) { return null; }
    }
    // Date-only form: YYYYMMDD (all-day)
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
        try {
            $dt = new DateTime("$m[1]-$m[2]-$m[3]T00:00:00", new DateTimeZone('UTC'));
            return ['utc' => $dt->format('Y-m-d\TH:i:s\Z'), 'date_only' => true];
        } catch (Exception $e) { return null; }
    }
    return null;
}

function ics_safe_tz($tz) {
    if (!$tz) return new DateTimeZone(date_default_timezone_get() ?: 'UTC');
    try { return new DateTimeZone($tz); }
    catch (Exception $e) { return new DateTimeZone('UTC'); }
}

function ics_apply_duration($start, $dur) {
    try {
        $dt = new DateTime($start['utc']);
        $dt->add(new DateInterval($dur));
        return ['utc' => $dt->format('Y-m-d\TH:i:s\Z'), 'date_only' => $start['date_only']];
    } catch (Exception $e) { return null; }
}

function ics_unescape($s) {
    $s = str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s);
    return $s;
}

/**
 * Expand a (possibly recurring) event to its occurrences within [from, to].
 * Both bounds are UTC ISO-8601 strings. Returns an array of event arrays
 * (each with overridden start/end). Caps at $maxInstances per event.
 */
function ics_expand_event($event, $from, $to, $maxInstances = 250) {
    if (empty($event['rrule'])) {
        // Non-recurring: include only if the event range overlaps [from, to]
        if (!ics_in_range($event, $from, $to)) return [];
        return [$event];
    }

    $rule     = ics_parse_rrule($event['rrule']);
    $freq     = strtoupper($rule['FREQ'] ?? '');
    $interval = max(1, (int)($rule['INTERVAL'] ?? 1));
    $count    = isset($rule['COUNT']) ? (int)$rule['COUNT'] : null;
    $until    = isset($rule['UNTIL']) ? ics_parse_until($rule['UNTIL']) : null;
    $byday    = isset($rule['BYDAY']) ? array_map('trim', explode(',', strtoupper($rule['BYDAY']))) : null;

    if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
        return ics_in_range($event, $from, $to) ? [$event] : [];
    }

    try {
        $start    = new DateTime($event['start']);
        $end      = new DateTime($event['end']);
        $duration = $end->getTimestamp() - $start->getTimestamp();
        $rangeFr  = new DateTime($from);
        $rangeTo  = new DateTime($to);
    } catch (Exception $e) {
        return [];
    }

    $exMap = [];
    foreach ($event['exdates'] ?? [] as $ex) $exMap[$ex] = true;

    $out      = [];
    $produced = 0;
    $iter     = 0;
    $hardCap  = 2000;
    $cur      = clone $start;

    $emit = function ($dt) use (&$out, &$produced, $event, $duration, $rangeFr, $rangeTo, $exMap, $maxInstances) {
        if ($produced >= $maxInstances) return false;
        $startStr = $dt->format('Y-m-d\TH:i:s\Z');
        if (isset($exMap[$startStr])) return true;
        $endDt = clone $dt;
        $endDt->modify('+' . $duration . ' seconds');
        if ($endDt < $rangeFr) return true;
        if ($dt > $rangeTo) return true;
        $occ = array_merge($event, [
            'start' => $startStr,
            'end'   => $endDt->format('Y-m-d\TH:i:s\Z'),
            'recurring' => true,
        ]);
        unset($occ['exdates']);
        $out[] = $occ;
        $produced++;
        return true;
    };

    while ($iter < $hardCap && $produced < $maxInstances) {
        if ($until && $cur > $until) break;
        if ($count !== null && $iter >= $count) break;

        if ($freq === 'WEEKLY' && $byday) {
            $weekStart = clone $cur;
            // Move back to the Monday of this week (RFC default WKST is MO)
            $dow = (int)$weekStart->format('N'); // 1=Mon..7=Sun
            if ($dow > 1) $weekStart->modify('-' . ($dow - 1) . ' days');
            foreach ($byday as $bd) {
                $bd = preg_replace('/^[+\-]?\d*/', '', $bd);
                $map = ['MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6,'SU'=>7];
                if (!isset($map[$bd])) continue;
                $occ = clone $weekStart;
                $occ->modify('+' . ($map[$bd] - 1) . ' days');
                $occ->setTime((int)$start->format('G'), (int)$start->format('i'), (int)$start->format('s'));
                if ($occ < $start) continue;
                if ($until && $occ > $until) continue;
                if ($occ > $rangeTo) break 2;
                if (!$emit($occ)) break 2;
            }
        } else {
            if ($cur >= $start) {
                if (!$emit($cur)) break;
            }
        }

        switch ($freq) {
            case 'DAILY':   $cur->modify("+{$interval} days");   break;
            case 'WEEKLY':  $cur->modify("+{$interval} weeks");  break;
            case 'MONTHLY': $cur->modify("+{$interval} months"); break;
            case 'YEARLY':  $cur->modify("+{$interval} years");  break;
        }
        if ($cur > $rangeTo) break;
        $iter++;
    }
    return $out;
}

function ics_parse_rrule($rrule) {
    $out = [];
    foreach (explode(';', $rrule) as $pair) {
        $eq = strpos($pair, '=');
        if ($eq === false) continue;
        $out[strtoupper(substr($pair, 0, $eq))] = substr($pair, $eq + 1);
    }
    return $out;
}

function ics_parse_until($val) {
    try {
        $value = rtrim($val, 'Z');
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $value, $m)) {
            return new DateTime("$m[1]-$m[2]-$m[3]T$m[4]:$m[5]:$m[6]", new DateTimeZone('UTC'));
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
            return new DateTime("$m[1]-$m[2]-$m[3]T23:59:59", new DateTimeZone('UTC'));
        }
    } catch (Exception $e) {}
    return null;
}

function ics_in_range($event, $from, $to) {
    return ($event['end'] >= $from) && ($event['start'] <= $to);
}

/* ============================================================ */
/* iTip (RFC 5546) — meeting invitations / RSVP                 */
/* ============================================================ */

/**
 * Parse a calendar object for iTip handling. Unlike ics_parse() (which only
 * returns normalized agenda events), this also surfaces the VCALENDAR METHOD
 * and, per VEVENT, the ORGANIZER, ATTENDEE list, UID, SEQUENCE and STATUS —
 * everything needed to recognize an invitation and send a reply.
 *
 * Returns ['method' => 'REQUEST'|'REPLY'|'CANCEL'|'PUBLISH'|'', 'events' => [...]].
 */
function ics_parse_itip($ics) {
    if (!is_string($ics) || $ics === '') return ['method' => '', 'events' => []];
    $ics = str_replace(["\r\n", "\r"], "\n", $ics);
    $ics = preg_replace("/\n[ \t]/", '', $ics); // unfold continuation lines
    $lines = explode("\n", $ics);

    $method  = '';
    $events  = [];
    $cur     = null;
    $inEvent = false;
    $inSub   = false; // inside VALARM / nested component we want to ignore

    foreach ($lines as $line) {
        if ($line === '') continue;
        $colon = strpos($line, ':');
        if ($colon === false) continue;
        $keypart = substr($line, 0, $colon);
        $val     = substr($line, $colon + 1);
        $segs    = explode(';', $keypart);
        $key     = strtoupper(array_shift($segs));
        $params  = [];
        foreach ($segs as $seg) {
            $eq = strpos($seg, '=');
            if ($eq === false) continue;
            $params[strtoupper(substr($seg, 0, $eq))] = trim(substr($seg, $eq + 1), '"');
        }

        if ($key === 'BEGIN') {
            $u = strtoupper($val);
            if ($u === 'VEVENT') { $cur = ['attendees' => []]; $inEvent = true; $inSub = false; }
            elseif ($inEvent)    { $inSub = true; } // VALARM etc. inside the event
            continue;
        }
        if ($key === 'END') {
            $u = strtoupper($val);
            if ($u === 'VEVENT' && $cur !== null) {
                $ev = _ics_itip_finalize($cur);
                if ($ev) $events[] = $ev;
                $cur = null; $inEvent = false; $inSub = false;
            } elseif ($inSub) {
                $inSub = false;
            }
            continue;
        }
        if (!$inEvent) {
            if ($key === 'METHOD') $method = strtoupper(trim($val));
            continue;
        }
        if ($inSub) continue; // skip VALARM properties

        switch ($key) {
            case 'UID':         $cur['uid']         = trim($val); break;
            case 'SUMMARY':     $cur['summary']     = ics_unescape($val); break;
            case 'DESCRIPTION': $cur['description'] = ics_unescape($val); break;
            case 'LOCATION':    $cur['location']    = ics_unescape($val); break;
            case 'STATUS':      $cur['status']      = strtoupper(trim($val)); break;
            case 'SEQUENCE':    $cur['sequence']    = (int)$val; break;
            case 'RRULE':       $cur['rrule']       = $val; break;
            case 'DTSTART':     $cur['_dtstart']    = ['value' => $val, 'params' => $params]; break;
            case 'DTEND':       $cur['_dtend']      = ['value' => $val, 'params' => $params]; break;
            case 'DURATION':    $cur['_duration']   = $val; break;
            case 'ORGANIZER':   $cur['organizer']   = _ics_addr($val, $params); break;
            case 'ATTENDEE':
                $a = _ics_addr($val, $params);
                if ($a['email'] !== '') $cur['attendees'][] = $a;
                break;
        }
    }
    return ['method' => $method, 'events' => $events];
}

/** Normalize an ORGANIZER/ATTENDEE value (mailto:addr) + params (CN, PARTSTAT…). */
function _ics_addr($val, $params) {
    $email = preg_replace('/^mailto:/i', '', trim($val));
    return [
        'email'    => strtolower(trim($email, " \t<>")),
        'name'     => isset($params['CN']) ? ics_unescape($params['CN']) : '',
        'partstat' => strtoupper($params['PARTSTAT'] ?? ''),
        'role'     => strtoupper($params['ROLE'] ?? ''),
        'rsvp'     => strtoupper($params['RSVP'] ?? '') === 'TRUE',
    ];
}

/** Turn a collected VEVENT into the invite shape (UTC times, organizer, etc.). */
function _ics_itip_finalize($cur) {
    if (!isset($cur['_dtstart'])) return null;
    $start = ics_parse_dt($cur['_dtstart']);
    if (!$start) return null;

    $end = isset($cur['_dtend']) ? ics_parse_dt($cur['_dtend']) : null;
    if (!$end && isset($cur['_duration'])) $end = ics_apply_duration($start, $cur['_duration']);
    if (!$end) {
        $end = $start;
        if (!$start['date_only']) {
            try {
                $dt = new DateTime($end['utc']);
                $dt->modify('+30 minutes');
                $end['utc'] = $dt->format('Y-m-d\TH:i:s\Z');
            } catch (Exception $e) {}
        }
    }

    return [
        'uid'         => $cur['uid'] ?? '',
        'summary'     => $cur['summary'] ?? '(no title)',
        'description' => $cur['description'] ?? '',
        'location'    => $cur['location'] ?? '',
        'status'      => $cur['status'] ?? '',
        'sequence'    => $cur['sequence'] ?? 0,
        'start'       => $start['utc'],
        'end'         => $end['utc'],
        'all_day'     => $start['date_only'],
        'rrule'       => $cur['rrule'] ?? null,
        'organizer'   => $cur['organizer'] ?? ['email' => '', 'name' => '', 'partstat' => '', 'role' => '', 'rsvp' => false],
        'attendees'   => $cur['attendees'] ?? [],
    ];
}

/**
 * Build a METHOD:REPLY VCALENDAR for an RSVP. Echoes UID/SEQUENCE/DTSTART/
 * SUMMARY/ORGANIZER from the request and adds a single ATTENDEE line carrying
 * the responder's PARTSTAT. Lines are folded to 75 octets per RFC 5545.
 */
function ics_build_reply($event, $attendeeEmail, $attendeeName, $partstat) {
    $partstat = strtoupper($partstat);
    if (!in_array($partstat, ['ACCEPTED', 'DECLINED', 'TENTATIVE'], true)) $partstat = 'ACCEPTED';

    $fmtDt = function ($iso, $allDay) {
        try { $dt = new DateTime($iso); }
        catch (Exception $e) { return null; }
        return $allDay ? $dt->format('Ymd') : $dt->format('Ymd\THis\Z');
    };
    $dtstart  = $fmtDt($event['start'] ?? '', !empty($event['all_day']));
    $orgEmail = $event['organizer']['email'] ?? '';
    $orgName  = $event['organizer']['name'] ?? '';

    $esc = function ($s) {
        return str_replace(["\\", "\n", ";", ","], ["\\\\", "\\n", "\\;", "\\,"], (string)$s);
    };

    $lines   = [];
    $lines[] = 'BEGIN:VCALENDAR';
    $lines[] = 'PRODID:-//WebMail//Calendar//EN';
    $lines[] = 'VERSION:2.0';
    $lines[] = 'METHOD:REPLY';
    $lines[] = 'BEGIN:VEVENT';
    if (!empty($event['uid'])) $lines[] = 'UID:' . $esc($event['uid']);
    $lines[] = 'SEQUENCE:' . (int)($event['sequence'] ?? 0);
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    if ($dtstart) $lines[] = (!empty($event['all_day']) ? 'DTSTART;VALUE=DATE:' : 'DTSTART:') . $dtstart;
    if (!empty($event['summary'])) $lines[] = 'SUMMARY:' . $esc($event['summary']);
    if ($orgEmail !== '') {
        $org = 'ORGANIZER';
        if ($orgName !== '') $org .= ';CN=' . _ics_param_quote($orgName);
        $lines[] = $org . ':mailto:' . $orgEmail;
    }
    $att = 'ATTENDEE;PARTSTAT=' . $partstat;
    if ($attendeeName !== '') $att .= ';CN=' . _ics_param_quote($attendeeName);
    $lines[] = $att . ':mailto:' . $attendeeEmail;
    $lines[] = 'REQUEST-STATUS:2.0;Success';
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", array_map('_ics_fold_line', $lines)) . "\r\n";
}

/** Quote an iCalendar parameter value if it contains a separator char. */
function _ics_param_quote($s) {
    $s = str_replace(["\r", "\n", '"'], [' ', ' ', ''], (string)$s);
    return preg_match('/[;:,]/', $s) ? '"' . $s . '"' : $s;
}

/**
 * Fold a content line to <=75 octets, continuation lines prefixed with a space.
 * Folding/unfolding is octet-based per the spec, so splitting inside a UTF-8
 * sequence is safe — the receiver rejoins before decoding.
 */
function _ics_fold_line($line) {
    if (strlen($line) <= 75) return $line;
    $out = substr($line, 0, 75);
    foreach (str_split(substr($line, 75), 74) as $piece) $out .= "\r\n " . $piece;
    return $out;
}
