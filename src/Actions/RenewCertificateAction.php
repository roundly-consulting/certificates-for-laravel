<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\ProvisionsMultipleDomains;
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
 * The row moves Issued/Renewed/Failed → Renewing → Renewed. The provider's own report is
 * the proof: a status that is not live, or the very same certificate (same fingerprint),
 * is a failed renewal. When the renewal fails the row moves to Failed and CertificateFailed
 * fires before the exception is rethrown — it is never left stuck in Renewing, and a Failed
 * row stays renewable (renewDue() retries it).
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

        $previousFingerprint = $certificate->fingerprint;

        $certificate->forceFill(['status' => CertificateStatus::Renewing])->save();

        try {
            $provider = $this->manager->provider($certificate->driver);
            $domains = $certificate->domains ?? [$certificate->domain];

            // A SAN certificate is re-provisioned for every domain it covers — renewing only
            // the primary would silently drop the other hosts from the live certificate.
            if ($provider instanceof ProvisionsMultipleDomains && count($domains) > 1) {
                $provider->generateMany($certificate->name, $domains);
            } else {
                $provider->generate($certificate->name, $certificate->domain);
            }

            $report = $provider instanceof ReportsCertificateStatus
                ? $provider->status($certificate->name, $certificate->domain)
                : null;

            if ($report !== null && ! $report->status->isActive()) {
                throw CertificateException::providerReported($certificate->driver, $certificate->domain, $report->status);
            }

            // Same fingerprint = the same certificate: nothing was renewed, whatever the
            // provider call returned (e.g. imported material nobody replaced).
            if ($report?->fingerprint !== null && $report->fingerprint === $previousFingerprint) {
                throw CertificateException::notRenewed($certificate->driver, $certificate->domain);
            }
        } catch (Throwable $e) {
            $certificate->markFailed($e->getMessage());
            Event::dispatch(new CertificateFailed($certificate, $e->getMessage()));

            throw $e;
        }

        $certificate->forceFill([
            'issuer' => $report->issuer ?? $certificate->issuer,
            'serial' => $report->serial ?? $certificate->serial,
            'fingerprint' => $report->fingerprint ?? $certificate->fingerprint,
        ]);

        $certificate->markRenewed($report->expiresAt ?? now()->addDays(90));

        Event::dispatch(new CertificateRenewed($certificate));

        return $certificate;
    }
}
