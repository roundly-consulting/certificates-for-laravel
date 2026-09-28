<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateRequested;
use RoundlyConsulting\Certificates\Exceptions\ProvisioningInProgressException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Regression: the provisioning lock was one global name (`certificates:generate`) with the
 * certificate name only as its owner, so while ANY issuance held it every other domain's
 * issue() returned early — no provisioning, no exception, no event, generate() still true —
 * and the row sat in Requested forever, invisible to active(), expiring() and renewDue().
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('provisions a domain while another domain holds its own provisioning lock', function (): void {
    // Another process mid-issuance of busy.example.com — under both the old global name
    // and the new per-certificate one.
    Cache::lock('certificates:generate', 60)->get();
    Cache::lock('certificates:generate:generated-tls-busy-example-com', 60)->get();

    $certificate = Certificates::issue(IssueCertificateData::make('other.example.com'));

    expect($certificate->status)->toBe(CertificateStatus::Issued)
        ->and($certificate->expires_at)->not->toBeNull();
});

it('refuses loudly, without touching the registry, while the same certificate is being provisioned', function (): void {
    Event::fake([CertificateRequested::class]);
    Cache::lock('certificates:generate:generated-tls-locked-example-com', 60)->get();

    expect(fn () => Certificates::issue(IssueCertificateData::make('locked.example.com')))
        ->toThrow(ProvisioningInProgressException::class, 'already being provisioned');

    expect(Certificate::query()->forDomain('locked.example.com')->exists())->toBeFalse();
    Event::assertNotDispatched(CertificateRequested::class);
});

it('reports a contended generate() as false instead of pretending it provisioned', function (): void {
    Cache::lock('certificates:generate:generated-tls-locked-example-com', 60)->get();

    expect(app(CertificatesManager::class)->generate('locked.example.com'))->toBeFalse()
        ->and(Certificate::query()->forDomain('locked.example.com')->exists())->toBeFalse();
});

it('releases the lock after provisioning so the next issuance goes through', function (): void {
    Certificates::issue(IssueCertificateData::make('again.example.com'));

    expect(Certificates::issue(IssueCertificateData::make('again.example.com'))->status)->toBe(CertificateStatus::Issued)
        ->and(Cache::lock('certificates:generate:generated-tls-again-example-com', 60)->get())->toBeTrue();
});
