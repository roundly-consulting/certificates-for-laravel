<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateRenewed;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Models\Certificate;
use Throwable;

/**
 * Renew one registry certificate through its own driver.
 *
 * The row moves Issued/Renewed → Renewing → Renewed. When the provider throws, the row
 * moves to Failed and CertificateFailed fires before the exception is rethrown — it is
 * never left stuck in Renewing, a status nothing can renew out of.
 *
 * Reach it through `Certificates::renew()` / `Certificates::for($domain)->renew()`.
 */
final readonly class RenewCertificateAction
{
    public function __construct(
        private CertificateProviderManager $manager,
    ) {}

    public function execute(Certificate $certificate): Certificate
    {
        if (! $certificate->status->canTransitionTo(CertificateStatus::Renewing)) {
            throw CertificateException::illegalTransition($certificate->status, CertificateStatus::Renewing);
        }

        $certificate->forceFill(['status' => CertificateStatus::Renewing])->save();

        try {
            $provider = $this->manager->provider($certificate->driver);
            $provider->generate($certificate->name, $certificate->domain);

            $expiresAt = $provider instanceof ReportsCertificateStatus
                ? $provider->status($certificate->name, $certificate->domain)->expiresAt
                : null;
        } catch (Throwable $e) {
            $certificate->markFailed($e->getMessage());
            Event::dispatch(new CertificateFailed($certificate, $e->getMessage()));

            throw $e;
        }

        $certificate->markRenewed($expiresAt ?? now()->addDays(90));

        Event::dispatch(new CertificateRenewed($certificate));

        return $certificate;
    }
}
