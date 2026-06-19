<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * @extends Factory<Certificate>
 */
final class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = $this->faker->domainName();

        return [
            'name' => 'generated-tls-'.str_replace('.', '-', $domain),
            'domain' => $domain,
            'driver' => 'kubernetes',
            'status' => CertificateStatus::Pending,
            'issuer' => null,
            'serial' => null,
            'fingerprint' => null,
            'issued_at' => null,
            'expires_at' => null,
            'last_renewed_at' => null,
            'last_error' => null,
            'meta' => null,
        ];
    }

    public function issued(): self
    {
        return $this->state(fn (): array => [
            'status' => CertificateStatus::Issued,
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(90),
        ]);
    }

    public function expiring(int $days = 7): self
    {
        return $this->state(fn (): array => [
            'status' => CertificateStatus::Issued,
            'issued_at' => CarbonImmutable::now()->subDays(80),
            'expires_at' => CarbonImmutable::now()->addDays($days),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'status' => CertificateStatus::Expired,
            'issued_at' => CarbonImmutable::now()->subDays(120),
            'expires_at' => CarbonImmutable::now()->subDays(5),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => CertificateStatus::Failed,
            'last_error' => 'provisioning failed',
        ]);
    }

    public function forDomain(string $domain): self
    {
        return $this->state(fn (): array => [
            'domain' => $domain,
            'name' => 'generated-tls-'.str_replace('.', '-', $domain),
        ]);
    }
}
