<?php
/**
 * Small shared helpers used across the lib/ modules.
 */

if (!function_exists('gen_uuid')) {
    /** RFC 4122 version-4 UUID, e.g. "f47ac10b-58cc-4372-a567-0e02b2c3d479". */
    function gen_uuid() {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }
}
