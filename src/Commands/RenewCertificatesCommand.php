<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\Actions\RenewCertificateAction;
use RoundlyConsulting\Certificates\Events\CertificateExpiring;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Support\CertificateModel;

final class RenewCertificatesCommand extends Command
{
    protected $signature = 'certificates:renew {domain?} {--threshold=} {--queue} {--connection=}';

    protected $description = 'Renew certificates that are expiring (or a single domain)';

    public function handle(RenewCertificateAction $action): int
    {
        $connection = is_string($connection = $this->option('connection')) && $connection !== ''
            ? $connection
            : null;

        $query = CertificateModel::class()::on($connection);

        if (is_string($domain = $this->argument('domain')) && $domain !== '') {
            $query->forDomain($domain);
        } else {
            $threshold = is_numeric($this->option('threshold'))
                ? (int) $this->option('threshold')
                : (int) config('certificates.renewal.threshold_days', 21);

            $query->expiring($threshold);
        }

        $certificates = $query->get();

        if ($certificates->isEmpty()) {
            $this->info((string) trans('certificates::messages.commands.none_to_renew'));

            return self::SUCCESS;
        }

        $queue = (bool) $this->option('queue');

        foreach ($certificates as $certificate) {
            Event::dispatch(new CertificateExpiring(
                $certificate,
                $certificate->daysUntilExpiry() ?? 0,
            ));

            if ($queue) {
                RenewCertificateJob::dispatch($certificate->id);
                $this->info((string) trans('certificates::messages.commands.queued', ['domain' => $certificate->domain]));

                continue;
            }

            $this->info((string) trans('certificates::messages.commands.renewing', ['domain' => $certificate->domain]));
            $action->execute($certificate);
            $this->info((string) trans('certificates::messages.commands.renewed', ['domain' => $certificate->domain]));
        }

        return self::SUCCESS;
    }
}
