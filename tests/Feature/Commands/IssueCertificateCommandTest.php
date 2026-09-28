<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('issues a certificate from the command line', function (): void {
    $this->artisan('certificates:issue', ['domain' => 'app.example.com', '--driver' => 'array'])
        ->assertExitCode(0);

    expect(Certificate::query()->forDomain('app.example.com')->first()->status)
        ->toBe(CertificateStatus::Issued);
});

it('fails for an invalid domain', function (): void {
    $this->artisan('certificates:issue', ['domain' => 'not a domain'])
        ->assertExitCode(1);
});

/**
 * Regression: the README calls every command "a thin caller of the facade", but
 * certificates:issue resolved IssueCertificateAction directly, so Certificates::fake()
 * never saw a command-line issuance.
 */
it('issues through the facade, so the fake records it', function (): void {
    Certificates::fake();

    $this->artisan('certificates:issue', ['domain' => 'cli.example.com', '--driver' => 'array'])
        ->assertExitCode(0);

    Certificates::assertIssued('cli.example.com');
    expect(Certificate::query()->count())->toBe(0);
});

it('reports a contended issuance as a failure', function (): void {
    Cache::lock('certificates:generate:generated-tls-busy-example-com', 60)->get();

    $this->artisan('certificates:issue', ['domain' => 'busy.example.com'])
        ->expectsOutputToContain('already being provisioned')
        ->assertExitCode(1);
});
