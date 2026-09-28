<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Exceptions\KubernetesApiException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Issuance on the kubernetes driver is asynchronous: the Ingress patch only asks
 * cert-manager's ingress-shim to create a Certificate, which it does moments later.
 */
const K8S_CERT = 'https://k8s.test/apis/cert-manager.io/v1/namespaces/apps/certificates/generated-tls-shop-tenant-com';

/**
 * @param  array{0: array<mixed>|string, 1: int}  $certificate  the cert-manager Certificate GET response
 */
function fakeCluster(array $certificate): void
{
    Http::fake(function (Request $request) use ($certificate) {
        if (str_contains($request->url(), '/ingresses/app-ingress') && $request->method() === 'GET') {
            return Http::response('not found', 404);
        }

        if (str_contains($request->url(), '/ingresses') && $request->method() === 'POST') {
            return Http::response(['ok' => true], 201);
        }

        if ($request->url() === K8S_CERT) {
            return Http::response(...$certificate);
        }

        return Http::response('unexpected '.$request->url(), 500);
    });
}

beforeEach(function (): void {
    config()->set('certificates.default', 'kubernetes');
});

/**
 * Regression: the status read after the Ingress patch 404'd because cert-manager had not
 * created the Certificate yet, threw outside the try/catch, and stranded the row in
 * Requested with no CertificateFailed and no alert — on every normal first issue.
 */
it('leaves a first issuance Requested while cert-manager has not created the Certificate yet', function (): void {
    Event::fake([CertificateIssued::class, CertificateFailed::class]);
    fakeCluster([['kind' => 'Status', 'code' => 404], 404]);

    $certificate = Certificates::for('shop.tenant.com')->using('kubernetes')->issue();

    expect($certificate->status)->toBe(CertificateStatus::Requested)
        ->and($certificate->expires_at)->toBeNull()
        ->and(Certificate::query()->forDomain('shop.tenant.com')->first()?->status)->toBe(CertificateStatus::Requested);

    Event::assertNotDispatched(CertificateIssued::class);
    Event::assertNotDispatched(CertificateFailed::class);
});

it('records a real status failure as Failed with CertificateFailed', function (): void {
    Event::fake([CertificateFailed::class]);
    fakeCluster(['forbidden', 403]);

    expect(fn () => Certificates::for('shop.tenant.com')->using('kubernetes')->issue())
        ->toThrow(KubernetesApiException::class);

    expect(Certificate::query()->forDomain('shop.tenant.com')->first()?->status)->toBe(CertificateStatus::Failed);
    Event::assertDispatched(CertificateFailed::class);
});

it('marks the row Issued once cert-manager reports the Certificate Ready', function (): void {
    fakeCluster([[
        'spec' => ['issuerRef' => ['name' => 'letsencrypt']],
        'status' => [
            'notAfter' => '2030-01-01T00:00:00Z',
            'conditions' => [['type' => 'Ready', 'status' => 'True']],
        ],
    ], 200]);

    $certificate = Certificates::for('shop.tenant.com')->using('kubernetes')->issue();

    expect($certificate)
        ->status->toBe(CertificateStatus::Issued)
        ->issuer->toBe('letsencrypt')
        ->and($certificate->expires_at?->toIso8601String())->toBe('2030-01-01T00:00:00+00:00');
});

/**
 * Regression: sync mapped any non-True Ready condition to Failed — a terminal status the
 * row could never renew out of — while cert-manager was merely still issuing.
 */
it('syncs a certificate cert-manager is still issuing as pending, keeping a Requested row Requested', function (): void {
    $inProgress = [
        'metadata' => ['name' => 'generated-tls-new-example-com'],
        'spec' => ['dnsNames' => ['new.example.com'], 'issuerRef' => ['name' => 'letsencrypt']],
        'status' => ['conditions' => [
            ['type' => 'Ready', 'status' => 'False', 'reason' => 'DoesNotExist', 'message' => 'Issuing certificate as Secret does not exist'],
            ['type' => 'Issuing', 'status' => 'True', 'reason' => 'DoesNotExist'],
        ]],
    ];

    Http::fake([
        'https://k8s.test/apis/cert-manager.io/v1/namespaces/apps/certificates' => Http::response(['items' => [
            $inProgress,
            ['metadata' => ['name' => 'generated-tls-requested-example-com'], 'spec' => ['dnsNames' => ['requested.example.com']]] + ['status' => $inProgress['status']],
        ]]),
        'https://k8s.test/apis/cert-manager.io/v1/namespaces/apps/certificates/*' => Http::response($inProgress),
    ]);

    Certificate::factory()->forDomain('requested.example.com')->create(['driver' => 'kubernetes', 'status' => CertificateStatus::Requested]);

    expect(Certificates::sync('kubernetes'))->toBe(2)
        ->and(Certificates::find('new.example.com')?->status)->toBe(CertificateStatus::Pending)
        ->and(Certificates::find('requested.example.com')?->status)->toBe(CertificateStatus::Requested);
});
