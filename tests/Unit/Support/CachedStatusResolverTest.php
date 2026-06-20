<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\CertificateManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Providers\NullProvider;
use RoundlyConsulting\Certificates\Support\CachedStatusResolver;

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

function bindProvider(CertificateProvider $provider): void
{
    app(CertificateManager::class)->extend('array', fn (): CertificateProvider => $provider);
}

it('caches the first lookup and serves the second from cache', function (): void {
    $provider = new CountingStatusProvider;
    bindProvider($provider);

    $resolver = new CachedStatusResolver(app(CertificateManager::class));

    $resolver->resolve('array', 'tls-app', 'app.com');
    $resolver->resolve('array', 'tls-app', 'app.com');

    expect($provider->calls)->toBe(1);
});

it('bypasses the cache and refreshes it when fresh is true', function (): void {
    $provider = new CountingStatusProvider;
    bindProvider($provider);

    $resolver = new CachedStatusResolver(app(CertificateManager::class));

    $resolver->resolve('array', 'tls-app', 'app.com');
    $resolver->resolve('array', 'tls-app', 'app.com', fresh: true);

    expect($provider->calls)->toBe(2);
});

it('always hits the provider when caching is disabled', function (): void {
    config()->set('certificates.status_cache.enabled', false);
    $provider = new CountingStatusProvider;
    bindProvider($provider);

    $resolver = new CachedStatusResolver(app(CertificateManager::class));

    $resolver->resolve('array', 'tls-app', 'app.com');
    $resolver->resolve('array', 'tls-app', 'app.com');

    expect($provider->calls)->toBe(2);
});

it('returns null for a provider that does not report status', function (): void {
    bindProvider(new NullProvider);

    $resolver = new CachedStatusResolver(app(CertificateManager::class));

    expect($resolver->resolve('array', 'tls-app', 'app.com'))->toBeNull();
});

it('forgets a cached entry', function (): void {
    $provider = new CountingStatusProvider;
    bindProvider($provider);

    $resolver = new CachedStatusResolver(app(CertificateManager::class));

    $resolver->resolve('array', 'tls-app', 'app.com');
    $resolver->forget('array', 'tls-app');
    $resolver->resolve('array', 'tls-app', 'app.com');

    expect($provider->calls)->toBe(2);
});

it('honours a custom cache store', function (): void {
    config()->set('certificates.status_cache.store', 'array');
    $provider = new CountingStatusProvider;
    bindProvider($provider);

    $resolver = new CachedStatusResolver(app(CertificateManager::class));

    $resolver->resolve('array', 'tls-app', 'app.com');
    $resolver->resolve('array', 'tls-app', 'app.com');

    expect($provider->calls)->toBe(1);
});
