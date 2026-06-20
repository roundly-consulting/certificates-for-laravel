<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\CertificateManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('returns a cached status report via the service', function (): void {
    $provider = new CountingStatusProvider;
    app(CertificateManager::class)->extend('array', fn (): CertificateProvider => $provider);

    $report = Certificates::statusReport('app.com');

    expect($report->status)->toBe(CertificateStatus::Issued);

    Certificates::statusReport('app.com');

    expect($provider->calls)->toBe(1);
});

it('bypasses the cache from the builder fresh() method', function (): void {
    $provider = new CountingStatusProvider;
    app(CertificateManager::class)->extend('array', fn (): CertificateProvider => $provider);

    Certificates::for('app.com')->statusReport();
    Certificates::for('app.com')->fresh()->statusReport();

    expect($provider->calls)->toBe(2);
});
