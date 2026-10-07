<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Facades\Certificates;

/**
 * Regression (batch 11 follow-up #80): a brand-new Ingress TLS entry appended a rule for
 * every host of the certificate, even for a host the Ingress already routed — leaving two
 * rules for the same host. C-7 only added missing rules when the entry already existed.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'kubernetes');

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/ingresses/app-ingress')) {
            return $request->method() === 'GET'
                ? Http::response(['metadata' => ['name' => 'app-ingress', 'resourceVersion' => '3'], 'spec' => [
                    'tls' => [['hosts' => ['other.tenant.com'], 'secretName' => 'generated-tls-other-tenant-com']],
                    'rules' => [['host' => 'other.tenant.com'], ['host' => 'shop.tenant.com']],
                ]])
                : Http::response(['ok' => true]);
        }

        if (str_contains($request->url(), '/certificates/')) {
            return Http::response(['status' => ['notAfter' => '2030-01-01T00:00:00Z', 'conditions' => [['type' => 'Ready', 'status' => 'True']]]]);
        }

        return Http::response('unexpected '.$request->url(), 500);
    });
});

/**
 * @return list<array<string, mixed>>
 */
function newEntryIngressPatches(): array
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() === 'PATCH')
        ->map(fn (array $pair): array => $pair[0]->data())
        ->values()
        ->all();
}

it('does not add a second rule for a host the Ingress already routes', function (): void {
    Certificates::for('shop.tenant.com')->issue();

    $patches = newEntryIngressPatches();

    expect($patches)->toHaveCount(1)
        ->and($patches[0]['spec']['tls'][1])->toBe(['hosts' => ['shop.tenant.com'], 'secretName' => 'generated-tls-shop-tenant-com'])
        ->and(array_column($patches[0]['spec']['rules'], 'host'))->toBe(['other.tenant.com', 'shop.tenant.com'])
        // the existing rule is kept as the cluster had it, not replaced by the package's backend
        ->and($patches[0]['spec']['rules'][1])->toBe(['host' => 'shop.tenant.com']);
});

it('adds rules only for the hosts of a new entry that are not routed yet', function (): void {
    Certificates::for('shop.tenant.com')->alsoFor('www.shop.tenant.com')->issue();

    $patches = newEntryIngressPatches();

    expect($patches)->toHaveCount(1)
        ->and(array_column($patches[0]['spec']['rules'], 'host'))->toBe(['other.tenant.com', 'shop.tenant.com', 'www.shop.tenant.com']);
});
