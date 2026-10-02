<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Exceptions\UnknownProviderException;
use RoundlyConsulting\Certificates\Providers\AcmeProvider;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;
use RoundlyConsulting\Certificates\Providers\LocalFilesystemProvider;
use RoundlyConsulting\Certificates\Providers\NullProvider;
use RoundlyConsulting\Certificates\Tests\Fixtures\RecordingDnsSolver;

function manager(): CertificateProviderManager
{
    return app(CertificateProviderManager::class);
}

it('resolves the default kubernetes driver', function (): void {
    expect(manager()->provider())->toBeInstanceOf(KubernetesProvider::class);
});

it('resolves the null and array drivers', function (): void {
    expect(manager()->provider('null'))->toBeInstanceOf(NullProvider::class)
        ->and(manager()->provider('array'))->toBeInstanceOf(ArrayProvider::class);
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

    app()->forgetInstance(CertificateProviderManager::class);

    $provider = manager()->provider('acme');
    $solver = (new ReflectionProperty(AcmeProvider::class, 'solver'))->getValue($provider);

    // The configured solver alone decides the challenge: no separate challenge-type key.
    expect($provider)->toBeInstanceOf(AcmeProvider::class)
        ->and($solver)->toBeInstanceOf(RecordingDnsSolver::class)
        ->and($solver->type())->toBe('dns-01');
});

it('builds a CSR via the package Csr helper', function (): void {
    $csr = new Csr;
    $der = $csr->forDomains(['x.com'], $csr->newKey());

    expect($der)->not->toBeEmpty();
});
