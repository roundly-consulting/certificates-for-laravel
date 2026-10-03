<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Alerts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\Settings;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

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

        $configured = Settings::optionalString('certificates.alerts.notifiable', config('certificates.alerts.notifiable'));

        if ($configured !== null) {
            $resolved = app($configured);

            // A configured notifiable that resolves to no model would silently send no alert.
            if (! $resolved instanceof Model) {
                throw new InvalidConfigurationException('Configuration value [certificates.alerts.notifiable] must resolve to an Eloquent model, ['.get_debug_type($resolved).'] given.');
            }

            return $resolved;
        }

        $certifiable = $certificate->certifiable;

        return $certifiable instanceof Model ? $certifiable : null;
    }
}
