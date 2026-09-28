<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Certificates\Actions\IssueCertificateAction;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;

final class IssueCertificateCommand extends Command
{
    protected $signature = 'certificates:issue {domain} {--driver=}';

    protected $description = 'Issue a TLS certificate for a domain';

    public function handle(IssueCertificateAction $action): int
    {
        /** @var string $domain */
        $domain = $this->argument('domain');

        $this->info((string) trans('certificates::messages.commands.issuing', ['domain' => $domain]));

        try {
            $certificate = $action->execute(new IssueCertificateData(
                domain: $domain,
                driver: $this->stringOption('driver'),
            ));
        } catch (CertificateException $e) {
            $this->error((string) trans('certificates::messages.commands.failed', [
                'domain' => $domain,
                'reason' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }

        $this->info((string) trans('certificates::messages.commands.issued', [
            'domain' => $domain,
            'status' => $certificate->status->label(),
        ]));

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
