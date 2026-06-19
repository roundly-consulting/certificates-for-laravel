<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;

it('records generate calls and reflects them', function (): void {
    $provider = new ArrayProvider;

    expect($provider->exists('generated-tls-a-com', 'a.com'))->toBeFalse()
        ->and($provider->status('generated-tls-a-com', 'a.com')->status)->toBe(CertificateStatus::Pending);

    $provider->generate('generated-tls-a-com', 'a.com');

    expect($provider->exists('generated-tls-a-com', 'a.com'))->toBeTrue()
        ->and($provider->get())->toHaveCount(1)
        ->and($provider->get()->first()->domain)->toBe('a.com')
        ->and($provider->generatedCalls())->toBe([['name' => 'generated-tls-a-com', 'domain' => 'a.com']]);
});

it('reports issued status with expiry once generated', function (): void {
    $provider = new ArrayProvider;
    $provider->generate('generated-tls-a-com', 'a.com');

    $report = $provider->status('generated-tls-a-com', 'a.com');

    expect($report->status)->toBe(CertificateStatus::Issued)
        ->and($report->expiresAt)->not->toBeNull();
});
