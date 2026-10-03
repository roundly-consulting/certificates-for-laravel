<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing the certificate registry from
 * `certificates.model`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class CertificateModel
{
    /**
     * @return class-string<Certificate>
     */
    public static function class(): string
    {
        return ModelResolver::for('certificates.model', Certificate::class);
    }
}
