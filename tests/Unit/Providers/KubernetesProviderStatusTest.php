<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\KubernetesApiException;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;

function statusProvider(): KubernetesProvider
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

$certUrl = 'https://k8s.test/apis/cert-manager.io/v1/namespaces/apps/certificates/generated-tls-a-com';

it('maps a Ready=True certificate to Issued with expiry', function () use ($certUrl): void {
    Http::fake([$certUrl => Http::response([
        'spec' => ['issuerRef' => ['name' => 'letsencrypt-prod']],
        'status' => [
            'notAfter' => '2030-01-01T00:00:00Z',
            'conditions' => [['type' => 'Ready', 'status' => 'True']],
        ],
    ], 200)]);

    $report = statusProvider()->status('generated-tls-a-com', 'a.com');

    expect($report->status)->toBe(CertificateStatus::Issued)
        ->and($report->issuer)->toBe('letsencrypt-prod')
        ->and($report->expiresAt)->toEqual(CarbonImmutable::parse('2030-01-01T00:00:00Z'));
});

it('maps a failed issuance to Failed', function () use ($certUrl): void {
    Http::fake([$certUrl => Http::response([
        'status' => [
            'lastFailureTime' => '2026-09-28T10:00:00Z',
            'conditions' => [
                ['type' => 'Ready', 'status' => 'False', 'reason' => 'DoesNotExist'],
                ['type' => 'Issuing', 'status' => 'False', 'reason' => 'Failed', 'message' => 'The certificate request has failed to complete'],
            ],
        ],
    ], 200)]);

    expect(statusProvider()->status('generated-tls-a-com', 'a.com')->status)
        ->toBe(CertificateStatus::Failed);
});

it('maps a certificate with no conditions to Pending and null expiry', function () use ($certUrl): void {
    Http::fake([$certUrl => Http::response(['status' => []], 200)]);

    $report = statusProvider()->status('generated-tls-a-com', 'a.com');

    expect($report->status)->toBe(CertificateStatus::Pending)
        ->and($report->expiresAt)->toBeNull();
});

it('throws when reading status fails', function () use ($certUrl): void {
    Http::fake([$certUrl => Http::response('boom', 500)]);

    statusProvider()->status('generated-tls-a-com', 'a.com');
})->throws(KubernetesApiException::class);

it('maps a Certificate cert-manager has not created yet to Pending', function () use ($certUrl): void {
    Http::fake([$certUrl => Http::response(['kind' => 'Status', 'code' => 404], 404)]);

    $report = statusProvider()->status('generated-tls-a-com', 'a.com');

    expect($report->status)->toBe(CertificateStatus::Pending)
        ->and($report->expiresAt)->toBeNull();
});

it('maps Ready=False while cert-manager is issuing to Pending', function () use ($certUrl): void {
    Http::fake([$certUrl => Http::response([
        'status' => ['conditions' => [
            ['type' => 'Ready', 'status' => 'False', 'reason' => 'DoesNotExist'],
            ['type' => 'Issuing', 'status' => 'True', 'reason' => 'DoesNotExist'],
        ]],
    ], 200)]);

    expect(statusProvider()->status('generated-tls-a-com', 'a.com')->status)
        ->toBe(CertificateStatus::Pending);
});

it('maps a Ready=True certificate past its notAfter to Expired', function () use ($certUrl): void {
    Http::fake([$certUrl => Http::response([
        'status' => [
            'notAfter' => '2001-01-01T00:00:00Z',
            'conditions' => [['type' => 'Ready', 'status' => 'True']],
        ],
    ], 200)]);

    expect(statusProvider()->status('generated-tls-a-com', 'a.com')->status)
        ->toBe(CertificateStatus::Expired);
});
