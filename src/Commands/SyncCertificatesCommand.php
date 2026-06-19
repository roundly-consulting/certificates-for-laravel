<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Certificates\CertificateManager;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;

final class SyncCertificatesCommand extends Command
{
    protected $signature = 'certificates:sync {--driver=}';

    protected $description = 'Pull live provider state into the local registry';

    public function handle(CertificateManager $manager): int
    {
        $driverName = is_string($driver = $this->option('driver')) && $driver !== ''
            ? $driver
            : $manager->getDefaultDriver();

        $provider = $manager->provider($driverName);

        $count = 0;

        foreach ($provider->get() as $remote) {
            $model = Certificate::query()->firstOrNew([
                'driver' => $driverName,
                'name' => $remote->name,
            ]);

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

        $this->info((string) trans('certificates::messages.commands.synced', [
            'count' => $count,
            'driver' => $driverName,
        ]));

        return self::SUCCESS;
    }
}
