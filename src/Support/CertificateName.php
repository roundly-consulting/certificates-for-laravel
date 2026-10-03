<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use Illuminate\Support\Str;
use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * The deterministic secret / registry name for a domain: `{name_prefix}{domain}`, folded
 * into a DNS-1123 subdomain Kubernetes accepts as a Secret and Certificate name —
 * lowercase (hostnames are case-insensitive, so `App.example.com` is the same certificate
 * as `app.example.com`), `[a-z0-9-]` only (a wildcard's `*.` becomes `wildcard-`), at most
 * 253 characters (an over-long name is cut and suffixed with a hash of the full one, so
 * two long domains never share a name).
 *
 * @internal read it through `Certificates::certificateName()`
 */
final class CertificateName
{
    private const MAX_LENGTH = 253;

    public static function for(string $domain): string
    {
        // '' is a valid prefix (names are the bare host); a non-string one throws.
        $prefix = config('certificates.name_prefix') ?? 'generated-tls-';

        if (! is_string($prefix)) {
            throw InvalidConfigurationException::notAString('certificates.name_prefix', $prefix);
        }

        $host = Str::lower(trim($domain));

        if (str_starts_with($host, '*.')) {
            $host = 'wildcard.'.substr($host, 2);
        }

        $name = Str::lower($prefix).$host;
        $name = trim((string) preg_replace('/[^a-z0-9-]+/', '-', $name), '-');

        if (strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        $hash = substr((new Digest)->hex($name), 0, 10);

        return rtrim(substr($name, 0, self::MAX_LENGTH - strlen($hash) - 1), '-').'-'.$hash;
    }
}
