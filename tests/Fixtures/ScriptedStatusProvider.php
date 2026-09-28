<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Contracts\ProvisionsMultipleDomains;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RuntimeException;

/**
 * A provider whose status report the test scripts: it provisions nothing real, records
 * every provisioning call, and reports whatever `$report` currently holds — so a test can
 * play a backend that is still issuing, has failed, or has handed back a new certificate.
 */
final class ScriptedStatusProvider implements CertificateProvider, ProvisionsMultipleDomains, ReportsCertificateStatus
{
    /** @var list<list<string>> */
    public array $provisioned = [];

    /** @var list<int> which provisioning calls (1-based) throw */
    public array $failOnCalls = [];

    /** When set, status() throws a RuntimeException with this message. */
    public ?string $statusFailure = null;

    public function __construct(
        public CertificateStatusReport $report = new CertificateStatusReport(status: CertificateStatus::Issued),
    ) {}

    public function get(): Collection
    {
        return Collection::make();
    }

    public function exists(string $name, string $domain): bool
    {
        return $this->provisioned !== [];
    }

    public function generate(string $name, string $domain): void
    {
        $this->generateMany($name, [$domain]);
    }

    public function generateMany(string $name, array $domains): void
    {
        $this->provisioned[] = $domains;

        if (in_array(count($this->provisioned), $this->failOnCalls, true)) {
            throw new RuntimeException('transient CA error');
        }
    }

    public function status(string $name, string $domain): CertificateStatusReport
    {
        if ($this->statusFailure !== null) {
            throw new RuntimeException($this->statusFailure);
        }

        return $this->report;
    }
}
