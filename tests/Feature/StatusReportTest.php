<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Tests\Fixtures\CountingStatusProvider;
use RoundlyConsulting\Certificates\Tests\Fixtures\ScriptedStatusProvider;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('returns a cached status report via the service', function (): void {
    $provider = new CountingStatusProvider;
    app(CertificateProviderManager::class)->extend('array', fn (): CertificateProvider => $provider);

    $report = Certificates::statusReport('app.com');

    expect($report->status)->toBe(CertificateStatus::Issued);

    Certificates::statusReport('app.com');

    expect($provider->calls)->toBe(1);
});

it('bypasses the cache from the builder fresh() method', function (): void {
    $provider = new CountingStatusProvider;
    app(CertificateProviderManager::class)->extend('array', fn (): CertificateProvider => $provider);

    Certificates::for('app.com')->statusReport();
    Certificates::for('app.com')->fresh()->statusReport();

    expect($provider->calls)->toBe(2);
});

/**
 * Regression: CachedStatusResolver::forget() was never called, so for up to
 * `status_cache.ttl` after an issue or renewal statusReport() kept serving the report
 * from before the change — "pending" for a certificate that was just issued.
 */
it('drops the cached report when a certificate is issued', function (): void {
    config()->set('certificates.default', 'array');

    expect(Certificates::statusReport('fresh.example.com')?->status)->toBe(CertificateStatus::Pending);

    Certificates::issue(IssueCertificateData::make('fresh.example.com'));

    expect(Certificates::statusReport('fresh.example.com')?->status)->toBe(CertificateStatus::Issued);
});

it('drops the cached report when a certificate is renewed', function (): void {
    $provider = new ScriptedStatusProvider(new CertificateStatusReport(
        status: CertificateStatus::Issued,
        expiresAt: CarbonImmutable::parse('2030-01-01'),
        fingerprint: 'AA',
    ));
    app(CertificateProviderManager::class)->extend('scripted', static fn (): ScriptedStatusProvider => $provider);

    Certificates::for('renewed.example.com')->using('scripted')->issue();
    expect(Certificates::statusReport('renewed.example.com', 'scripted')?->fingerprint)->toBe('AA');

    $provider->report = new CertificateStatusReport(
        status: CertificateStatus::Issued,
        expiresAt: CarbonImmutable::parse('2030-04-01'),
        fingerprint: 'BB',
    );
    Certificates::renew('renewed.example.com');

    expect(Certificates::statusReport('renewed.example.com', 'scripted')?->fingerprint)->toBe('BB');
});
