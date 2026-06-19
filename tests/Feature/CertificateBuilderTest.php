<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('issues through the fluent builder with all setters', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme']);

    $certificate = Certificates::for('shop.example.com')
        ->using('array')
        ->issuer('letsencrypt-prod')
        ->namespace('tenants')
        ->validForDays(30)
        ->meta(['tenant' => '1'])
        ->owner($tenant)
        ->issue();

    expect($certificate)
        ->status->toBe(CertificateStatus::Issued)
        ->issuer->toBe('letsencrypt-prod')
        ->and($certificate->certifiable_id)->toBe($tenant->id)
        ->and($certificate->meta)->toBe(['tenant' => '1']);
});

it('issueIfMissing is idempotent', function (): void {
    $first = Certificates::for('idem.example.com')->issueIfMissing();
    $second = Certificates::for('idem.example.com')->issueIfMissing();

    expect($second->id)->toBe($first->id)
        ->and(Certificate::query()->forDomain('idem.example.com')->count())->toBe(1);
});

it('exposes exists, status, and find through the builder', function (): void {
    expect(Certificates::for('none.example.com')->find())->toBeNull()
        ->and(Certificates::for('none.example.com')->status())->toBeNull();

    Certificates::for('here.example.com')->issue();

    expect(Certificates::for('here.example.com')->exists())->toBeTrue()
        ->and(Certificates::for('here.example.com')->status())->toBe(CertificateStatus::Issued)
        ->and(Certificates::for('here.example.com')->find())->not->toBeNull();
});
