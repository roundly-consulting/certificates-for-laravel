<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateRenewed;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Regression (chat review C-6): on the kubernetes driver a renewal re-applies an Ingress TLS
 * entry that already exists (no API write), cert-manager reports no fingerprint, and Ready=True
 * wins over a failed Issuing condition — so renew() marked the row Renewed with the very same
 * notAfter and fired CertificateRenewed, every day, while cert-manager's renewal was failing.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'kubernetes');
});

/**
 * A cluster whose Ingress already routes the certificate, and whose cert-manager Certificate
 * reports `$notAfter`.
 *
 * @param  list<array<string, mixed>>  $conditions
 */
function clusterReporting(string $notAfter, array $conditions, array $extraStatus = []): void
{
    Http::fake(function (Request $request) use ($notAfter, $conditions, $extraStatus) {
        if (str_contains($request->url(), '/ingresses/app-ingress') && $request->method() === 'GET') {
            return Http::response([
                'metadata' => ['name' => 'app-ingress', 'resourceVersion' => '7'],
                'spec' => [
                    'tls' => [['hosts' => ['shop.tenant.com'], 'secretName' => 'generated-tls-shop-tenant-com']],
                    'rules' => [['host' => 'shop.tenant.com']],
                ],
            ]);
        }

        if (str_ends_with($request->url(), '/certificates/generated-tls-shop-tenant-com')) {
            return Http::response([
                'spec' => ['issuerRef' => ['name' => 'letsencrypt']],
                'status' => ['notAfter' => $notAfter, 'conditions' => $conditions] + $extraStatus,
            ]);
        }

        return Http::response('unexpected '.$request->method().' '.$request->url(), 500);
    });
}

function issuedOnKubernetes(string $expiresAt): Certificate
{
    return Certificate::factory()->forDomain('shop.tenant.com')->create([
        'driver' => 'kubernetes',
        'status' => CertificateStatus::Issued,
        'expires_at' => CarbonImmutable::parse($expiresAt),
    ]);
}

it('fails a renewal cert-manager did not carry out', function (): void {
    Event::fake([CertificateRenewed::class, CertificateFailed::class]);
    $certificate = issuedOnKubernetes('2030-01-01T00:00:00Z');

    clusterReporting('2030-01-01T00:00:00Z', [
        ['type' => 'Ready', 'status' => 'True'],
        ['type' => 'Issuing', 'status' => 'False', 'reason' => 'Failed'],
    ], ['lastFailureTime' => '2029-12-01T00:00:00Z']);

    expect(fn () => Certificates::renew($certificate))
        ->toThrow(CertificateException::class, 'did not produce a new certificate');

    $row = $certificate->fresh();

    expect($row?->status)->toBe(CertificateStatus::Failed)
        ->and($row?->expires_at?->toIso8601String())->toBe('2030-01-01T00:00:00+00:00');

    Event::assertDispatched(CertificateFailed::class);
    Event::assertNotDispatched(CertificateRenewed::class);
});

it('records a renewal once cert-manager moved notAfter later', function (): void {
    Event::fake([CertificateRenewed::class, CertificateFailed::class]);
    $certificate = issuedOnKubernetes('2030-01-01T00:00:00Z');

    clusterReporting('2030-03-01T00:00:00Z', [['type' => 'Ready', 'status' => 'True']]);

    $renewed = Certificates::renew($certificate);

    expect($renewed->status)->toBe(CertificateStatus::Renewed)
        ->and($renewed->expires_at?->toIso8601String())->toBe('2030-03-01T00:00:00+00:00');

    Event::assertDispatched(CertificateRenewed::class);
});
