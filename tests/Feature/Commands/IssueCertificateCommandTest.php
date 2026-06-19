<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Enums\CertificateStatus;
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
