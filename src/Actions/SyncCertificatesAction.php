<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Support\CertificateModel;

/**
 * Pull a driver's live certificates into the registry, one row per (driver, name).
 * Providers that report status also refresh status, expiry and issuer; for the rest a
 * new row starts as Issued and an existing row keeps its status.
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
            $model = CertificateModel::class()::on($connection)->firstOrNew([
                'driver' => $driver,
                'name' => $remote->name,
            ]);

            if ($connection !== null) {
                $model->setConnection($connection);
            }

            $model->forceFill(['domain' => $remote->domain]);

            if ($provider instanceof ReportsCertificateStatus) {
                $report = $provider->status($remote->name, $remote->domain);
                $model->forceFill([
                    'status' => $report->status,
                    'expires_at' => $report->expiresAt,
                    'issuer' => $report->issuer ?? $model->issuer,
                ]);
            } elseif (! $model->exists) {
                $model->forceFill(['status' => CertificateStatus::Issued]);
            }

            $model->save();
            $count++;
        }

        return $count;
    }
}
