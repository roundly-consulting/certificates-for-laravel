<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Certificates\CertificatesManager;

/**
 * A thin caller of `Certificates::prune()`.
 */
final class PruneCertificatesCommand extends Command
{
    protected $signature = 'certificates:prune {--days=30} {--status=} {--connection=}';

    protected $description = 'Soft-delete stale expired/failed certificate records';

    public function handle(CertificatesManager $certificates): int
    {
        $connection = is_string($connection = $this->option('connection')) && $connection !== ''
            ? $connection
            : null;

        $days = is_numeric($this->option('days')) ? (int) $this->option('days') : 30;
        $status = is_string($status = $this->option('status')) && $status !== '' ? $status : null;

        $count = $certificates->on($connection)->prune($days, $status);

        $this->info((string) trans('certificates::messages.commands.pruned', ['count' => $count]));

        return self::SUCCESS;
    }
}
