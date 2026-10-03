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
 * (certificates.alerts.notifiable, resolved from the container to a stored model),
 * then the certificate's own certifiable owner.
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

            // An unbound class is built fresh, with no key: alerts could not store a monitor
            // or an alert against it. The host binds the class to the stored record.
            if (! $resolved->exists) {
                throw new InvalidConfigurationException('Configuration value [certificates.alerts.notifiable] resolved to an unsaved ['.$resolved::class.'] model; bind the class in the container to a stored record, e.g. $this->app->bind('.class_basename($resolved).'::class, fn () => '.class_basename($resolved).'::query()->firstOrFail()).');
            }

            return $resolved;
        }

        $certifiable = $certificate->certifiable;

        return $certifiable instanceof Model ? $certifiable : null;
    }
}
