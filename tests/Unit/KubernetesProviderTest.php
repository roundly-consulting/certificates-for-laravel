<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Exceptions\KubernetesApiException;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

function provider(): KubernetesProvider
{
    return new KubernetesProvider(
        baseUrl: 'https://k8s.test',
        token: 'test-token',
        namespace: 'apps',
        ingressName: 'app-ingress',
        serviceName: 'app-service',
        servicePort: 8080,
        issuer: 'letsencrypt',
        issuerKind: 'ClusterIssuer',
        ingressClass: 'nginx',
        verify: false,
    );
}

$certsUrl = 'https://k8s.test/apis/cert-manager.io/v1/namespaces/apps/certificates*';
$ingressUrl = 'https://k8s.test/apis/networking.k8s.io/v1/namespaces/apps/ingresses*';

it('lists certificates as RemoteCertificate objects', function () use ($certsUrl): void {
    Http::fake([
        $certsUrl => Http::response([
            'items' => [
                ['metadata' => ['name' => 'generated-tls-a-com'], 'spec' => ['dnsNames' => ['a.com']]],
                ['metadata' => ['name' => 'generated-tls-b-com'], 'spec' => ['commonName' => 'b.com']],
                ['metadata' => ['name' => 'broken']], // no domain -> filtered
            ],
        ]),
    ]);

    $certificates = provider()->get();

    expect($certificates)->toHaveCount(2)
        ->and($certificates->first())->toBeInstanceOf(RemoteCertificate::class)
        ->and($certificates->first()->domain)->toBe('a.com')
        ->and($certificates->last()->domain)->toBe('b.com');
});

it('throws when listing certificates fails', function () use ($certsUrl): void {
    Http::fake([$certsUrl => Http::response('boom', 500)]);

    provider()->get();
})->throws(KubernetesApiException::class);

it('reports a certificate exists', function () use ($certsUrl): void {
    Http::fake([$certsUrl => Http::response(['metadata' => ['name' => 'generated-tls-a-com']], 200)]);

    expect(provider()->exists('generated-tls-a-com', 'a.com'))->toBeTrue();
});

it('reports a missing certificate as not existing', function () use ($certsUrl): void {
    Http::fake([$certsUrl => Http::response('not found', 404)]);

    expect(provider()->exists('generated-tls-a-com', 'a.com'))->toBeFalse();
});

it('throws on an unexpected status while checking existence', function () use ($certsUrl): void {
    Http::fake([$certsUrl => Http::response('nope', 403)]);

    provider()->exists('generated-tls-a-com', 'a.com');
})->throws(KubernetesApiException::class);

it('creates a new ingress when none exists', function () use ($ingressUrl): void {
    Http::fakeSequence($ingressUrl)
        ->push('not found', 404)
        ->push(['ok' => true], 201);

    provider()->generate('generated-tls-new-com', 'new.com');

    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST') {
            return false;
        }

        $body = $request->data();

        return $body['spec']['tls'][0]['secretName'] === 'generated-tls-new-com'
            && $body['spec']['rules'][0]['host'] === 'new.com'
            && $body['spec']['rules'][0]['http']['paths'][0]['backend']['service']['name'] === 'app-service';
    });
});

it('patches an existing ingress to add a new host', function () use ($ingressUrl): void {
    Http::fakeSequence($ingressUrl)
        ->push([
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => ['name' => 'app-ingress', 'namespace' => 'apps'],
            'spec' => [
                'tls' => [['hosts' => ['old.com'], 'secretName' => 'generated-tls-old-com']],
                'rules' => [['host' => 'old.com']],
            ],
        ], 200)
        ->push(['ok' => true], 200);

    provider()->generate('generated-tls-new-com', 'new.com');

    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'PATCH') {
            return false;
        }

        $body = $request->data();

        return count($body['spec']['tls']) === 2
            && $body['spec']['tls'][1]['hosts'] === ['new.com'];
    });
});

it('is a no-op when the domain is already present', function () use ($ingressUrl): void {
    Http::fake([
        $ingressUrl => Http::response([
            'metadata' => ['name' => 'app-ingress'],
            'spec' => [
                'tls' => [['hosts' => ['new.com'], 'secretName' => 'generated-tls-new-com']],
                'rules' => [['host' => 'new.com']],
            ],
        ], 200),
    ]);

    provider()->generate('generated-tls-new-com', 'new.com');

    // Only the GET should have been sent — no apply/patch.
    Http::assertSentCount(1);
});

it('throws when fetching the ingress fails', function () use ($ingressUrl): void {
    Http::fake([$ingressUrl => Http::response('boom', 500)]);

    provider()->generate('generated-tls-new-com', 'new.com');
})->throws(KubernetesApiException::class);

it('throws when applying the ingress fails', function () use ($ingressUrl): void {
    Http::fakeSequence($ingressUrl)
        ->push('not found', 404)
        ->push('rejected', 422);

    provider()->generate('generated-tls-new-com', 'new.com');
})->throws(KubernetesApiException::class);
