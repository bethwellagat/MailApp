/**
 * Workspace-logo legibility detection.
 *
 * A white-label tenant uploads whatever logo they already have, and most brand
 * assets are drawn in dark ink on a transparent background because they were
 * made for white letterheads. Dropped into the dark topbar, such a logo
 * disappears. The mirror case is just as real: a knocked-out white logo vanishes
 * in light mode.
 *
 * The logo is stored as a data: URI (see sanitize_logo_data_uri — PNG/JPEG/GIF/
 * WebP only, no SVG), so it is same-origin by construction: the canvas is never
 * tainted and getImageData always succeeds. That lets us measure the image
 * rather than guess.
 *
 * What is measured is CONTRAST AGAINST THE SURFACE, not how dark the ink is.
 * Those are different questions, and only the first one matters: a mid-blue
 * wordmark is "dark" by any brightness test yet clears 3.7:1 on black and needs
 * no help. Each opaque pixel is checked against both appearances, and a plate is
 * asked for only when most of the ink would fail there. Judging per pixel rather
 * than on the average also handles two-tone logos correctly — a mark that is
 * half black and half white is always half legible, so it gets no plate, where
 * an average would have called it mid-grey and plated it on both.
 *
 * The verdict is recorded on the element and CSS applies it per appearance, so
 * a theme toggle needs no re-run. Deliberately a plate and not filter:invert():
 * inversion would rescue a black wordmark but turn a blue one orange, and a
 * white-label tenant's colours are the one thing that has to survive.
 */
(function () {
    'use strict';

    /* Sampled at 32x32: the browser's downscale averages for us, and 1024 pixels
       is plenty to judge a proportion. */
    var SAMPLE = 32;

    /* Transparent padding says nothing about the ink, and most logos are mostly
       padding — counting it would swamp the result. */
    var OPAQUE = 0.5;

    /* WCAG 1.4.11 non-text contrast. A logo is a graphical object. */
    var MIN_CONTRAST = 3.0;

    /* Plate only when the mark is mostly lost, so a logo with a dark outline or
       a small dark detail is not plated on account of that detail. */
    var LOST_FRACTION = 0.65;

    /* Relative luminance of the two surfaces a logo can land on: the dark
       topbar resolves to black, the light one to systemGroupedBackground
       #F2F2F7. The Settings preview checkerboards sit within a few percent of
       these, so one verdict serves both places. */
    var L_DARK_SURFACE = 0.0;
    var L_LIGHT_SURFACE = 0.8911;

    function channel(v) {
        v /= 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    }

    function contrast(a, b) {
        var hi = Math.max(a, b), lo = Math.min(a, b);
        return (hi + 0.05) / (lo + 0.05);
    }

    function classify(img) {
        if (!img || !img.naturalWidth || !img.naturalHeight) return;

        var canvas = document.createElement('canvas');
        canvas.width = SAMPLE;
        canvas.height = SAMPLE;
        var ctx = canvas.getContext && canvas.getContext('2d', { willReadFrequently: true });
        if (!ctx) return;

        var data;
        try {
            ctx.drawImage(img, 0, 0, SAMPLE, SAMPLE);
            data = ctx.getImageData(0, 0, SAMPLE, SAMPLE).data;
        } catch (e) {
            /* Should not happen for a data: URI, but a logo we cannot measure is
               left exactly as the tenant supplied it. */
            return;
        }

        var ink = 0, lostOnDark = 0, lostOnLight = 0;
        for (var i = 0; i < data.length; i += 4) {
            var alpha = data[i + 3] / 255;
            if (alpha < OPAQUE) continue;
            ink++;
            var lum = 0.2126 * channel(data[i]) +
                      0.7152 * channel(data[i + 1]) +
                      0.0722 * channel(data[i + 2]);
            if (contrast(lum, L_DARK_SURFACE) < MIN_CONTRAST) lostOnDark++;
            if (contrast(lum, L_LIGHT_SURFACE) < MIN_CONTRAST) lostOnLight++;
        }
        if (!ink) { img.removeAttribute('data-needs-plate'); return; }

        var dark = lostOnDark / ink >= LOST_FRACTION;
        var light = lostOnLight / ink >= LOST_FRACTION;

        if (dark && light) img.setAttribute('data-needs-plate', 'both');
        else if (dark) img.setAttribute('data-needs-plate', 'dark');
        else if (light) img.setAttribute('data-needs-plate', 'light');
        else img.removeAttribute('data-needs-plate');
    }

    function scan(root) {
        var scope = root || document;
        var imgs = scope.querySelectorAll('img[data-ink-detect]');
        for (var i = 0; i < imgs.length; i++) {
            (function (img) {
                if (img.complete) classify(img);
                else img.addEventListener('load', function () { classify(img); }, { once: true });
            })(imgs[i]);
        }
    }

    /* Exposed so Settings can re-run it after swapping the preview image, before
       the new logo has been saved. */
    window.detectLogoInk = scan;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scan(); });
    } else {
        scan();
    }
})();
