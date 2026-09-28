<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\ScriptedStatusProvider;

/**
 * Regression: issue() read only `expiresAt` from the provider's report and marked the row
 * Issued whatever the report said — a null expiry became an invented now+90d. A backend
 * still issuing (cert-manager) or one that failed was recorded as a live certificate.
 */
beforeEach(function (): void {
    $provider = $this->provider = new ScriptedStatusProvider;
    app(CertificateProviderManager::class)->extend('scripted', static fn (): ScriptedStatusProvider => $provider);
});

it('keeps the row Requested while the provider reports issuance in progress', function (): void {
    Event::fake([CertificateIssued::class, CertificateFailed::class]);
    $this->provider->report = new CertificateStatusReport(status: CertificateStatus::Pending);

    $certificate = Certificates::for('pending.example.com')->using('scripted')->issue();

    expect($certificate->status)->toBe(CertificateStatus::Requested)
        ->and($certificate->expires_at)->toBeNull()
        ->and($certificate->issued_at)->toBeNull()
        ->and(Certificates::find('pending.example.com')?->status)->toBe(CertificateStatus::Requested);

    Event::assertNotDispatched(CertificateIssued::class);
    Event::assertNotDispatched(CertificateFailed::class);
});

it('fails loudly when the provider reports the certificate failed', function (CertificateStatus $reported): void {
    Event::fake([CertificateIssued::class, CertificateFailed::class]);
    $this->provider->report = new CertificateStatusReport(status: $reported);

    expect(fn () => Certificates::for('bad.example.com')->using('scripted')->issue())
        ->toThrow(CertificateException::class, 'reports the certificate for "bad.example.com" as '.$reported->value);

    expect(Certificates::find('bad.example.com'))
        ->status->toBe(CertificateStatus::Failed)
        ->expires_at->toBeNull();

    Event::assertDispatched(CertificateFailed::class);
    Event::assertNotDispatched(CertificateIssued::class);
})->with([
    'failed' => CertificateStatus::Failed,
    'expired' => CertificateStatus::Expired,
    'revoked' => CertificateStatus::Revoked,
]);

it('records the reported expiry, issuer, serial and fingerprint of an issued certificate', function (): void {
    $expiresAt = CarbonImmutable::now()->addDays(47)->startOfSecond();
    $this->provider->report = new CertificateStatusReport(
        status: CertificateStatus::Issued,
        expiresAt: $expiresAt,
        issuer: 'R11',
        serial: '0A1B',
        fingerprint: 'AB:CD',
    );

    $certificate = Certificates::for('live.example.com')->using('scripted')->issue();

    expect($certificate->fresh())
        ->status->toBe(CertificateStatus::Issued)
        ->expires_at->toDateTimeString()->toBe($expiresAt->toDateTimeString())
        ->issuer->toBe('R11')
        ->serial->toBe('0A1B')
        ->fingerprint->toBe('AB:CD');
});

it('falls back to validForDays only when an issued report carries no expiry', function (): void {
    $this->provider->report = new CertificateStatusReport(status: CertificateStatus::Issued);

    $certificate = Certificates::for('noexpiry.example.com')->using('scripted')->validForDays(30)->issue();

    expect($certificate->status)->toBe(CertificateStatus::Issued)
        ->and($certificate->daysUntilExpiry())->toBe(30);
});

it('marks the row failed when reading the status after provisioning throws', function (): void {
    Event::fake([CertificateFailed::class]);

    $this->provider->statusFailure = 'status endpoint down';

    expect(fn () => Certificates::for('status.example.com')->using('scripted')->issue())
        ->toThrow(RuntimeException::class, 'status endpoint down');

    expect(Certificate::query()->forDomain('status.example.com')->first()?->status)->toBe(CertificateStatus::Failed);
    Event::assertDispatched(CertificateFailed::class, fn (CertificateFailed $e): bool => $e->reason === 'status endpoint down');
});
