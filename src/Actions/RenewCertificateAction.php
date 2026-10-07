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
use RoundlyConsulting\Certificates\Exceptions\ProvisioningInProgressException;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CachedStatusResolver;
use RoundlyConsulting\Certificates\Support\ProvisioningLock;
use Throwable;

/**
 * Renew one registry certificate through its own driver.
 *
 * The row moves Issued/Renewed/Failed → Renewing → Renewed. The provider's own report is
 * the proof: a status that is not live, the very same certificate (same fingerprint), or —
 * when the provider reports no fingerprint — an expiry no later than before, is a failed
 * renewal. When the renewal fails the row moves to Failed and CertificateFailed
 * fires before the exception is rethrown — it is never left stuck in Renewing, and a Failed
 * row stays renewable (renewDue() retries it).
 *
 * A renewal holds the certificate's provisioning lock (the one issue() takes), and the row
 * moves to Renewing only if it is still in the status this process read: a concurrent
 * renewal, or a model loaded before another process renewed it, throws
 * ProvisioningInProgressException instead of renewing twice. A row an interrupted renewal
 * left in Renewing for longer than the lock lives is renewed again rather than stranded.
 *
 * Reach it through `Certificates::renew()` / `Certificates::for($domain)->renew()`.
 */
final readonly class RenewCertificateAction
{
    public function __construct(
        private CertificateProviderManager $manager,
        private CachedStatusResolver $statusCache,
    ) {}

    public function execute(Certificate $certificate): Certificate
    {
        if (! $certificate->canRenew()) {
            throw CertificateException::illegalTransition($certificate->status, CertificateStatus::Renewing);
        }

        $lock = ProvisioningLock::for($certificate->name);

        if (! $lock->get()) {
            throw ProvisioningInProgressException::forDomain($certificate->domain);
        }

        try {
            return $this->renew($certificate);
        } finally {
            $lock->release();
        }
    }

    private function renew(Certificate $certificate): Certificate
    {
        $previousFingerprint = $certificate->fingerprint;
        $previousExpiresAt = $certificate->expires_at;

        $this->claim($certificate);

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

            // Without a fingerprint the expiry is the proof: a renewed certificate runs out
            // later than the one it replaces. cert-manager renews inside the cluster and keeps
            // reporting Ready while its renewal fails, so an unchanged notAfter is no renewal.
            if ($report !== null && $report->fingerprint === null && $report->expiresAt !== null
                && $previousExpiresAt !== null && ! $report->expiresAt->greaterThan($previousExpiresAt)) {
                throw CertificateException::notRenewed($certificate->driver, $certificate->domain);
            }
        } catch (Throwable $e) {
            $certificate->markFailed($e->getMessage());
            Event::dispatch(new CertificateFailed($certificate, $e->getMessage()));

            throw $e;
        } finally {
            // Whatever happened, the backend changed: a cached report predates it.
            $this->statusCache->forget($certificate->driver, $certificate->name);
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

    /**
     * Move the row to Renewing in one conditional update: only while it still holds the
     * status this process read. A model loaded before another process renewed (or
     * re-issued) the certificate must not renew it a second time.
     */
    private function claim(Certificate $certificate): void
    {
        $values = ['status' => CertificateStatus::Renewing];

        if ($certificate->usesTimestamps()) {
            $values[$certificate->getUpdatedAtColumn()] = $certificate->freshTimestamp();
        }

        $claimed = $certificate->newQuery()
            ->whereKey($certificate->getKey())
            ->where('status', $certificate->getOriginal('status'))
            // A stuck Renewing row is taken over once: whoever reclaims it first moves its
            // timestamp, and a second process holding the same stale model then matches nothing.
            ->when($certificate->isStaleRenewal(), fn ($query) => $query->where(
                $certificate->getUpdatedAtColumn() ?? 'updated_at',
                $certificate->getOriginal($certificate->getUpdatedAtColumn() ?? 'updated_at'),
            ))
            ->update($values);

        if ($claimed !== 1) {
            throw ProvisioningInProgressException::forDomain($certificate->domain);
        }

        $certificate->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }
}
