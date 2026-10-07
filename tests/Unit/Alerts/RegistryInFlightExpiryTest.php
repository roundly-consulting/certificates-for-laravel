<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Regression (chat review V-1): the registry-wide check selected only Failed / Expired rows
 * and Issued / Renewed ones near expiry, so a row stuck in an in-flight status past its
 * expiry — a renewal that never finished, an issuance that never settled — read "ok" while
 * the per-certificate check for the same row failed.
 */
it('fails registry-wide for an in-flight certificate that is past its expiry', function (CertificateStatus $status): void {
    Certificate::factory()->issued()->create(['driver' => 'array']);
    $stuck = Certificate::factory()->create([
        'driver' => 'array',
        'status' => $status,
        'expires_at' => CarbonImmutable::now()->subDays(2),
    ]);

    $result = (new CertificateExpiryCheck)->check();

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta['affected'])->toHaveCount(1)
        ->and($result->meta['affected'][0]['certificate_id'])->toBe($stuck->id);
})->with([
    'renewing' => [CertificateStatus::Renewing],
    'requested' => [CertificateStatus::Requested],
    'pending' => [CertificateStatus::Pending],
]);

it('leaves an in-flight certificate with time left out of the registry signal', function (): void {
    Certificate::factory()->create([
        'driver' => 'array',
        'status' => CertificateStatus::Renewing,
        'expires_at' => CarbonImmutable::now()->addDays(60),
    ]);

    expect((new CertificateExpiryCheck)->check()->status)->toBe(Status::Ok);
});

it('still ignores a revoked certificate past its expiry', function (): void {
    Certificate::factory()->create([
        'driver' => 'array',
        'status' => CertificateStatus::Revoked,
        'expires_at' => CarbonImmutable::now()->subDays(2),
    ]);

    expect((new CertificateExpiryCheck)->check()->status)->toBe(Status::Ok);
});
