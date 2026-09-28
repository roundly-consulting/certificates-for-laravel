<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('renews, revokes and expires through the domain handle', function (): void {
    Certificate::factory()->issued()->forDomain('a.com')->create(['driver' => 'array']);
    Certificate::factory()->issued()->forDomain('b.com')->create(['driver' => 'array']);

    expect(Certificates::for('a.com')->renew()->status)->toBe(CertificateStatus::Renewed)
        ->and(Certificates::for('a.com')->revoke('rotated')->status)->toBe(CertificateStatus::Revoked)
        ->and(Certificates::find('a.com')?->last_error)->toBe('rotated')
        ->and(Certificates::for('b.com')->expire()->status)->toBe(CertificateStatus::Expired);
});

it('queues a renewal through the domain handle', function (): void {
    Queue::fake();

    $certificate = Certificate::factory()->issued()->forDomain('later.com')->create(['driver' => 'array']);

    expect(Certificates::for('later.com')->renewLater()->is($certificate))->toBeTrue();

    Queue::assertPushed(RenewCertificateJob::class, fn (RenewCertificateJob $job): bool => $job->certificateId === $certificate->id);
});

it('never acts on another domain\'s row', function (): void {
    $other = Certificate::factory()->issued()->forDomain('other.com')->create(['driver' => 'array']);

    expect(fn () => Certificates::for('mine.com')->renew())
        ->toThrow(CertificateException::class, 'No certificate is registered for "mine.com".')
        ->and(fn () => Certificates::for('mine.com')->revoke())->toThrow(CertificateException::class)
        ->and(fn () => Certificates::for('mine.com')->expire())->toThrow(CertificateException::class)
        ->and(fn () => Certificates::for('mine.com')->renewLater())->toThrow(CertificateException::class)
        ->and($other->fresh()?->status)->toBe(CertificateStatus::Issued);
});

it('never acts on the same domain\'s row under another driver', function (): void {
    $kubernetes = Certificate::factory()->issued()->forDomain('shared.com')->create(['driver' => 'kubernetes']);

    expect(fn () => Certificates::for('shared.com')->using('array')->revoke())
        ->toThrow(CertificateException::class, 'No certificate is registered for "shared.com".')
        ->and($kubernetes->fresh()?->status)->toBe(CertificateStatus::Issued);
});
