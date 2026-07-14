<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing the certificate registry from
 * `certificates.model`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; anything that isn't a Certificate (so it can't answer the
 * package's scopes and lifecycle methods) falls back to the packaged model.
 */
final class CertificateModel
{
    /**
     * @return class-string<Certificate>
     */
    public static function class(): string
    {
        $model = ModelResolver::for('certificates.model', Certificate::class);

        return is_a($model, Certificate::class, true) ? $model : Certificate::class;
    }
}
