<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateExpiring;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateRenewed;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\FailingDomainsProvider;
use RoundlyConsulting\Certificates\Tests\Fixtures\RefusingDispatcher;

/**
 * Regression: renewDue() stopped at the first certificate that failed to renew, so every
 * later due certificate was silently skipped in that run — a scheduled renewal could let
 * them expire. Each due certificate is now attempted on its own and the outcome reported.
 */
beforeEach(function (): void {
    app(CertificateProviderManager::class)->extend('flaky', fn () => new FailingDomainsProvider(['b.com']));

    $this->a = Certificate::factory()->expiring(1)->forDomain('a.com')->create(['driver' => 'flaky']);
    $this->b = Certificate::factory()->expiring(2)->forDomain('b.com')->create(['driver' => 'flaky']);
    $this->c = Certificate::factory()->expiring(3)->forDomain('c.com')->create(['driver' => 'flaky']);
});

it('renews the rest of the due set when the middle certificate fails', function (): void {
    Event::fake([CertificateExpiring::class, CertificateFailed::class, CertificateRenewed::class]);
    Exceptions::fake();

    $report = Certificates::renewDue(7);

    expect(Certificates::status('a.com'))->toBe(CertificateStatus::Renewed)
        ->and(Certificates::status('b.com'))->toBe(CertificateStatus::Failed)
        ->and(Certificates::status('c.com'))->toBe(CertificateStatus::Renewed)
        ->and(Certificates::find('b.com')?->last_error)->toBe('CA rejected the order for b.com.')
        ->and($report->renewedDomains())->toBe(['a.com', 'c.com'])
        ->and($report->queued)->toBe([])
        ->and($report->failedDomains())->toBe(['b.com'])
        ->and($report->failed[0]->certificate->is($this->b))->toBeTrue()
        ->and($report->failed[0]->reason())->toBe('CA rejected the order for b.com.')
        ->and($report->hasFailures())->toBeTrue()
        ->and($report->count())->toBe(3);

    Event::assertDispatchedTimes(CertificateExpiring::class, 3);
    Event::assertDispatchedTimes(CertificateRenewed::class, 2);
    Event::assertDispatched(CertificateFailed::class, fn (CertificateFailed $e): bool => $e->certificate->domain === 'b.com');
    Event::assertDispatchedTimes(CertificateFailed::class, 1);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'CA rejected the order for b.com.');
});

it('queues the rest of the due set when one dispatch fails', function (): void {
    Queue::fake();
    Exceptions::fake();
    app()->instance(Dispatcher::class, new RefusingDispatcher([$this->b->id]));

    $report = Certificates::renewDue(7, queue: true);

    expect($report->queuedDomains())->toBe(['a.com', 'c.com'])
        ->and($report->renewed)->toBe([])
        ->and($report->failedDomains())->toBe(['b.com'])
        ->and($report->failed[0]->reason())->toBe('Queue connection refused.')
        // nothing was attempted, so the row stays renewable for the next run
        ->and(Certificates::status('b.com'))->toBe(CertificateStatus::Issued);

    Queue::assertPushed(RenewCertificateJob::class, 2);
    Queue::assertPushed(RenewCertificateJob::class, fn (RenewCertificateJob $job): bool => $job->certificateId === $this->a->id);
    Queue::assertPushed(RenewCertificateJob::class, fn (RenewCertificateJob $job): bool => $job->certificateId === $this->c->id);
    Exceptions::assertReported(RuntimeException::class);
});

it('keeps dispatching when a job fails on the sync queue', function (): void {
    config()->set('queue.default', 'sync');
    Exceptions::fake();

    $report = Certificates::renewDue(7, queue: true);

    expect($report->queuedDomains())->toBe(['a.com', 'c.com'])
        ->and($report->failedDomains())->toBe(['b.com'])
        ->and(Certificates::status('a.com'))->toBe(CertificateStatus::Renewed)
        ->and(Certificates::status('b.com'))->toBe(CertificateStatus::Failed)
        ->and(Certificates::status('c.com'))->toBe(CertificateStatus::Renewed);
});

it('prints every outcome and exits non-zero when a renewal failed', function (): void {
    Exceptions::fake();

    $this->artisan('certificates:renew', ['--threshold' => '7'])
        ->expectsOutputToContain('Renewed certificate for a.com.')
        ->expectsOutputToContain('Renewed certificate for c.com.')
        ->expectsOutputToContain('Failed to renew certificate for b.com: CA rejected the order for b.com.')
        ->assertExitCode(1);

    expect(Certificates::status('c.com'))->toBe(CertificateStatus::Renewed);
});

it('exits non-zero when a queued dispatch failed', function (): void {
    Queue::fake();
    Exceptions::fake();
    app()->instance(Dispatcher::class, new RefusingDispatcher([$this->b->id]));

    $this->artisan('certificates:renew', ['--threshold' => '7', '--queue' => true])
        ->expectsOutputToContain('Queued renewal for a.com.')
        ->expectsOutputToContain('Queued renewal for c.com.')
        ->expectsOutputToContain('Failed to renew certificate for b.com: Queue connection refused.')
        ->assertExitCode(1);
});

it('exits zero when every due certificate renews', function (): void {
    app(CertificateProviderManager::class)->extend('flaky', fn () => new FailingDomainsProvider([]));

    $this->artisan('certificates:renew', ['--threshold' => '7'])
        ->expectsOutputToContain('Renewed certificate for b.com.')
        ->assertExitCode(0);
});
