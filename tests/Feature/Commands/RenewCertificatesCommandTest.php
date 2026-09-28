<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateExpiring;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('reports when nothing is due for renewal', function (): void {
    $this->artisan('certificates:renew')
        ->expectsOutputToContain('No certificates are due for renewal.')
        ->assertExitCode(0);
});

it('renews expiring certificates inline and announces expiry', function (): void {
    Event::fake([CertificateExpiring::class]);
    Certificate::factory()->expiring(5)->create(['driver' => 'array']);

    $certificate = Certificate::query()->firstOrFail();

    $this->artisan('certificates:renew', ['--threshold' => '7'])
        ->expectsOutputToContain("Renewed certificate for {$certificate->domain}.")
        ->assertExitCode(0);

    expect($certificate->fresh()->status)->toBe(CertificateStatus::Renewed);
    Event::assertDispatched(CertificateExpiring::class);
});

it('renews a single domain', function (): void {
    Certificate::factory()->issued()->create(['domain' => 'one.example.com', 'driver' => 'array']);

    $this->artisan('certificates:renew', ['domain' => 'one.example.com'])
        ->expectsOutputToContain('Renewing certificate for one.example.com...')
        ->expectsOutputToContain('Renewed certificate for one.example.com.')
        ->assertExitCode(0);

    expect(Certificate::query()->forDomain('one.example.com')->first()->status)
        ->toBe(CertificateStatus::Renewed);
});

it('queues renewals when --queue is given', function (): void {
    Queue::fake();
    Certificate::factory()->expiring(5)->create(['driver' => 'array']);

    $this->artisan('certificates:renew', ['--threshold' => '7', '--queue' => true])
        ->assertExitCode(0);

    Queue::assertPushed(RenewCertificateJob::class);
});

it('reports an unknown domain as nothing to renew', function (): void {
    $this->artisan('certificates:renew', ['domain' => 'unknown.example.com'])
        ->expectsOutputToContain('No certificates are due for renewal.')
        ->assertExitCode(0);
});

it('queues a single domain with --queue', function (): void {
    Queue::fake();
    $certificate = Certificate::factory()->issued()->create(['domain' => 'queued.example.com', 'driver' => 'array']);

    $this->artisan('certificates:renew', ['domain' => 'queued.example.com', '--queue' => true])
        ->expectsOutputToContain('Queued renewal for queued.example.com.')
        ->assertExitCode(0);

    Queue::assertPushed(RenewCertificateJob::class, fn (RenewCertificateJob $job): bool => $job->certificateId === $certificate->id);
});

it('fails when a single domain cannot be renewed', function (): void {
    Certificate::factory()->failed()->create(['domain' => 'dead.example.com', 'driver' => 'array']);

    $this->artisan('certificates:renew', ['domain' => 'dead.example.com'])
        ->expectsOutputToContain('Cannot transition a certificate from "failed" to "renewing".')
        ->assertExitCode(1);
});

it('runs renewDue through the facade', function (): void {
    $fake = Certificates::fake();

    $this->artisan('certificates:renew', ['--threshold' => '10', '--queue' => true])->assertExitCode(0);

    $fake->assertRenewedDue(10);
});
