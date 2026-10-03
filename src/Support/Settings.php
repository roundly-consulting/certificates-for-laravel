<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Strict readers for the package's non-boolean settings. A key that is not set — absent,
 * null or blank (`''` or whitespace, what a host's `KEY=` gives) — takes its default; a
 * present value of the wrong shape throws the toolkit's
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
     * bounds; `$default` when `$value` is not set (null or blank).
     *
     * @throws InvalidConfigurationException
     */
    public static function integer(string $key, mixed $value, int $default, ?int $min = null, ?int $max = null): int
    {
        return Config::for([$key => $value])->integer($key, $default, $min, $max);
    }

    /**
     * A required string: `$default` when `$value` is not set (null or blank); a non-string
     * value throws.
     *
     * @throws InvalidConfigurationException
     */
    public static function string(string $key, mixed $value, string $default): string
    {
        if (self::notSet($value)) {
            return $default;
        }

        if (! is_string($value)) {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return $value;
    }

    /**
     * An optional string: null when not set — absent, null or blank (an empty env value);
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
     * A list of non-blank strings; `$default` when not set (null or blank). A non-list, or
     * any entry that is not a non-blank string, throws.
     *
     * @param  list<string>  $default
     * @return list<string>
     *
     * @throws InvalidConfigurationException
     */
    public static function strings(string $key, mixed $value, array $default): array
    {
        if (self::notSet($value)) {
            return $default;
        }

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

    /**
     * Absent, null or blank (`''` or whitespace): the key is not set, so its default applies.
     */
    public static function notSet(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
