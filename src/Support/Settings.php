<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Strict readers for the package's non-boolean settings. An absent (null) key takes its
 * default; a present value of the wrong shape throws the toolkit's
 * {@see InvalidConfigurationException} naming the key — `'five'` never becomes a 0-day
 * threshold, and a mistyped string never silently reads as the default.
 *
 * Callers hand in the value they read (a `config()` call or a driver-section offset), so
 * every read stays visible to the config contract.
 *
 * @internal the package's own config wiring — hosts configure `config/certificates.php`.
 */
final class Settings
{
    /**
     * An int or a canonical integer string (env values arrive as strings) within the
     * bounds; `$default` only when `$value` is null.
     *
     * @throws InvalidConfigurationException
     */
    public static function integer(string $key, mixed $value, int $default, ?int $min = null, ?int $max = null): int
    {
        return Config::for([$key => $value])->integer($key, $default, $min, $max);
    }

    /**
     * A required string: `$default` only when `$value` is null; a blank or non-string value
     * throws.
     *
     * @throws InvalidConfigurationException
     */
    public static function string(string $key, mixed $value, string $default): string
    {
        $value ??= $default;

        if (! is_string($value) || trim($value) === '') {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return $value;
    }

    /**
     * An optional string: null when absent or blank (an empty env value reads as unset);
     * a value that is not a string throws.
     *
     * @throws InvalidConfigurationException
     */
    public static function optionalString(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return trim($value) === '' ? null : $value;
    }

    /**
     * A list of non-blank strings; `$default` only when absent. A non-list, or any entry
     * that is not a non-blank string, throws.
     *
     * @param  list<string>  $default
     * @return list<string>
     *
     * @throws InvalidConfigurationException
     */
    public static function strings(string $key, mixed $value, array $default): array
    {
        $value ??= $default;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidConfigurationException("Configuration value [{$key}] must be a list of non-empty strings, [".get_debug_type($value).'] given.');
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidConfigurationException("Configuration value [{$key}] must be a list of non-empty strings, an entry of type [".get_debug_type($item).'] given.');
            }
        }

        return $value;
    }
}
