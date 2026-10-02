<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Caches provider status() lookups so repeated checks don't hit the backend
 * (e.g. an HTTP round-trip per call) on every request. The issue and renew
 * actions forget a certificate's entry after provisioning, so a report never
 * outlives the change it describes.
 */
final class CachedStatusResolver
{
    public function __construct(
        private readonly CertificateProviderManager $manager,
    ) {}

    public function resolve(string $driver, string $name, string $domain, bool $fresh = false): ?CertificateStatusReport
    {
        $provider = $this->manager->provider($driver);

        if (! $provider instanceof ReportsCertificateStatus) {
            return null;
        }

        if (! $this->enabled() || $fresh) {
            $report = $provider->status($name, $domain);

            if ($this->enabled()) {
                $this->store()->put($this->key($driver, $name), $report, $this->ttl());
            }

            return $report;
        }

        /** @var CertificateStatusReport $report */
        $report = $this->store()->remember(
            $this->key($driver, $name),
            $this->ttl(),
            fn (): CertificateStatusReport => $provider->status($name, $domain),
        );

        return $report;
    }

    public function forget(string $driver, string $name): void
    {
        $this->store()->forget($this->key($driver, $name));
    }

    private function enabled(): bool
    {
        return Config::boolean('certificates.status_cache.enabled', true);
    }

    private function ttl(): int
    {
        return (int) config('certificates.status_cache.ttl', 300);
    }

    private function store(): Repository
    {
        $store = config('certificates.status_cache.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    private function key(string $driver, string $name): string
    {
        return "certificates:status:{$driver}:{$name}";
    }
}
