<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Alerts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Resolves the alerts notifiable for a certificate with a fixed precedence:
 * an explicitly supplied model, then the configured FQCN
 * (certificates.alerts.notifiable), then the certificate's own certifiable owner.
 */
final class ExpiryNotifiableResolver
{
    public function resolve(Certificate $certificate, ?Model $explicit = null): ?Model
    {
        if ($explicit !== null) {
            return $explicit;
        }

        $configured = config('certificates.alerts.notifiable');

        if (is_string($configured) && $configured !== '') {
            $resolved = app($configured);

            return $resolved instanceof Model ? $resolved : null;
        }

        $certifiable = $certificate->certifiable;

        return $certifiable instanceof Model ? $certifiable : null;
    }
}
