<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CertificateModel;

final class ListCertificatesCommand extends Command
{
    protected $signature = 'certificates:list {--driver=} {--status=} {--expiring=} {--connection=}';

    protected $description = 'List certificates from the local registry';

    public function handle(): int
    {
        $connection = is_string($connection = $this->option('connection')) && $connection !== ''
            ? $connection
            : null;

        $query = CertificateModel::class()::on($connection);

        if (is_string($driver = $this->option('driver')) && $driver !== '') {
            $query->forDriver($driver);
        }

        if (is_string($status = $this->option('status')) && $status !== '') {
            $query->where('status', $status);
        }

        if (is_numeric($expiring = $this->option('expiring'))) {
            $query->expiring((int) $expiring);
        }

        $certificates = $query->orderBy('domain')->get();

        if ($certificates->isEmpty()) {
            $this->info((string) trans('certificates::messages.commands.none_found'));

            return self::SUCCESS;
        }

        $this->table(
            ['Domain', 'Driver', 'Status', 'Expires at'],
            $certificates->map(fn (Certificate $certificate): array => [
                $certificate->domain,
                $certificate->driver,
                $certificate->status->label(),
                $certificate->expires_at?->toDateTimeString() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
