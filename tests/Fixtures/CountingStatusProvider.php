<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;

/**
 * Counts status() calls so the test can assert cache hits versus misses.
 */
final class CountingStatusProvider implements CertificateProvider, ReportsCertificateStatus
{
    public int $calls = 0;

    public function get(): Collection
    {
        return Collection::make();
    }

    public function exists(string $name, string $domain): bool
    {
        return false;
    }

    public function generate(string $name, string $domain): void {}

    public function status(string $name, string $domain): CertificateStatusReport
    {
        $this->calls++;

        return new CertificateStatusReport(status: CertificateStatus::Issued);
    }
}
