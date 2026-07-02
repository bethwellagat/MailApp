/* WebMail — service worker (zero-dependency, hand-written).
 *
 * Responsibilities:
 *   1. Make the app installable + usable offline (offline shell fallback).
 *   2. Cache-first for versioned static assets (css/js/svg/fonts) so repeat
 *      loads are instant and work with no network.
 *   3. Cache already-read mail JSON (GET ajax/fetch.php) so opened threads and
 *      the folder list stay readable offline.
 *
 * Security notes:
 *   - Credentials (email/password/host) live only in the PHP session and are
 *     NEVER part of any response cached here.
 *   - The authenticated HTML pages (inbox/settings) embed the per-session CSRF
 *     token, so they are deliberately NEVER cached — navigations are
 *     network-first and fall back only to the static offline shell.
 *   - Cached mail JSON is user content. It is wiped whenever the app lands on
 *     the unauthenticated login screen (see index.php), which covers logout,
 *     session expiry, and a different user on a shared browser.
 */

'use strict';

var VERSION = 'v3';
var SHELL_CACHE  = 'wm-shell-' + VERSION;   // precached offline shell + icons
var STATIC_CACHE = 'wm-static-' + VERSION;  // runtime-cached static assets + fonts
var MSG_CACHE    = 'wm-msg-' + VERSION;     // already-read mail JSON (cleared on logout)

var CURRENT_CACHES = [SHELL_CACHE, STATIC_CACHE, MSG_CACHE];
var MSG_CACHE_MAX = 150; // cap stored mail-API responses so the cache can't grow without bound

// Minimal precache: only fully static, version-stable files. Keeping this list
// tiny means install can't fail because one optional asset 404'd.
var SHELL_ASSETS = [
    'offline.html',
    'assets/favicon.svg',
    'assets/icon.svg',
    'assets/icon-maskable.svg'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(SHELL_CACHE).then(function (cache) {
            return cache.addAll(SHELL_ASSETS);
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (names) {
            return Promise.all(names.map(function (name) {
                // Drop our own stale versions; leave unrelated caches alone.
                if (name.indexOf('wm-') === 0 && CURRENT_CACHES.indexOf(name) === -1) {
                    return caches.delete(name);
                }
                return null;
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('message', function (event) {
    var data = event.data || {};
    if (data.type === 'CLEAR_MESSAGE_CACHE') {
        event.waitUntil(caches.delete(MSG_CACHE));
    } else if (data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

/* ---------- Strategy helpers ---------- */

function isStaticAsset(url) {
    return /\.(?:css|js|mjs|svg|png|jpe?g|gif|webp|ico|woff2?|ttf|otf)$/i.test(url.pathname);
}

// Read-only mail API: GET ajax/fetch.php?...  (POST goes to the same file for
// state changes and is never intercepted — see the method guard in fetch).
// Subdirectory-safe: matches whatever app root the install lives under.
function isReadApi(url) {
    return /\/ajax\/(?:fetch|calendar|contacts)\.php$/.test(url.pathname);
}

function isFontHost(url) {
    // Fonts are self-hosted under assets/fonts/ now — no external font hosts to
    // special-case. Kept as an inert no-op so the fetch-handler branch is dead.
    return false;
}

// Cache-first: serve the stored copy, otherwise fetch and store it. Used for
// versioned static assets (their URL changes when the file does, so a cached
// entry is never stale).
function cacheFirst(request, cacheName) {
    return caches.match(request).then(function (cached) {
        if (cached) return cached;
        return fetch(request).then(function (response) {
            if (response && (response.ok || response.type === 'opaque')) {
                var copy = response.clone();
                caches.open(cacheName).then(function (cache) { cache.put(request, copy); });
            }
            return response;
        });
    });
}

// Stale-while-revalidate: serve cache immediately if present, refresh in the
// background. Used for cross-origin fonts (URLs are stable, content rarely
// changes, opaque responses are fine to keep).
function staleWhileRevalidate(request, cacheName) {
    return caches.open(cacheName).then(function (cache) {
        return cache.match(request).then(function (cached) {
            var network = fetch(request).then(function (response) {
                if (response && (response.ok || response.type === 'opaque')) {
                    cache.put(request, response.clone());
                }
                return response;
            }).catch(function () { return cached; });
            return cached || network;
        });
    });
}

// Network-first for read mail JSON: always prefer fresh data; on success store
// a copy for offline; on failure fall back to the cached copy, then to a
// synthetic offline payload the frontend can render gracefully.
// Keep a bounded cache: Cache.keys() returns entries in insertion order, so the
// front of the list is the oldest — delete the overflow there.
function trimCache(cacheName, max) {
    return caches.open(cacheName).then(function (cache) {
        return cache.keys().then(function (keys) {
            if (keys.length <= max) return null;
            return Promise.all(keys.slice(0, keys.length - max).map(function (k) {
                return cache.delete(k);
            }));
        });
    });
}

function networkFirstApi(request, url) {
    return fetch(request).then(function (response) {
        var isSearch = /[?&]action=search(?:&|$)/.test(url.search);
        if (response && response.ok && !isSearch) {
            var copy = response.clone();
            caches.open(MSG_CACHE).then(function (cache) {
                cache.put(request, copy).then(function () { trimCache(MSG_CACHE, MSG_CACHE_MAX); });
            });
        }
        return response;
    }).catch(function () {
        return caches.match(request).then(function (cached) {
            if (cached) return cached;
            return new Response(
                JSON.stringify({ error: "You're offline. This view hasn't been saved for offline use yet.", offline: true }),
                { status: 503, headers: { 'Content-Type': 'application/json' } }
            );
        });
    });
}

/* ---------- Fetch router ---------- */

self.addEventListener('fetch', function (event) {
    var request = event.request;

    // Only GET is cacheable / safe to intercept; let everything else (POST
    // logins, sends, RSVP, etc.) hit the network untouched.
    if (request.method !== 'GET') return;

    var url;
    try { url = new URL(request.url); } catch (e) { return; }
    if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

    var sameOrigin = url.origin === self.location.origin;

    // Page navigations: never cache the authenticated HTML (it carries the CSRF
    // token); just fall back to the offline shell when the network is gone.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match('offline.html', { cacheName: SHELL_CACHE }).then(function (shell) {
                    return shell || new Response('You are offline.', {
                        status: 503, headers: { 'Content-Type': 'text/plain' }
                    });
                });
            })
        );
        return;
    }

    if (sameOrigin && isReadApi(url)) {
        event.respondWith(networkFirstApi(request, url));
        return;
    }

    if (sameOrigin && isStaticAsset(url)) {
        event.respondWith(cacheFirst(request, STATIC_CACHE));
        return;
    }

    if (!sameOrigin && isFontHost(url)) {
        event.respondWith(staleWhileRevalidate(request, STATIC_CACHE));
        return;
    }

    // Other same-origin GETs (e.g. dynamic .php that isn't a read API): network
    // with a cache fallback so a previously seen response can still resolve.
    if (sameOrigin) {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match(request);
            })
        );
    }
});
