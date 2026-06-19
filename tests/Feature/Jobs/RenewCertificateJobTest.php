<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Actions\RenewCertificateAction;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('renews the certificate when handled', function (): void {
    $certificate = Certificate::factory()->issued()->create(['driver' => 'array']);

    (new RenewCertificateJob($certificate->id))->handle(
        app(RenewCertificateAction::class),
    );

    expect($certificate->fresh()->status)->toBe(CertificateStatus::Renewed);
});

it('does nothing when the certificate is gone', function (): void {
    (new RenewCertificateJob(999))->handle(
        app(RenewCertificateAction::class),
    );

    expect(Certificate::query()->count())->toBe(0);
});

it('honours the configured renewal queue', function (): void {
    config()->set('certificates.renewal.queue', 'certs');

    $job = new RenewCertificateJob(1);

    expect($job->queue)->toBe('certs');
});
