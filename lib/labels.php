<?php
/**
 * Labels — colour-coded, several per message, filterable, and stored on the
 * mail server as IMAP keywords so they survive in any other client.
 *
 * A folder forces one place per message; a candidate is both "Interviewed"
 * and "Dubai". The registry (name, colour, slug) lives per account under
 * data/labels/; the label itself is the keyword $lbl_<slug> set on the
 * message (lib/search.php search_label_keyword), on servers that allow
 * custom keywords (PERMANENTFLAGS \*), which Dovecot and most do.
 *
 * Reading labels back: PHP's IMAP overview carries no keywords, so the
 * page asks for a batch of UIDs and the server runs one KEYWORD search per
 * label and intersects — one round trip per label, each server-side and
 * indexed, instead of a FETCH FLAGS per message.
 */

require_once __DIR__ . '/log.php';     // app_data_root()
require_once __DIR__ . '/util.php';    // atomic_write_json, gen_uuid, bulk_uid_window
require_once __DIR__ . '/search.php';  // search_label_slug / search_label_keyword

const LABELS_MAX      = 50;
const LABEL_NAME_MAX  = 40;
const LABEL_COLORS    = 12;            // .label-c0 … .label-c11 in the stylesheet

function _labels_dir()        { return app_data_root() . '/labels'; }
function _labels_file($email) { return _labels_dir() . '/' . hash('sha256', strtolower(trim((string)$email))) . '.json'; }

function load_labels($email) {
    $f = _labels_file($email);
    $d = is_file($f) ? @json_decode((string)@file_get_contents($f), true) : null;
    if (!is_array($d) || !isset($d['labels']) || !is_array($d['labels'])) $d = ['labels' => []];
    $d['labels'] = array_values(array_filter($d['labels'], fn($l) => is_array($l) && !empty($l['id']) && !empty($l['slug'])));
    return $d;
}

function save_labels($email, array $d) {
    $dir = _labels_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    return (bool)atomic_write_json(_labels_file($email), ['labels' => array_values($d['labels'] ?? [])]);
}

/** By name, with the keyword each one is stored under. */
function labels_list($email) {
    $list = load_labels($email)['labels'];
    usort($list, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
    foreach ($list as &$l) $l['keyword'] = label_keyword($l);
    return $list;
}

function label_get($email, $id) {
    foreach (load_labels($email)['labels'] as $l) if ($l['id'] === (string)$id) return $l + ['keyword' => label_keyword($l)];
    return null;
}

function label_keyword(array $label) { return '$lbl_' . $label['slug']; }

/** name → keyword and slug → keyword, lower-cased, for the search translator's label: operator. */
function labels_keyword_map($email) {
    $map = [];
    foreach (load_labels($email)['labels'] as $l) {
        $map[strtolower((string)$l['name'])] = label_keyword($l);
        $map[(string)$l['slug']]             = label_keyword($l);
    }
    return $map;
}

/** A slug nobody else in the registry has: "dubai", then "dubai_2", … Pure. */
function label_unique_slug($name, array $taken) {
    $base = search_label_slug($name);
    if ($base === '') $base = 'label';
    $slug = $base; $n = 2;
    while (in_array($slug, $taken, true)) { $slug = substr($base, 0, 32 - strlen('_' . $n)) . '_' . $n; $n++; }
    return $slug;
}

/**
 * Create or update. The slug — and so the keyword on every message — is
 * fixed when the label is created; renaming changes what people see, not
 * what the server stores. Returns the record or ['error' => …].
 */
function label_save($email, array $in) {
    $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
    if ($name === '') return ['error' => 'Give the label a name.'];
    if (function_exists('mb_substr')) $name = mb_substr($name, 0, LABEL_NAME_MAX); else $name = substr($name, 0, LABEL_NAME_MAX);
    $color = max(0, min(LABEL_COLORS - 1, (int)($in['color'] ?? 0)));
    $d  = load_labels($email);
    $id = (string)($in['id'] ?? '');
    foreach ($d['labels'] as $i => $old) {
        if ($old['id'] === $id) {
            foreach ($d['labels'] as $j => $other) {
                if ($j !== $i && strcasecmp((string)$other['name'], $name) === 0) return ['error' => 'There is already a label called "' . $other['name'] . '".'];
            }
            $d['labels'][$i] = ['id' => $id, 'name' => $name, 'slug' => $old['slug'], 'color' => $color];
            if (!save_labels($email, $d)) return ['error' => 'Could not save the label — is the data folder writable?'];
            return $d['labels'][$i] + ['keyword' => label_keyword($d['labels'][$i])];
        }
    }
    foreach ($d['labels'] as $other) {
        if (strcasecmp((string)$other['name'], $name) === 0) return ['error' => 'There is already a label called "' . $other['name'] . '".'];
    }
    if (count($d['labels']) >= LABELS_MAX) return ['error' => 'You already have ' . LABELS_MAX . ' labels; delete one first.'];
    $rec = ['id' => gen_uuid(), 'name' => $name, 'slug' => label_unique_slug($name, array_column($d['labels'], 'slug')), 'color' => $color];
    $d['labels'][] = $rec;
    if (!save_labels($email, $d)) return ['error' => 'Could not save the label — is the data folder writable?'];
    return $rec + ['keyword' => label_keyword($rec)];
}

/** Remove from the registry. The keyword stays on messages, invisible: harmless, and restorable by recreating the slug. */
function label_delete($email, $id) {
    $d = load_labels($email);
    $before = count($d['labels']);
    $d['labels'] = array_values(array_filter($d['labels'], fn($l) => $l['id'] !== (string)$id));
    if (count($d['labels']) === $before) return false;
    return save_labels($email, $d);
}

/* ---------- on the server ---------- */

/** Set or clear one label's keyword on a UID set. */
function label_apply($mbox, array $uids, array $label, $on) {
    if (empty($uids)) return 0;
    $set = implode(',', array_map('intval', $uids));
    $ok  = $on ? @imap_setflag_full($mbox, $set, label_keyword($label), ST_UID)
               : @imap_clearflag_full($mbox, $set, label_keyword($label), ST_UID);
    return $ok ? count($uids) : 0;
}

/** uid → [label ids] from one KEYWORD search per label, limited to the UIDs asked about. Pure. */
function labels_intersect(array $perLabelUids, array $uids) {
    $want = array_fill_keys(array_map('intval', $uids), true);
    $out  = [];
    foreach ($perLabelUids as $labelId => $found) {
        foreach ((array)$found as $u) {
            $u = (int)$u;
            if (isset($want[$u])) $out[(string)$u][] = (string)$labelId;
        }
    }
    return $out;
}

function labels_for_uids($mbox, array $labels, array $uids) {
    $per = [];
    foreach ($labels as $l) {
        $found = @imap_search($mbox, 'KEYWORD ' . label_keyword($l), SE_UID);
        $per[$l['id']] = is_array($found) ? $found : [];
    }
    return labels_intersect($per, $uids);
}
