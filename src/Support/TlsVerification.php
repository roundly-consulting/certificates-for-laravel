<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * The Guzzle `verify` option a path-or-switch setting (`drivers.kubernetes.ca_path`,
 * `drivers.acme.verify`) stands for: a CA bundle path, `true` for the system bundle, or
 * `false` to disable TLS verification.
 *
 * Env values are strings, and env() only converts "true"/"false" — so "1", "on", "0" or
 * "off" arrive as text. A boolean word is read as the switch it names rather than as a
 * CA bundle file called "0". null and an empty string verify against the system bundle;
 * any other string is a CA bundle path (a typo'd path fails the TLS handshake, it never
 * disables verification). Only an explicit false-ish value (`false`, `0`, `"0"`,
 * `"off"`, `"no"`, `"false"`) disables verification, and a value of any other type (an
 * array, a float, an int other than 0/1) throws rather than being guessed at.
 *
 * @internal shared by the driver factories and the `about` row so both read it the same way
 */
final class TlsVerification
{
    /**
     * @param  string  $key  the config key, named in the error
     *
     * @throws InvalidConfigurationException when the setting is neither a bool, a 0/1 int nor a string
     */
    public static function from(mixed $setting, string $key): string|bool
    {
        if ($setting === null || (is_string($setting) && trim($setting) === '')) {
            return true;
        }

        if (is_bool($setting)) {
            return $setting;
        }

        if ($setting === 0 || $setting === 1) {
            return $setting === 1;
        }

        if (! is_string($setting)) {
            throw new InvalidConfigurationException("Configuration value [{$key}] must be a CA bundle path or a boolean, [".get_debug_type($setting).'] given.');
        }

        return filter_var($setting, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $setting;
    }
}
