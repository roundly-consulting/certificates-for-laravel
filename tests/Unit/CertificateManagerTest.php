<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\CertificateManager;
use RoundlyConsulting\Certificates\ChallengeSolvers\DnsChallengeSolver;
use RoundlyConsulting\Certificates\Contracts\AcmeChallengeSolver;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Exceptions\UnknownProviderException;
use RoundlyConsulting\Certificates\Providers\AcmeProvider;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;
use RoundlyConsulting\Certificates\Providers\LocalFilesystemProvider;
use RoundlyConsulting\Certificates\Providers\NullProvider;

function manager(): CertificateManager
{
    return app(CertificateManager::class);
}

it('resolves the default kubernetes driver', function (): void {
    expect(manager()->provider())->toBeInstanceOf(KubernetesProvider::class);
});

it('resolves the null and array drivers', function (): void {
    expect(manager()->provider('null'))->toBeInstanceOf(NullProvider::class)
        ->and(manager()->provider('array'))->toBeInstanceOf(ArrayProvider::class);
});

it('falls back to the legacy providers config block', function (): void {
    config()->set('certificates.drivers.kubernetes', null);
    config()->set('certificates.providers.kubernetes.base_url', 'https://legacy.test');

    app()->forgetInstance(CertificateManager::class);

    expect(manager()->provider('kubernetes'))->toBeInstanceOf(KubernetesProvider::class);
});

it('throws a package exception for an unknown driver', function (): void {
    manager()->provider('does-not-exist');
})->throws(UnknownProviderException::class);

it('resolves a custom driver registered via extend', function (): void {
    $custom = new NullProvider;

    manager()->extend('custom', fn (): CertificateProvider => $custom);

    expect(manager()->provider('custom'))->toBe($custom);
});

it('resolves the filesystem driver', function (): void {
    expect(manager()->provider('filesystem'))->toBeInstanceOf(LocalFilesystemProvider::class);
});

it('resolves the acme driver', function (): void {
    expect(manager()->provider('acme'))->toBeInstanceOf(AcmeProvider::class);
});

it('honours a custom acme solver FQCN', function (): void {
    config()->set('certificates.drivers.acme.solver', RecordingDnsSolver::class);

    app()->forgetInstance(CertificateManager::class);

    // The provider builds without error and uses the configured solver.
    expect(manager()->provider('acme'))->toBeInstanceOf(AcmeProvider::class);
});

it('builds a CSR via the package Csr helper', function (): void {
    $csr = new Csr;
    $der = $csr->forDomains(['x.com'], $csr->newKey());

    expect($der)->not->toBeEmpty();
});

final class RecordingDnsSolver extends DnsChallengeSolver implements AcmeChallengeSolver
{
    protected function publishRecord(string $name, string $value): void {}

    protected function removeRecord(string $name, string $value): void {}
}
