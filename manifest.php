<?php
/**
 * Web App Manifest — emitted dynamically so the installed-app name tracks the
 * host's brand (resolve_brand derives it from the Host header), keeping the
 * single codebase portable across client domains with no per-install edits.
 *
 * Contains no secrets and needs no session, so it is safe to serve on the
 * login screen (where the install prompt first appears). Linked from the
 * pages as the clean URL `manifest` (mod_rewrite maps it to this file), so
 * relative members (start_url, scope, icons) resolve against the app root.
 */

require_once __DIR__ . '/lib/brand.php';

$brand = resolve_brand();
$name  = $brand['name'];
$label = $name . ' WorkSpace';

header('Content-Type: application/manifest+json; charset=utf-8');
// Fallback caching for hosts without mod_headers; where .htaccess applies its
// no-store rule to .php it simply wins, which is fine — the manifest is cheap.
header('Cache-Control: public, max-age=3600');

$iconV = @filemtime(__DIR__ . '/assets/icon.svg') ?: 1;
$p192  = @filemtime(__DIR__ . '/assets/icon-192.png') ?: 1;
$p512  = @filemtime(__DIR__ . '/assets/icon-512.png') ?: 1;
$pmask = @filemtime(__DIR__ . '/assets/icon-maskable-512.png') ?: 1;

$manifest = [
    'name'             => $label,
    'short_name'       => $name,
    'description'      => $brand['tagline'] ?: 'Secure WorkSpace',
    'start_url'        => 'inbox',
    'scope'            => './',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#f5f6f8',
    'theme_color'      => '#0078d4',
    'lang'             => 'en',
    'dir'              => 'ltr',
    'icons'            => [
        // Raster PNGs first — Chromium/Android require concrete 192/512 sizes
        // for installability; the SVG is kept as a crisp 'any' for browsers that
        // honour it.
        [
            'src'     => 'assets/icon-192.png?v=' . $p192,
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => 'assets/icon-512.png?v=' . $p512,
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => 'assets/icon-maskable-512.png?v=' . $pmask,
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
        [
            'src'     => 'assets/icon.svg?v=' . $iconV,
            'sizes'   => 'any',
            'type'    => 'image/svg+xml',
            'purpose' => 'any',
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
