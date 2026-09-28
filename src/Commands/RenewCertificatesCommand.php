<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;

/**
 * A thin caller: `Certificates::renewDue()` for the expiring set, or
 * `Certificates::renew()` / `renewLater()` for one domain. Prints every certificate's
 * outcome and exits non-zero when any of them failed.
 */
final class RenewCertificatesCommand extends Command
{
    protected $signature = 'certificates:renew {domain?} {--threshold=} {--queue} {--connection=}';

    protected $description = 'Renew certificates that are expiring (or a single domain)';

    public function handle(CertificatesManager $certificates): int
    {
        $certificates = $certificates->on($this->stringOption('connection'));
        $queue = (bool) $this->option('queue');

        if (is_string($domain = $this->argument('domain')) && $domain !== '') {
            return $this->renewDomain($certificates, $domain, $queue);
        }

        $threshold = is_numeric($this->option('threshold')) ? (int) $this->option('threshold') : null;

        $report = $certificates->renewDue($threshold, $queue);

        if ($report->isEmpty()) {
            $this->info((string) trans('certificates::messages.commands.none_to_renew'));

            return self::SUCCESS;
        }

        foreach ($report->renewed as $certificate) {
            $this->info((string) trans('certificates::messages.commands.renewed', ['domain' => $certificate->domain]));
        }

        foreach ($report->queued as $certificate) {
            $this->info((string) trans('certificates::messages.commands.queued', ['domain' => $certificate->domain]));
        }

        foreach ($report->failed as $failure) {
            $this->error((string) trans('certificates::messages.commands.renew_failed', [
                'domain' => $failure->certificate->domain,
                'reason' => $failure->reason(),
            ]));
        }

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    private function renewDomain(CertificatesManager $certificates, string $domain, bool $queue): int
    {
        $certificate = $certificates->find($domain);

        if ($certificate === null) {
            $this->info((string) trans('certificates::messages.commands.none_to_renew'));

            return self::SUCCESS;
        }

        try {
            if ($queue) {
                $certificates->renewLater($certificate);
                $this->info((string) trans('certificates::messages.commands.queued', ['domain' => $domain]));

                return self::SUCCESS;
            }

            $this->info((string) trans('certificates::messages.commands.renewing', ['domain' => $domain]));
            $certificates->renew($certificate);
        } catch (CertificateException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info((string) trans('certificates::messages.commands.renewed', ['domain' => $domain]));

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
