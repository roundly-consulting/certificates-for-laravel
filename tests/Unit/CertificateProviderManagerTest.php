<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Acme\AcmeClient;
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

/**
 * Regression: the docs said a null `ca_path` disables TLS verification, while it verified
 * against the system bundle — and an empty CERTIFICATES_K8S_CA_PATH reached Guzzle as an
 * empty CA path. Only false disables verification; null or empty is the system bundle.
 */
it('maps the kubernetes ca_path onto TLS verification', function (mixed $caPath, string|bool $verify): void {
    config()->set('certificates.drivers.kubernetes.ca_path', $caPath);

    app()->forgetInstance(CertificateProviderManager::class);

    $provider = manager()->provider('kubernetes');

    expect((new ReflectionProperty(KubernetesProvider::class, 'verify'))->getValue($provider))->toBe($verify);
})->with([
    'a CA bundle path' => ['/etc/k8s/ca.crt', '/etc/k8s/ca.crt'],
    'null: the system bundle' => [null, true],
    'empty: the system bundle' => ['', true],
    'true: the system bundle' => [true, true],
    'false: verification off' => [false, false],
]);

it('maps the acme verify setting the same way', function (mixed $setting, string|bool $verify): void {
    config()->set('certificates.drivers.acme.verify', $setting);

    app()->forgetInstance(CertificateProviderManager::class);

    $client = (new ReflectionProperty(AcmeProvider::class, 'client'))->getValue(manager()->provider('acme'));

    expect((new ReflectionProperty(AcmeClient::class, 'verify'))->getValue($client))->toBe($verify);
})->with([
    'a CA bundle path' => ['/etc/ssl/ca.pem', '/etc/ssl/ca.pem'],
    'empty: the system bundle' => ['', true],
    'false: verification off' => [false, false],
]);
