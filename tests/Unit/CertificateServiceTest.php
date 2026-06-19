<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\CertificateService;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Providers\NullProvider;
use RoundlyConsulting\Certificates\Support\CertificateBuilder;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

function service(): CertificateService
{
    return app(CertificateService::class);
}

it('derives a dns-safe certificate name with the configured prefix', function (): void {
    expect(service()->certificateName('app.example.com'))
        ->toBe('generated-tls-app-example-com');
});

it('replaces dots and colons in the derived name', function (): void {
    expect(service()->certificateName('app.example.com:8443'))
        ->toBe('generated-tls-app-example-com-8443');
});

it('issues a certificate and records it in the registry', function (): void {
    $certificate = service()->issue(IssueCertificateData::make('example.com'));

    expect($certificate)
        ->toBeInstanceOf(Certificate::class)
        ->domain->toBe('example.com')
        ->status->toBe(CertificateStatus::Issued);

    expect(Certificate::query()->forDomain('example.com')->exists())->toBeTrue();
});

it('generate records to the registry when the table exists', function (): void {
    expect(service()->generate('shop.example.com'))->toBeTrue();

    expect(service()->find('shop.example.com'))->not->toBeNull();
});

it('issueIfMissing is idempotent for an active certificate', function (): void {
    $first = service()->issueIfMissing('idem.example.com');
    $second = service()->issueIfMissing('idem.example.com');

    expect($second->id)->toBe($first->id);
});

it('finds by domain and reports status', function (): void {
    service()->issue(IssueCertificateData::make('found.example.com'));

    expect(service()->find('found.example.com'))->not->toBeNull()
        ->and(service()->status('found.example.com'))->toBe(CertificateStatus::Issued)
        ->and(service()->status('missing.example.com'))->toBeNull();
});

it('reports existence via the provider', function (): void {
    service()->issue(IssueCertificateData::make('exists.example.com'));

    expect(service()->exists('exists.example.com'))->toBeTrue()
        ->and(service()->exists('nope.example.com'))->toBeFalse();
});

it('resolves a driver instance', function (): void {
    expect(service()->driver('null'))
        ->toBeInstanceOf(NullProvider::class);
});

it('begins a fluent builder', function (): void {
    expect(service()->for('fluent.example.com'))
        ->toBeInstanceOf(CertificateBuilder::class);
});
