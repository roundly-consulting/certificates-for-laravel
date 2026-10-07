<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Events\CertificateRenewed;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CertificateModel;

/**
 * Pull a driver's live certificates into the registry, one row per (driver, name).
 * Providers that report status also refresh status, expiry, issuer, serial and fingerprint
 * (a Pending report never demotes a Requested/Renewing row); for the rest a new — or
 * revived pruned — row starts as Issued and an existing row keeps its status. A Revoked
 * row stays Revoked whatever the provider reports, and a pruned Revoked row stays pruned
 * (sync skips it).
 *
 * Sync also settles what was still in flight, completing the lifecycle the way issue() and
 * renew() do: Requested → Issued sets issued_at and fires CertificateIssued; Renewing →
 * Issued with a new certificate (a new fingerprint or, without one, a later expiry) is
 * recorded as Renewed with CertificateRenewed; an existing row moving to Failed fires
 * CertificateFailed. A row sync discovers or revives fires nothing.
 *
 * Reach it through `Certificates::sync()` (what `certificates:sync` runs).
 */
final readonly class SyncCertificatesAction
{
    public function __construct(
        private CertificateProviderManager $manager,
    ) {}

    /**
     * @return int how many remote certificates were written to the registry
     */
    public function execute(?string $driver = null, ?string $connection = null): int
    {
        $driver ??= $this->manager->getDefaultDriver();
        $provider = $this->manager->provider($driver);

        $count = 0;

        foreach ($provider->get() as $remote) {
            // unique(driver, name) also covers pruned rows: a certificate the provider still
            // reports revives its pruned row instead of colliding with it.
            $model = CertificateModel::class()::on($connection)->withTrashed()->firstOrNew([
                'driver' => $driver,
                'name' => $remote->name,
            ]);
            $revived = $model->trashed();

            // A pruned revocation is still a revocation: the backend keeps the material, so
            // listing it never brings the row back — only a fresh issue() does.
            if ($revived && $model->status === CertificateStatus::Revoked) {
                continue;
            }

            if ($revived) {
                $model->forceFill([$model->getDeletedAtColumn() => null]);
            }

            if ($connection !== null) {
                $model->setConnection($connection);
            }

            $model->forceFill(['domain' => Str::lower($remote->domain)]);
            $event = null;

            if ($provider instanceof ReportsCertificateStatus) {
                $report = $provider->status($remote->name, $remote->domain);
                $previous = $model->exists && ! $revived ? clone $model : null;

                $model->forceFill([
                    'status' => $this->statusFor($model, $report->status, $revived),
                    'expires_at' => $report->expiresAt,
                    'issuer' => $report->issuer ?? $model->issuer,
                    'serial' => $report->serial ?? $model->serial,
                    'fingerprint' => $report->fingerprint ?? $model->fingerprint,
                ]);

                $event = $previous === null ? null : $this->settle($model, $previous, $report, $driver);
            } elseif (! $model->exists || $revived) {
                $model->forceFill(['status' => CertificateStatus::Issued]);
            }

            $model->save();
            $count++;

            if ($event !== null) {
                Event::dispatch($event);
            }
        }

        return $count;
    }

    /**
     * Complete the lifecycle step this sync observed on an existing row — the event issue()
     * or renew() would have fired had the outcome been known then. `$previous` is the row as
     * it was before this sync.
     */
    private function settle(Certificate $model, Certificate $previous, CertificateStatusReport $report, string $driver): CertificateIssued|CertificateRenewed|CertificateFailed|null
    {
        if ($model->status === $previous->status) {
            return null;
        }

        if ($previous->status === CertificateStatus::Requested && $model->status === CertificateStatus::Issued) {
            $model->forceFill(['issued_at' => CarbonImmutable::now(), 'last_error' => null]);

            return new CertificateIssued($model);
        }

        if ($previous->status === CertificateStatus::Renewing && $model->status === CertificateStatus::Issued && $this->replaced($previous, $report)) {
            $model->forceFill([
                'status' => CertificateStatus::Renewed,
                'last_renewed_at' => CarbonImmutable::now(),
                'last_error' => null,
            ]);

            return new CertificateRenewed($model);
        }

        if ($model->status === CertificateStatus::Failed) {
            $reason = CertificateException::providerReported($driver, $model->domain, CertificateStatus::Failed)->getMessage();
            $model->forceFill(['last_error' => $reason]);

            return new CertificateFailed($model, $reason);
        }

        return null;
    }

    /**
     * Whether the provider now holds a different certificate than the row recorded — the
     * proof renew() asks for: a new fingerprint, or without one a later expiry.
     */
    private function replaced(Certificate $previous, CertificateStatusReport $report): bool
    {
        if ($report->fingerprint !== null) {
            return $report->fingerprint !== $previous->fingerprint;
        }

        return $report->expiresAt !== null
            && ($previous->expires_at === null || $report->expiresAt->greaterThan($previous->expires_at));
    }

    /**
     * A provider reporting Pending is still issuing; a row already Requested or Renewing
     * says the same thing more precisely, so it keeps its status until there is an outcome.
     */
    private function statusFor(Certificate $model, CertificateStatus $reported, bool $revived): CertificateStatus
    {
        // Revoked is terminal and registry-only: the backend still holds the material, so what
        // it reports never overrides the decision — only a fresh issue() revives the row. (A
        // pruned row a sync revives is a fresh registration, like a new one; a pruned Revoked
        // row is never revived — execute() skips it.)
        if ($model->exists && ! $revived && $model->status === CertificateStatus::Revoked) {
            return CertificateStatus::Revoked;
        }

        $inFlight = $model->exists
            && in_array($model->status, [CertificateStatus::Requested, CertificateStatus::Renewing], true);

        return $reported === CertificateStatus::Pending && $inFlight ? $model->status : $reported;
    }
}
