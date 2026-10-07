<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Exceptions\ProvisioningInProgressException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Regression (chat review C-2): a process killed after the row was saved as Renewing (a queue
 * timeout during ACME polling, a deploy) ran no catch or finally. expiring() excludes
 * Renewing and Renewing → Renewing is illegal, so the certificate was never renewed again.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

/**
 * A row left in Renewing `$minutesAgo` minutes ago, expiring in five days.
 */
function renewingSince(int $minutesAgo, string $domain = 'stuck.example.com'): Certificate
{
    $certificate = Certificate::factory()->forDomain($domain)->expiring(5)->create([
        'driver' => 'array',
        'status' => CertificateStatus::Renewing,
    ]);

    $certificate->forceFill(['updated_at' => CarbonImmutable::now()->subMinutes($minutesAgo)])->saveQuietly();

    return $certificate->refresh();
}

it('renews a row an interrupted renewal left in Renewing', function (): void {
    $stuck = renewingSince(60 * 24 * 5);

    $report = Certificates::renewDue();

    expect($report->renewed)->toHaveCount(1)
        ->and($stuck->fresh()?->status)->toBe(CertificateStatus::Renewed);
});

it('lets renew() and renewLater() take over a stuck renewal', function (): void {
    expect(Certificates::renew(renewingSince(30, 'a.example.com'))->status)->toBe(CertificateStatus::Renewed);

    expect(Certificates::renewLater(renewingSince(30, 'b.example.com'))->domain)->toBe('b.example.com');
});

it('leaves a renewal that may still be running alone', function (): void {
    $running = renewingSince(1);

    expect(Certificates::expiring())->toHaveCount(0)
        ->and(Certificates::renewDue()->renewed)->toBe([])
        ->and(fn () => Certificates::renew($running))->toThrow(CertificateException::class, 'renewing');
});

it('times a queued renewal out no later than the provisioning lock', function (): void {
    expect((new RenewCertificateJob(1))->timeout)->toBe(600);

    config()->set('certificates.lock.locked_for_seconds', 900);

    expect((new RenewCertificateJob(1))->timeout)->toBe(900);
});

it('recovers a stuck renewal under the fake as well', function (): void {
    $fake = Certificates::fake();
    $stuck = Certificate::factory()->forDomain('stuck.example.com')->expiring(5)->make([
        'driver' => 'array',
        'status' => CertificateStatus::Renewing,
        'updated_at' => CarbonImmutable::now()->subDay(),
    ]);
    $fake->seed($stuck);

    expect(Certificates::renewDue()->renewed)->toHaveCount(1);

    Certificates::assertRenewed('stuck.example.com');
});

it('takes a stuck renewal over only once', function (): void {
    $stale = renewingSince(60);

    // Another process reclaimed it a moment ago and is renewing it now.
    Certificate::query()->whereKey($stale->id)->update(['updated_at' => CarbonImmutable::now()]);

    expect(fn () => Certificates::renew($stale))->toThrow(ProvisioningInProgressException::class);

    expect($stale->fresh()?->status)->toBe(CertificateStatus::Renewing);
});
