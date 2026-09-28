<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
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
        ->validForDays(30)
        ->meta(['tenant' => '1'])
        ->owner($tenant)
        ->issue();

    expect($certificate)
        ->status->toBe(CertificateStatus::Issued)
        ->driver->toBe('array')
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

/**
 * Regression: the README's builder chained ->issuer('letsencrypt-prod')->namespace('tenants')
 * as if they chose the issuer and namespace, but no provider ever received them — the
 * kubernetes driver always used drivers.kubernetes.namespace/issuer. They are gone rather
 * than kept as switches that silently do nothing: a driver's issuer and namespace are its
 * configuration.
 */
it('offers no per-certificate issuer or namespace that no provider could honour', function (string $method): void {
    expect(fn () => Certificates::for('a.example.com')->{$method}('x'))
        ->toThrow(BadMethodCallException::class);
})->with(['issuer', 'namespace']);

it('keeps issuer and namespace off the issue DTO and command', function (): void {
    expect(property_exists(IssueCertificateData::class, 'issuer'))->toBeFalse()
        ->and(property_exists(IssueCertificateData::class, 'namespace'))->toBeFalse()
        ->and(fn () => $this->artisan('certificates:issue', ['domain' => 'a.example.com', '--namespace' => 'tenants']))
        ->toThrow(InvalidArgumentException::class, 'The "--namespace" option does not exist.');
});
