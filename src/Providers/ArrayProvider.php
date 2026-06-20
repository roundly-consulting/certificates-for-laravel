<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Contracts\ProvisionsMultipleDomains;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

/**
 * In-memory provider, primarily backing the test fake. It records every
 * generate() call so callers can assert on them.
 */
final class ArrayProvider implements CertificateProvider, ProvisionsMultipleDomains, ReportsCertificateStatus
{
    /** @var array<string, RemoteCertificate> */
    private array $certificates = [];

    /** @var list<array{name: string, domain: string}> */
    private array $generated = [];

    /** @var list<array{name: string, domains: list<string>}> */
    private array $generatedMany = [];

    /**
     * @return Collection<int, RemoteCertificate>
     */
    public function get(): Collection
    {
        return Collection::make(array_values($this->certificates));
    }

    public function exists(string $name, string $domain): bool
    {
        return isset($this->certificates[$name]);
    }

    public function generate(string $name, string $domain): void
    {
        $this->certificates[$name] = new RemoteCertificate(name: $name, domain: $domain);
        $this->generated[] = ['name' => $name, 'domain' => $domain];
    }

    /**
     * @param  list<string>  $domains
     */
    public function generateMany(string $name, array $domains): void
    {
        $this->certificates[$name] = new RemoteCertificate(name: $name, domain: $domains[0]);
        $this->generatedMany[] = ['name' => $name, 'domains' => $domains];
    }

    public function status(string $name, string $domain): CertificateStatusReport
    {
        return new CertificateStatusReport(
            status: isset($this->certificates[$name]) ? CertificateStatus::Issued : CertificateStatus::Pending,
            expiresAt: isset($this->certificates[$name]) ? CarbonImmutable::now()->addDays(90) : null,
        );
    }

    /**
     * @return list<array{name: string, domain: string}>
     */
    public function generatedCalls(): array
    {
        return $this->generated;
    }

    /**
     * @return list<array{name: string, domains: list<string>}>
     */
    public function generatedManyCalls(): array
    {
        return $this->generatedMany;
    }
}
