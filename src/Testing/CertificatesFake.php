<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Testing;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Certificates\CertificateService;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CertificateBuilder;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

/**
 * In-memory test double for the certificate service. Records issue/request
 * calls so tests can assert against them without touching a real provider.
 */
final class CertificatesFake extends CertificateService
{
    /** @var list<string> */
    private array $requested = [];

    /** @var list<string> */
    private array $issued = [];

    /** @var list<string> */
    private array $failed = [];

    /** @var array<string, Certificate> */
    private array $store = [];

    public function __construct()
    {
        // The fake stands alone; it does not delegate to a manager/action.
    }

    public function get(): Collection
    {
        return Collection::make(array_map(
            fn (Certificate $certificate): RemoteCertificate => new RemoteCertificate($certificate->name, $certificate->domain),
            array_values($this->store),
        ));
    }

    public function exists(string $domain): bool
    {
        return isset($this->store[$domain]);
    }

    public function generate(string $domain): bool
    {
        $this->issue(IssueCertificateData::make($domain));

        return true;
    }

    public function issue(IssueCertificateData $data): Certificate
    {
        $this->requested[] = $data->domain;

        $certificate = new Certificate;
        $certificate->forceFill([
            'name' => $this->certificateName($data->domain),
            'domain' => $data->domain,
            'driver' => $data->driver ?? 'array',
            'status' => CertificateStatus::Issued,
            'issuer' => $data->issuer,
            'issued_at' => now(),
            'expires_at' => now()->addDays($data->validForDays ?? 90),
        ]);

        $this->store[$data->domain] = $certificate;
        $this->issued[] = $data->domain;

        return $certificate;
    }

    public function issueIfMissing(string $domain): Certificate
    {
        return $this->store[$domain] ?? $this->issue(IssueCertificateData::make($domain));
    }

    public function for(string $domain): CertificateBuilder
    {
        return new CertificateBuilder($this, $domain);
    }

    public function find(string $domain, ?string $driver = null): ?Certificate
    {
        return $this->store[$domain] ?? null;
    }

    public function status(string $domain): ?CertificateStatus
    {
        return $this->store[$domain]->status ?? null;
    }

    /**
     * Record an intentionally-failed issuance (for tests exercising failure).
     */
    public function recordFailure(string $domain): void
    {
        $this->requested[] = $domain;
        $this->failed[] = $domain;
    }

    public function assertIssued(string $domain): void
    {
        Assert::assertContains($domain, $this->issued, "Expected a certificate to be issued for [{$domain}].");
    }

    public function assertNotIssued(string $domain): void
    {
        Assert::assertNotContains($domain, $this->issued, "Expected no certificate to be issued for [{$domain}].");
    }

    public function assertIssuedCount(int $count): void
    {
        Assert::assertCount($count, $this->issued, "Expected [{$count}] certificate(s) to be issued.");
    }

    public function assertRequested(string $domain): void
    {
        Assert::assertContains($domain, $this->requested, "Expected a certificate to be requested for [{$domain}].");
    }

    public function assertFailed(string $domain): void
    {
        Assert::assertContains($domain, $this->failed, "Expected a certificate to fail for [{$domain}].");
    }
}
