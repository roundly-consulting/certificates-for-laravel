<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\CertificatesManager;

/**
 * A thin caller of `Certificates::sync()`.
 */
final class SyncCertificatesCommand extends Command
{
    protected $signature = 'certificates:sync {--driver=} {--connection=}';

    protected $description = 'Pull live provider state into the local registry';

    public function handle(CertificatesManager $certificates, CertificateProviderManager $providers): int
    {
        $connection = is_string($connection = $this->option('connection')) && $connection !== ''
            ? $connection
            : null;

        $driver = is_string($driver = $this->option('driver')) && $driver !== ''
            ? $driver
            : $providers->getDefaultDriver();

        $count = $certificates->on($connection)->sync($driver);

        $this->info((string) trans('certificates::messages.commands.synced', [
            'count' => $count,
            'driver' => $driver,
        ]));

        return self::SUCCESS;
    }
}
