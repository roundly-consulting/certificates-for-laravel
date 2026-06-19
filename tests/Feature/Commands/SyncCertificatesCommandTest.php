<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\CertificateManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('pulls live provider state into the registry', function (): void {
    $provider = new ArrayProvider;
    $provider->generate('generated-tls-a-com', 'a.example.com');

    app(CertificateManager::class)->extend('array', fn () => $provider);

    $this->artisan('certificates:sync', ['--driver' => 'array'])
        ->assertExitCode(0);

    expect(Certificate::query()->forDomain('a.example.com')->first())
        ->not->toBeNull()
        ->status->toBe(CertificateStatus::Issued);
});

it('syncs using the default driver when none is given', function (): void {
    $provider = new ArrayProvider;
    $provider->generate('generated-tls-c-com', 'c.example.com');

    app(CertificateManager::class)->extend('array', fn () => $provider);

    $this->artisan('certificates:sync')->assertExitCode(0);

    expect(Certificate::query()->forDomain('c.example.com')->exists())->toBeTrue();
});

it('syncs a provider without status reporting', function (): void {
    app(CertificateManager::class)->extend('plain', fn () => new class implements CertificateProvider
    {
        public function get(): Collection
        {
            return collect([new RemoteCertificate('generated-tls-b-com', 'b.example.com')]);
        }

        public function exists(string $name, string $domain): bool
        {
            return true;
        }

        public function generate(string $name, string $domain): void {}
    });

    $this->artisan('certificates:sync', ['--driver' => 'plain'])
        ->assertExitCode(0);

    expect(Certificate::query()->forDomain('b.example.com')->first()->status)
        ->toBe(CertificateStatus::Issued);
});
