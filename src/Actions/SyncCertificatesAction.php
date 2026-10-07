<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Illuminate\Support\Str;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CertificateModel;

/**
 * Pull a driver's live certificates into the registry, one row per (driver, name).
 * Providers that report status also refresh status, expiry, issuer, serial and fingerprint
 * (a Pending report never demotes a Requested/Renewing row); for the rest a new — or
 * revived pruned — row starts as Issued and an existing row keeps its status. A live
 * Revoked row stays Revoked whatever the provider reports.
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

            if ($revived) {
                $model->forceFill([$model->getDeletedAtColumn() => null]);
            }

            if ($connection !== null) {
                $model->setConnection($connection);
            }

            $model->forceFill(['domain' => Str::lower($remote->domain)]);

            if ($provider instanceof ReportsCertificateStatus) {
                $report = $provider->status($remote->name, $remote->domain);
                $model->forceFill([
                    'status' => $this->statusFor($model, $report->status, $revived),
                    'expires_at' => $report->expiresAt,
                    'issuer' => $report->issuer ?? $model->issuer,
                    'serial' => $report->serial ?? $model->serial,
                    'fingerprint' => $report->fingerprint ?? $model->fingerprint,
                ]);
            } elseif (! $model->exists || $revived) {
                $model->forceFill(['status' => CertificateStatus::Issued]);
            }

            $model->save();
            $count++;
        }

        return $count;
    }

    /**
     * A provider reporting Pending is still issuing; a row already Requested or Renewing
     * says the same thing more precisely, so it keeps its status until there is an outcome.
     */
    private function statusFor(Certificate $model, CertificateStatus $reported, bool $revived): CertificateStatus
    {
        // Revoked is terminal and registry-only: the backend still holds the material, so what
        // it reports never overrides the decision — only a fresh issue() revives the row. (A
        // pruned row a sync revives is a fresh registration, like a new one.)
        if ($model->exists && ! $revived && $model->status === CertificateStatus::Revoked) {
            return CertificateStatus::Revoked;
        }

        $inFlight = $model->exists
            && in_array($model->status, [CertificateStatus::Requested, CertificateStatus::Renewing], true);

        return $reported === CertificateStatus::Pending && $inFlight ? $model->status : $reported;
    }
}
