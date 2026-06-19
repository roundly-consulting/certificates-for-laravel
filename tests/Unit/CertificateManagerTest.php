<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\CertificateManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Exceptions\UnknownProviderException;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;
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

    manager()->extend('acme', fn (): CertificateProvider => $custom);

    expect(manager()->provider('acme'))->toBe($custom);
});
