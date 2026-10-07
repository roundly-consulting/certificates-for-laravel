<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;

/**
 * Regression (chat review C-18, owner decision A): the "is this host covered?" checks matched
 * the main domain only, so a host on a SAN certificate read as uncovered —
 * issueIfMissing('www.shop.example.com') issued a duplicate certificate. issueIfMissing(),
 * status() and HasCertificates::hasCertificateFor() now also find the SAN certificate;
 * find(), renew(), revoke() and expire() keep matching the main domain exactly.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'array');

    $this->tenant = Tenant::query()->create(['name' => 'shop']);
    $this->san = Certificates::for('shop.example.com')->alsoFor('www.shop.example.com')->owner($this->tenant)->issue();
});

it('does not issue a duplicate for a host a SAN certificate already covers', function (): void {
    expect(Certificates::issueIfMissing('www.shop.example.com')->id)->toBe($this->san->id)
        ->and(Certificates::for('www.shop.example.com')->issueIfMissing()->id)->toBe($this->san->id)
        ->and(Certificate::query()->count())->toBe(1);
});

it('reports the status of the SAN certificate covering a host', function (): void {
    expect(Certificates::status('www.shop.example.com'))->toBe(CertificateStatus::Issued)
        ->and(Certificates::for('www.shop.example.com')->status())->toBe(CertificateStatus::Issued)
        ->and(Certificates::status('www.shop.example.com', 'acme'))->toBeNull()
        ->and(Certificates::status('other.example.com'))->toBeNull();
});

it('tells the owner a SAN host is covered', function (): void {
    expect($this->tenant->hasCertificateFor('www.shop.example.com'))->toBeTrue()
        ->and($this->tenant->hasCertificateFor('other.example.com'))->toBeFalse();
});

it('keeps exact main-domain matching for find and the lifecycle verbs', function (): void {
    expect(Certificates::find('www.shop.example.com'))->toBeNull()
        ->and(fn () => Certificates::renew('www.shop.example.com'))->toThrow(CertificateException::class)
        ->and(fn () => Certificates::revoke('www.shop.example.com'))->toThrow(CertificateException::class)
        ->and(fn () => Certificates::expire('www.shop.example.com'))->toThrow(CertificateException::class);
});

it('covers SAN hosts under the fake as well', function (): void {
    Certificates::fake();
    $san = Certificates::for('fake.example.com')->alsoFor('www.fake.example.com')->issue();

    expect(Certificates::issueIfMissing('www.fake.example.com'))->toBe($san)
        ->and(Certificates::status('www.fake.example.com'))->toBe(CertificateStatus::Issued)
        ->and(Certificates::find('www.fake.example.com'))->toBeNull();

    Certificates::assertIssuedCount(1);
});
