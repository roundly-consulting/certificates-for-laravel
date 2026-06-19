<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;

it('produces the expected status per state', function (): void {
    expect(Certificate::factory()->make()->status)->toBe(CertificateStatus::Pending)
        ->and(Certificate::factory()->issued()->make()->status)->toBe(CertificateStatus::Issued)
        ->and(Certificate::factory()->expiring()->make()->status)->toBe(CertificateStatus::Issued)
        ->and(Certificate::factory()->expired()->make()->status)->toBe(CertificateStatus::Expired)
        ->and(Certificate::factory()->failed()->make()->status)->toBe(CertificateStatus::Failed);
});

it('sets a domain via forDomain state', function (): void {
    $certificate = Certificate::factory()->forDomain('shop.example.com')->make();

    expect($certificate->domain)->toBe('shop.example.com')
        ->and($certificate->name)->toBe('generated-tls-shop-example-com');
});
