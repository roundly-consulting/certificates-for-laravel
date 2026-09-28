<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\ScriptedStatusProvider;

/**
 * Regression: one transient CA error moved the row to Failed — a status with no
 * transitions — and expiring() only selected Issued/Renewed, so every later renewDue()
 * and certificates:renew skipped it while the live certificate ran out.
 */
beforeEach(function (): void {
    Exceptions::fake();

    $provider = $this->provider = new ScriptedStatusProvider;
    $provider->failOnCalls = [1];
    app(CertificateProviderManager::class)->extend('flaky', static fn (): ScriptedStatusProvider => $provider);

    $this->certificate = Certificate::factory()->expiring(3)->forDomain('flaky.example.com')->create(['driver' => 'flaky']);
});

it('retries a failed renewal on the next renewDue run', function (): void {
    $first = Certificates::renewDue();

    expect($first->failedDomains())->toBe(['flaky.example.com'])
        ->and($this->certificate->fresh()?->status)->toBe(CertificateStatus::Failed);

    $second = Certificates::renewDue();

    expect($second->renewedDomains())->toBe(['flaky.example.com'])
        ->and($second->failed)->toBe([])
        ->and($this->certificate->fresh()?->status)->toBe(CertificateStatus::Renewed)
        ->and($this->provider->provisioned)->toHaveCount(2);
});

it('lets a failed certificate be renewed directly', function (): void {
    $this->certificate->markFailed('transient CA error');
    $this->provider->failOnCalls = [];

    expect(CertificateStatus::Failed->canTransitionTo(CertificateStatus::Renewing))->toBeTrue()
        ->and(Certificates::renew('flaky.example.com')->status)->toBe(CertificateStatus::Renewed);
});

it('lists a failed certificate whose live certificate still runs out as expiring', function (): void {
    $this->certificate->markFailed('transient CA error');
    Certificate::factory()->failed()->forDomain('never-issued.example.com')->create(['driver' => 'flaky']);

    expect(Certificates::expiring(7)->pluck('domain')->all())->toBe(['flaky.example.com'])
        ->and(Certificate::query()->expiring(7)->count())->toBe(1);
});

it('retries a failed renewal under the fake too', function (): void {
    $fake = Certificates::fake();
    $fake->seed(Certificate::factory()->expiring(3)->forDomain('retry.example.com')->make([
        'driver' => 'array',
        'status' => CertificateStatus::Failed,
    ]));

    expect(Certificates::renewDue()->renewedDomains())->toBe(['retry.example.com']);
    Certificates::assertRenewed('retry.example.com');
});
