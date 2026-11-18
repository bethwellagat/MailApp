<?php
/**
 * Domain-aware brand resolution.
 *
 * Auto-derives a brand name + tagline from the current Host header so the
 * same code can be deployed to any client subdomain unchanged. Can be
 * overridden per-installation by dropping a data/brand.json file:
 *
 *   {
 *     "name":    "Acme Corporation",
 *     "tagline": "Secure WorkSpace",
 *     "logo":    ""
 *   }
 *
 * Any field that is empty / missing falls back to the derived value.
 */

/**
 * The brand as derived from the host alone, with no data/brand.json overlay.
 * Split out from resolve_brand() so a caller can ask what the name WOULD be
 * without the override — Settings shows this as the placeholder and in the
 * "leave empty to use…" hint, and that hint is a lie if it reports the value
 * currently overriding it.
 */
function brand_derived($host = null) {
    $host = $host ?? ($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:\d+$/', '', (string)$host);
    $host = strtolower($host);

    // Strip a leading infrastructure label so mail.acme.com / outlook.acme.com
    // / webmail.acme.com all resolve to acme.com.
    $domain = preg_replace('/^(mail|webmail|www|outlook|smtp|imap|pop3?|mx|cpanel|webdisk|autodiscover|autoconfig|web)\./i', '', $host);

    // SLD = the part before the public suffix. For most cases this is the
    // last label-but-one (acme.com → acme, acme.co.ke → acme).
    $sld = '';
    if ($domain !== '') {
        $parts = explode('.', $domain);
        if (count($parts) >= 3 && strlen(end($parts)) === 2 && in_array($parts[count($parts) - 2], ['co','ac','or','go','ne','com','org','gov'], true)) {
            // e.g. acme.co.ke / acme.ac.uk → acme
            $sld = $parts[count($parts) - 3];
        } elseif (count($parts) >= 2) {
            $sld = $parts[count($parts) - 2];
        } else {
            $sld = $parts[0];
        }
    }

    // An IP address has no meaningful second-level label — 127.0.0.1 would
    // otherwise yield "0" — and neither does a label with no letters in it.
    // Fall through to the generic name rather than invent a nonsense brand.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false || !preg_match("/\p{L}/u", $sld)) {
        $sld = '';
    }

    // Turn the SLD into a presentable name: treat - and _ as word breaks and
    // title-case each word, so radiant-comms → "Radiant Comms", acme_corp →
    // "Acme Corp", acme → "Acme". Run-together names (radiantcomms) can't be
    // split automatically and become "Radiantcomms".
    $niceName = $sld !== '' ? ucwords(str_replace(['-', '_'], ' ', $sld)) : 'WorkSpace';

    $defaults = [
        'name'    => $niceName,
        'tagline' => 'Secure WorkSpace',
        'domain'  => $domain,
        'host'    => $host,
        'logo'    => '',
        // Brand colour used for the PWA theme colour and the browser/OS chrome.
        // Overridable per install so a white-labelled deployment isn't stuck with
        // the default blue; validated as a hex colour before it reaches any page.
        'color'   => '#0078d4',
    ];

    return $defaults;
}

/**
 * The brand the app actually uses: the derived values with data/brand.json
 * laid over the top. Any field that is empty or missing keeps the derived one.
 */
function resolve_brand($host = null) {
    $defaults = brand_derived($host);

    $config = __DIR__ . '/../data/brand.json';
    if (is_file($config)) {
        $raw = @file_get_contents($config);
        if ($raw !== false) {
            $cfg = @json_decode($raw, true);
            if (is_array($cfg)) {
                foreach (['name', 'tagline', 'logo'] as $k) {
                    if (!empty($cfg[$k]) && is_string($cfg[$k])) {
                        $defaults[$k] = $cfg[$k];
                    }
                }
                // Only a literal hex colour — this value is emitted into a meta
                // tag and the manifest, so it must not be free-form text.
                if (!empty($cfg['color']) && is_string($cfg['color'])
                    && preg_match('/^#[0-9a-fA-F]{6}$/', trim($cfg['color']))) {
                    $defaults['color'] = strtolower(trim($cfg['color']));
                }
            }
        }
    }
    return $defaults;
}

/**
 * What to draw on top of the brand colour.
 *
 * The inbox header is filled with the install's own colour, and that colour is
 * whatever the tenant chose — a pale yellow is as valid as a navy. White ink is
 * used wherever it is readable (3:1, the floor for large text and icons, which
 * is everything the bar holds); a colour too light for that gets near-black ink
 * instead, so no choice of brand colour can make the header unreadable.
 *
 * Returns ['bar' => '#rrggbb', 'ink' => '#rrggbb', 'dark_ink' => bool].
 * Anything that is not a six-digit hex falls back to the default blue.
 */
function brand_bar_colors($hex) {
    $hex = is_string($hex) ? strtolower(trim($hex)) : '';
    if (!preg_match('/^#[0-9a-f]{6}$/', $hex)) $hex = '#0078d4';
    $lum = brand_luminance($hex);
    // Contrast of white against the colour: (1.0 + 0.05) / (L + 0.05).
    $white = 1.05 / ($lum + 0.05);
    $darkInk = $white < 3.0;
    return ['bar' => $hex, 'ink' => $darkInk ? '#1a1d23' : '#ffffff', 'dark_ink' => $darkInk];
}

/** WCAG relative luminance of a #rrggbb colour, 0 (black) to 1 (white). */
function brand_luminance($hex) {
    $v = hexdec(substr($hex, 1));
    $lin = function ($c) {
        $c /= 255;
        return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * $lin(($v >> 16) & 255) + 0.7152 * $lin(($v >> 8) & 255) + 0.0722 * $lin($v & 255);
}
