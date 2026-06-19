<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\Certificates\Certificate;
use RoundlyConsulting\Certificates\CertificateService;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;

function fakeProvider(): CertificateProvider
{
    return new class implements CertificateProvider
    {
        public int $generated = 0;

        public function get(): Collection
        {
            return collect([new Certificate('generated-tls-example-com', 'example.com')]);
        }

        public function exists(string $name, string $domain): bool
        {
            return $domain === 'existing.com';
        }

        public function generate(string $name, string $domain): void
        {
            $this->generated++;
        }
    };
}

it('derives a dns-safe certificate name with the configured prefix', function (): void {
    $service = new CertificateService(fakeProvider());

    expect($service->certificateName('app.example.com'))
        ->toBe('generated-tls-app-example-com');
});

it('replaces dots and colons in the derived name', function (): void {
    $service = new CertificateService(fakeProvider());

    expect($service->certificateName('app.example.com:8443'))
        ->toBe('generated-tls-app-example-com-8443');
});

it('proxies get to the provider', function (): void {
    $service = new CertificateService(fakeProvider());

    expect($service->get())
        ->toHaveCount(1)
        ->first()->domain->toBe('example.com');
});

it('reports existence via the provider', function (): void {
    $service = new CertificateService(fakeProvider());

    expect($service->exists('existing.com'))->toBeTrue();
    expect($service->exists('missing.com'))->toBeFalse();
});

it('generates a certificate when the lock is free', function (): void {
    $provider = fakeProvider();
    $service = new CertificateService($provider);

    expect($service->generate('example.com'))->toBeTrue();
    expect($provider->generated)->toBe(1);
});

it('returns false when the lock cannot be acquired', function (): void {
    $provider = fakeProvider();
    $service = new CertificateService($provider);

    // Hold the lock under the exact owner the service will use.
    $owner = $service->certificateName('example.com');
    Cache::lock('certificates:generate', 5, $owner);
    $held = Cache::lock('certificates:generate', 5, 'someone-else');
    $held->get();

    expect($service->generate('example.com'))->toBeFalse();
    expect($provider->generated)->toBe(0);

    $held->forceRelease();
});
