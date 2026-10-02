<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Listeners;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\Alerts\ExpiryNotifiableResolver;
use RoundlyConsulting\Certificates\Events\CertificateExpired;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateRevoked;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Routes certificate lifecycle failures (failed / revoked / expired) through the
 * alerts engine so they open a throttled, dedup'd Alert against the resolved
 * notifiable. A no-op unless certificates.alerts.enabled is true.
 */
final class AlertOnCertificateLifecycleFailure
{
    public function __construct(
        private readonly ExpiryNotifiableResolver $resolver,
    ) {}

    public function handle(CertificateFailed|CertificateRevoked|CertificateExpired $event): void
    {
        if (! Config::boolean('certificates.alerts.enabled')) {
            return;
        }

        $certificate = $event->certificate;
        $notifiable = $this->resolver->resolve($certificate);

        if (! $notifiable instanceof Model) {
            return;
        }

        Health::for($notifiable)->run(new CertificateExpiryCheck(certificateId: $certificate->id));
    }
}
