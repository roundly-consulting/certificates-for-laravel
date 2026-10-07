<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Events\CertificateRenewed;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Regression (chat review C-21, owner decision A): on the kubernetes driver issue() leaves the
 * row Requested and certificates:sync records the outcome — but sync moved it to Issued or
 * Failed silently: no CertificateIssued / CertificateFailed (so no lifecycle alert) and no
 * issued_at. Sync now completes the lifecycle the way issue() and renew() would have.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'kubernetes');
    Event::fake([CertificateIssued::class, CertificateFailed::class, CertificateRenewed::class]);
});

/**
 * One cert-manager Certificate for shop.tenant.com, in the given state.
 *
 * @param  array<string, mixed>  $status
 */
function clusterCertificate(array $status): void
{
    $certificate = [
        'metadata' => ['name' => 'generated-tls-shop-tenant-com'],
        'spec' => ['dnsNames' => ['shop.tenant.com'], 'issuerRef' => ['name' => 'letsencrypt']],
        'status' => $status,
    ];

    Http::fake(function (Request $request) use ($certificate) {
        return str_ends_with($request->url(), '/certificates')
            ? Http::response(['items' => [$certificate]])
            : Http::response($certificate);
    });
}

function rowIn(CertificateStatus $status, ?string $expiresAt = null): Certificate
{
    return Certificate::factory()->forDomain('shop.tenant.com')->create([
        'driver' => 'kubernetes',
        'status' => $status,
        'expires_at' => $expiresAt === null ? null : CarbonImmutable::parse($expiresAt),
    ]);
}

$ready = ['notAfter' => '2030-03-01T00:00:00Z', 'conditions' => [['type' => 'Ready', 'status' => 'True']]];
$failed = ['conditions' => [
    ['type' => 'Ready', 'status' => 'False'],
    ['type' => 'Issuing', 'status' => 'False', 'reason' => 'Failed'],
]];

it('settles a requested certificate as issued, with issued_at and CertificateIssued', function () use ($ready): void {
    CarbonImmutable::setTestNow('2026-10-07 12:00:00');
    $row = rowIn(CertificateStatus::Requested);
    clusterCertificate($ready);

    Certificates::sync();

    expect($row->fresh())
        ->status->toBe(CertificateStatus::Issued)
        ->issued_at->toEqual(CarbonImmutable::parse('2026-10-07 12:00:00'));

    Event::assertDispatched(CertificateIssued::class, fn (CertificateIssued $event): bool => $event->certificate->is($row));
    Event::assertNotDispatched(CertificateFailed::class);
});

it('settles a requested certificate cert-manager failed as failed, with CertificateFailed', function () use ($failed): void {
    $row = rowIn(CertificateStatus::Requested);
    clusterCertificate($failed);

    Certificates::sync();

    expect($row->fresh())
        ->status->toBe(CertificateStatus::Failed)
        ->last_error->toContain('shop.tenant.com');

    Event::assertDispatched(CertificateFailed::class, fn (CertificateFailed $event): bool => $event->certificate->is($row) && $event->reason !== '');
    Event::assertNotDispatched(CertificateIssued::class);
});

it('records a renewing certificate cert-manager renewed as a renewal', function () use ($ready): void {
    $row = rowIn(CertificateStatus::Renewing, '2030-01-01T00:00:00Z');
    clusterCertificate($ready);

    Certificates::sync();

    expect($row->fresh())
        ->status->toBe(CertificateStatus::Renewed)
        ->last_renewed_at->not->toBeNull()
        ->expires_at->toEqual(CarbonImmutable::parse('2030-03-01T00:00:00Z'));

    Event::assertDispatched(CertificateRenewed::class);
    Event::assertNotDispatched(CertificateIssued::class);
});

it('fires nothing for a certificate sync discovers or that did not change', function () use ($ready): void {
    clusterCertificate($ready);

    Certificates::sync();
    Certificates::sync();

    expect(Certificates::find('shop.tenant.com')?->status)->toBe(CertificateStatus::Issued);

    Event::assertNotDispatched(CertificateIssued::class);
    Event::assertNotDispatched(CertificateRenewed::class);
    Event::assertNotDispatched(CertificateFailed::class);
});

it('does not record a renewal when the provider still holds the old certificate', function () use ($ready): void {
    $row = rowIn(CertificateStatus::Renewing, '2030-03-01T00:00:00Z');
    clusterCertificate($ready);

    Certificates::sync();

    expect($row->fresh())
        ->status->toBe(CertificateStatus::Issued)
        ->last_renewed_at->toBeNull();

    Event::assertNotDispatched(CertificateRenewed::class);
});
