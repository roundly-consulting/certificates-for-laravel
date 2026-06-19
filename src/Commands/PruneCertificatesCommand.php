<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;

final class PruneCertificatesCommand extends Command
{
    protected $signature = 'certificates:prune {--days=30} {--status=}';

    protected $description = 'Soft-delete stale expired/failed certificate records';

    public function handle(): int
    {
        $days = is_numeric($this->option('days')) ? (int) $this->option('days') : 30;

        $query = Certificate::query()
            ->where('updated_at', '<=', CarbonImmutable::now()->subDays($days));

        if (is_string($status = $this->option('status')) && $status !== '') {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', [
                CertificateStatus::Expired->value,
                CertificateStatus::Failed->value,
                CertificateStatus::Revoked->value,
            ]);
        }

        $count = 0;

        foreach ($query->get() as $certificate) {
            $certificate->delete();
            $count++;
        }

        $this->info((string) trans('certificates::messages.commands.pruned', ['count' => $count]));

        return self::SUCCESS;
    }
}
