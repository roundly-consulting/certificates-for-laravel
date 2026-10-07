<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Certificates\Actions\RenewCertificateAction;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\ProvisioningInProgressException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;
use RoundlyConsulting\Certificates\Support\ProvisioningLock;

/**
 * Regression (chat review C-3): renew() took no provisioning lock and moved the row to
 * Renewing on an in-memory check, so concurrent renewals (and a renewal racing issue()) both
 * ran, and a duplicate queued job re-renewed a certificate that had just been renewed.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

function arrayDriver(): ArrayProvider
{
    $driver = Certificates::driver('array');

    expect($driver)->toBeInstanceOf(ArrayProvider::class);

    /** @var ArrayProvider $driver */
    return $driver;
}

function dueCertificate(string $domain = 'due.example.com'): Certificate
{
    return Certificate::factory()->forDomain($domain)->expiring(5)->create(['driver' => 'array']);
}

it('refuses to renew while the certificate is being provisioned elsewhere', function (): void {
    $certificate = dueCertificate();
    $expiresAt = $certificate->expires_at;

    $lock = ProvisioningLock::for($certificate->name);
    expect($lock->get())->toBeTrue();

    expect(fn () => Certificates::renew($certificate))->toThrow(ProvisioningInProgressException::class);

    $row = $certificate->fresh();

    expect($row?->status)->toBe(CertificateStatus::Issued)
        ->and($row?->expires_at?->equalTo($expiresAt))->toBeTrue()
        ->and(arrayDriver()->generatedCalls())->toBe([]);

    $lock->release();
});

it('refuses a renewal whose row another process moved on in the meantime', function (): void {
    $certificate = dueCertificate();
    $stale = Certificate::query()->findOrFail($certificate->id);

    // Another process renews it first…
    Certificates::renew($certificate);
    expect(arrayDriver()->generatedCalls())->toHaveCount(1);

    // …and this one still holds the Issued model it loaded before that.
    expect(fn () => Certificates::renew($stale))->toThrow(ProvisioningInProgressException::class);

    expect(arrayDriver()->generatedCalls())->toHaveCount(1)
        ->and($certificate->fresh()?->status)->toBe(CertificateStatus::Renewed);
});

it('releases the renewal lock afterwards', function (): void {
    $certificate = dueCertificate();

    Certificates::renew($certificate);

    $lock = ProvisioningLock::for($certificate->name);

    expect($lock->get())->toBeTrue();

    $lock->release();
});

it('skips a queued renewal of a certificate that is no longer due', function (): void {
    Queue::fake();
    $certificate = dueCertificate();

    // Two sweeps queue the same renewal before a worker gets to either.
    Certificates::renewDue(queue: true);
    Certificates::renewDue(queue: true);

    $jobs = Queue::pushed(RenewCertificateJob::class);
    expect($jobs)->toHaveCount(2);

    $jobs->each(fn (RenewCertificateJob $job) => $job->handle(app(RenewCertificateAction::class)));

    expect(arrayDriver()->generatedCalls())->toHaveCount(1)
        ->and($certificate->fresh()?->status)->toBe(CertificateStatus::Renewed);
});

it('still renews a renewLater() job whatever the expiry', function (): void {
    Queue::fake();
    $certificate = Certificate::factory()->issued()->create(['driver' => 'array']);

    Certificates::renewLater($certificate);

    Queue::pushed(RenewCertificateJob::class)->first()?->handle(app(RenewCertificateAction::class));

    expect($certificate->fresh()?->status)->toBe(CertificateStatus::Renewed);
});
