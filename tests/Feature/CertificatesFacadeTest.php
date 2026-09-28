<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

it('resolves the service, manager, and a kubernetes provider from the container', function (): void {
    expect(app(CertificatesManager::class))->toBeInstanceOf(CertificatesManager::class);
    expect(app(CertificateProviderManager::class))->toBeInstanceOf(CertificateProviderManager::class);
    expect(app(CertificateProvider::class))->toBeInstanceOf(KubernetesProvider::class);
});

it('lists certificates through the facade', function (): void {
    Http::fake([
        'https://k8s.test/apis/cert-manager.io/v1/namespaces/apps/certificates*' => Http::response([
            'items' => [
                ['metadata' => ['name' => 'generated-tls-a-com'], 'spec' => ['dnsNames' => ['a.com']]],
            ],
        ]),
    ]);

    $certificates = Certificates::get();

    expect($certificates)->toHaveCount(1)
        ->and($certificates->first())->toBeInstanceOf(RemoteCertificate::class);
});

it('derives a certificate name through the facade', function (): void {
    expect(Certificates::certificateName('app.example.com'))->toBe('generated-tls-app-example-com');
});

it('merges the package config', function (): void {
    expect(config('certificates.name_prefix'))->toBe('generated-tls-');
});
