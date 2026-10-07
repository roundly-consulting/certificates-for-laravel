<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Regression (chat review C-5): expiring() selected `expires_at BETWEEN now AND now + days`,
 * so a certificate whose renewal kept failing dropped out of renewDue() exactly when it ran
 * out — the retries stopped at expiry and certificates:check said nothing was expiring.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

function lapsed(string $domain, CertificateStatus $status): Certificate
{
    return Certificate::factory()->forDomain($domain)->create([
        'driver' => 'array',
        'status' => $status,
        'expires_at' => CarbonImmutable::now()->subHour(),
    ]);
}

it('keeps retrying a renewal after the certificate has run out', function (CertificateStatus $status): void {
    $certificate = lapsed('lapsed.example.com', $status);

    expect(Certificates::expiring()->pluck('id')->all())->toBe([$certificate->id]);

    $report = Certificates::renewDue();

    expect($report->renewed)->toHaveCount(1)
        ->and($certificate->fresh()?->status)->toBe(CertificateStatus::Renewed);
})->with([
    'failed renewal' => [CertificateStatus::Failed],
    'never renewed' => [CertificateStatus::Issued],
]);

it('reports a lapsed certificate from certificates:check', function (): void {
    lapsed('lapsed.example.com', CertificateStatus::Failed);

    $this->artisan('certificates:check')
        ->expectsOutputToContain('lapsed.example.com')
        ->assertSuccessful();
});

it('still leaves revoked and expired rows out', function (): void {
    lapsed('revoked.example.com', CertificateStatus::Revoked);
    lapsed('expired.example.com', CertificateStatus::Expired);

    expect(Certificates::expiring())->toHaveCount(0);
});

it('renews a lapsed certificate under the fake as well', function (): void {
    $fake = Certificates::fake();
    $fake->seed(Certificate::factory()->forDomain('lapsed.example.com')->make([
        'driver' => 'array',
        'status' => CertificateStatus::Failed,
        'expires_at' => CarbonImmutable::now()->subHour(),
    ]));

    expect(Certificates::renewDue()->renewed)->toHaveCount(1);

    Certificates::assertRenewed('lapsed.example.com');
});
