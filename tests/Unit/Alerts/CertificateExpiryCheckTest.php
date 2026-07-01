<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\AlertTeam;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function certExpiringInDays(int $days, CertificateStatus $status = CertificateStatus::Issued): Certificate
{
    return Certificate::factory()->create([
        'domain' => "d{$days}.com",
        'driver' => 'array',
        'status' => $status,
        'issued_at' => CarbonImmutable::now()->subDays(80),
        'expires_at' => CarbonImmutable::now()->addDays($days),
    ]);
}

it('returns ok well outside the warning window', function (): void {
    $cert = certExpiringInDays(40);

    $result = (new CertificateExpiryCheck(certificateId: $cert->id))->check();

    expect($result->status)->toBe(Status::Ok)
        ->and($result->meta['band'])->toBe('ok')
        ->and($result->meta['domain'])->toBe('d40.com');
});

it('warns at the warning boundary', function (): void {
    $cert = certExpiringInDays(30);

    expect((new CertificateExpiryCheck(certificateId: $cert->id))->check()->status)->toBe(Status::Warning);
});

it('warns inside the warning window', function (): void {
    $cert = certExpiringInDays(20);

    $result = (new CertificateExpiryCheck(certificateId: $cert->id))->check();

    expect($result->status)->toBe(Status::Warning)
        ->and($result->meta['band'])->toBe('warning');
});

it('fails at the critical boundary', function (): void {
    $cert = certExpiringInDays(7);

    expect((new CertificateExpiryCheck(certificateId: $cert->id))->check()->status)->toBe(Status::Failed);
});

it('fails inside the critical window', function (): void {
    $cert = certExpiringInDays(3);

    $result = (new CertificateExpiryCheck(certificateId: $cert->id))->check();

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta['band'])->toBe('critical');
});

it('skips when the certificate has no expiry', function (): void {
    $cert = Certificate::factory()->create(['status' => CertificateStatus::Pending, 'expires_at' => null]);

    expect((new CertificateExpiryCheck(certificateId: $cert->id))->check()->status)->toBe(Status::Skipped);
});

it('skips when the certificate cannot be resolved', function (): void {
    expect((new CertificateExpiryCheck(certificateId: 999))->check()->status)->toBe(Status::Skipped);
});

it('fails for an expired certificate', function (): void {
    $cert = Certificate::factory()->expired()->create(['domain' => 'gone.com', 'driver' => 'array']);

    expect((new CertificateExpiryCheck(certificateId: $cert->id))->check()->status)->toBe(Status::Failed);
});

it('fails for a terminal revoked or failed certificate', function (CertificateStatus $status): void {
    $cert = certExpiringInDays(40, $status);

    $result = (new CertificateExpiryCheck(certificateId: $cert->id))->check();

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta['band'])->toBe('critical');
})->with([
    [CertificateStatus::Revoked],
    [CertificateStatus::Failed],
]);

it('honours custom thresholds passed to the constructor', function (): void {
    $cert = certExpiringInDays(10);

    // Default (30/7) => warning; critical_days=14 => failed.
    $result = (new CertificateExpiryCheck(certificateId: $cert->id, warningDays: 60, criticalDays: 14))->check();

    expect($result->status)->toBe(Status::Failed);
});

it('reads certificate id and thresholds from the bound health check meta', function (): void {
    $cert = certExpiringInDays(10);
    $team = AlertTeam::query()->create(['name' => 'ops']);

    $row = HealthCheck::query()->create([
        'notifiable_type' => $team->getMorphClass(),
        'notifiable_id' => $team->getKey(),
        'health_check' => 'certificate_expiry',
        'frequency' => '@daily',
        'max_attempts' => 1,
        'decay_minutes' => 1,
        'meta' => ['certificate_id' => $cert->id, 'critical_days' => 14],
    ]);

    $result = (new CertificateExpiryCheck(healthCheck: $row))->check();

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta['certificate_id'])->toBe($cert->id);
});

it('runs registry-wide and reports ok with no critical certificates', function (): void {
    certExpiringInDays(40);

    $result = (new CertificateExpiryCheck)->check();

    expect($result->status)->toBe(Status::Ok);
});

it('runs registry-wide and fails when a certificate is critical', function (): void {
    certExpiringInDays(40);
    certExpiringInDays(3);

    $result = (new CertificateExpiryCheck)->check();

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta['affected'])->toHaveCount(1);
});

it('runs registry-wide and warns when the closest cert is only in the warning window', function (): void {
    certExpiringInDays(20);

    $result = (new CertificateExpiryCheck)->check();

    expect($result->status)->toBe(Status::Warning);
});
