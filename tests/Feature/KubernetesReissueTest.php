<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;

/**
 * Regression (chat review C-7): the kubernetes driver returned early whenever the Ingress
 * already had a TLS entry for the certificate's secret, so re-issuing with a changed SAN set
 * wrote nothing to the cluster while the registry recorded the new domains as Issued.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'kubernetes');
});

/**
 * @param  array<string, mixed>  $spec  the Ingress spec the cluster starts with
 */
function clusterWithIngress(array $spec): void
{
    Http::fake(function (Request $request) use ($spec) {
        if (str_contains($request->url(), '/ingresses/app-ingress')) {
            return $request->method() === 'GET'
                ? Http::response(['metadata' => ['name' => 'app-ingress', 'resourceVersion' => '7'], 'spec' => $spec])
                : Http::response(['ok' => true]);
        }

        if (str_contains($request->url(), '/certificates/')) {
            return Http::response(['status' => ['notAfter' => '2030-01-01T00:00:00Z', 'conditions' => [['type' => 'Ready', 'status' => 'True']]]]);
        }

        return Http::response('unexpected '.$request->url(), 500);
    });
}

/**
 * @return list<array<string, mixed>>
 */
function ingressPatches(): array
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() === 'PATCH')
        ->map(fn (array $pair): array => $pair[0]->data())
        ->values()
        ->all();
}

it('adds a new SAN host to the existing TLS entry and routes it', function (): void {
    clusterWithIngress([
        'tls' => [['hosts' => ['shop.tenant.com'], 'secretName' => 'generated-tls-shop-tenant-com']],
        'rules' => [['host' => 'shop.tenant.com']],
    ]);

    $certificate = Certificates::for('shop.tenant.com')->alsoFor('www.shop.tenant.com')->issue();

    $patches = ingressPatches();

    expect($certificate->status)->toBe(CertificateStatus::Issued)
        ->and($patches)->toHaveCount(1)
        ->and($patches[0]['spec']['tls'])->toBe([
            ['hosts' => ['shop.tenant.com', 'www.shop.tenant.com'], 'secretName' => 'generated-tls-shop-tenant-com'],
        ])
        ->and(array_column($patches[0]['spec']['rules'], 'host'))->toBe(['shop.tenant.com', 'www.shop.tenant.com']);
});

it('rewrites the hosts of a TLS entry whose SAN set shrank, keeping every rule', function (): void {
    clusterWithIngress([
        'tls' => [['hosts' => ['shop.tenant.com', 'old.shop.tenant.com'], 'secretName' => 'generated-tls-shop-tenant-com']],
        'rules' => [['host' => 'shop.tenant.com'], ['host' => 'old.shop.tenant.com']],
    ]);

    Certificates::for('shop.tenant.com')->issue();

    $patches = ingressPatches();

    expect($patches)->toHaveCount(1)
        ->and($patches[0]['spec']['tls'][0]['hosts'])->toBe(['shop.tenant.com'])
        ->and(array_column($patches[0]['spec']['rules'], 'host'))->toBe(['shop.tenant.com', 'old.shop.tenant.com']);
});

it('writes nothing when the entry already covers the same hosts in another order', function (): void {
    clusterWithIngress([
        'tls' => [['hosts' => ['www.shop.tenant.com', 'shop.tenant.com'], 'secretName' => 'generated-tls-shop-tenant-com']],
        'rules' => [['host' => 'shop.tenant.com'], ['host' => 'www.shop.tenant.com']],
    ]);

    Certificates::for('shop.tenant.com')->alsoFor('www.shop.tenant.com')->issue();

    expect(ingressPatches())->toBe([]);
});
