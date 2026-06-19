<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateExpiring;
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

    $this->artisan('certificates:renew', ['--threshold' => '7'])
        ->assertExitCode(0);

    expect(Certificate::query()->first()->fresh()->status)->toBe(CertificateStatus::Renewed);
    Event::assertDispatched(CertificateExpiring::class);
});

it('renews a single domain', function (): void {
    Certificate::factory()->issued()->create(['domain' => 'one.example.com', 'driver' => 'array']);

    $this->artisan('certificates:renew', ['domain' => 'one.example.com'])
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
