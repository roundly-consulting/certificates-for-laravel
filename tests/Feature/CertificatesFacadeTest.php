<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Certificate;
use RoundlyConsulting\Certificates\CertificateService;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;

it('resolves the service and a kubernetes provider from the container', function (): void {
    expect(app(CertificateService::class))->toBeInstanceOf(CertificateService::class);
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
        ->and($certificates->first())->toBeInstanceOf(Certificate::class);
});

it('generates a certificate through the facade', function (): void {
    Http::fakeSequence('https://k8s.test/apis/networking.k8s.io/v1/namespaces/apps/ingresses*')
        ->push('not found', 404)
        ->push(['ok' => true], 201);

    expect(Certificates::generate('new.com'))->toBeTrue();
});

it('merges the package config', function (): void {
    expect(config('certificates.name_prefix'))->toBe('generated-tls-');
});
