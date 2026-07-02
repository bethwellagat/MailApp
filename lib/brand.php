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

function resolve_brand($host = null) {
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
    ];

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
            }
        }
    }
    return $defaults;
}
