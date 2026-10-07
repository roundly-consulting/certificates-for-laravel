<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Regression (chat review C-16): `Certificates::for($domain)->using($driver)` scoped find(),
 * renew() & co. to the driver, but status() read the domain's newest row on any driver and
 * exists() asked the default driver.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'null');

    Certificates::for('shop.example.com')->using('array')->issue();
    Certificate::factory()->forDomain('shop.example.com')->failed()->create(['driver' => 'other']);
});

it('reads status() and exists() on the driver chosen with using()', function (): void {
    $handle = Certificates::for('shop.example.com')->using('array');

    expect($handle->find()?->status)->toBe(CertificateStatus::Issued)
        ->and($handle->status())->toBe(CertificateStatus::Issued)
        ->and($handle->exists())->toBeTrue();
});

it('takes the driver on the manager too', function (): void {
    expect(Certificates::status('shop.example.com', 'array'))->toBe(CertificateStatus::Issued)
        ->and(Certificates::status('shop.example.com', 'other'))->toBe(CertificateStatus::Failed)
        ->and(Certificates::status('shop.example.com'))->toBe(CertificateStatus::Failed)
        ->and(Certificates::exists('shop.example.com', 'array'))->toBeTrue()
        ->and(Certificates::exists('shop.example.com'))->toBeFalse();
});

it('scopes the reads to the driver under the fake as well', function (): void {
    Certificates::fake();
    Certificates::for('fake.example.com')->using('array')->issue();

    expect(Certificates::for('fake.example.com')->using('array')->exists())->toBeTrue()
        ->and(Certificates::for('fake.example.com')->using('acme')->exists())->toBeFalse()
        ->and(Certificates::for('fake.example.com')->using('array')->status())->toBe(CertificateStatus::Issued)
        ->and(Certificates::for('fake.example.com')->using('acme')->status())->toBeNull();
});
