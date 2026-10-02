<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

/**
 * The Guzzle `verify` option a path-or-switch setting (`drivers.kubernetes.ca_path`,
 * `drivers.acme.verify`) stands for: a CA bundle path, `true` for the system bundle, or
 * `false` to disable TLS verification.
 *
 * Env values are strings, and env() only converts "true"/"false" — so "1", "on", "0" or
 * "off" arrive as text. A boolean word is read as the switch it names rather than as a
 * CA bundle file called "0". null, an empty string and anything unrecognised verify
 * against the system bundle: only an explicit false-ish value disables verification.
 *
 * @internal shared by the driver factories and the `about` row so both read it the same way
 */
final class TlsVerification
{
    public static function from(mixed $setting): string|bool
    {
        if ($setting === null || (is_string($setting) && trim($setting) === '')) {
            return true;
        }

        $switch = filter_var($setting, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($switch !== null) {
            return $switch;
        }

        return is_string($setting) ? $setting : true;
    }
}
