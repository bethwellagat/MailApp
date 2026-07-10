<?php
/**
 * Application version — shown in Settings → Software update.
 *
 * Bump 'version' on a notable release. The in-app updater ships this file, and it
 * also stamps the deployed commit into data/version.json (shown as the "build"),
 * so the version label changes on EVERY update automatically — even between
 * semver bumps. Keep this a pure `return [...]` (it is included, not parsed).
 */
return [
    'version' => '1.0.1',
    'date'    => '2026-07-10',
];
