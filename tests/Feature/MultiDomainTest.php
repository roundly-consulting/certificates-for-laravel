<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Certificates\Actions\IssueCertificateAction;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Exceptions\InvalidDomainException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('has a domains column on the certificates table', function (): void {
    expect(Schema::hasColumn('certificates', 'domains'))->toBeTrue();
});

it('issues a SAN certificate from an array of domains via the builder', function (): void {
    $array = new ArrayProvider;
    app(CertificateProviderManager::class)->extend('array', fn (): CertificateProvider => $array);

    Certificates::for(['app.com', '*.app.com'])->using('array')->issue();

    expect($array->generatedManyCalls())->toHaveCount(1)
        ->and($array->generatedManyCalls()[0]['domains'])->toBe(['app.com', '*.app.com']);
});

it('appends SANs fluently with alsoFor', function (): void {
    $array = new ArrayProvider;
    app(CertificateProviderManager::class)->extend('array', fn (): CertificateProvider => $array);

    Certificates::for('app.com')->alsoFor('www.app.com', 'api.app.com')->using('array')->issue();

    expect($array->generatedManyCalls()[0]['domains'])->toBe(['app.com', 'www.app.com', 'api.app.com']);
});

it('persists SAN domains on the registry record', function (): void {
    $array = new ArrayProvider;
    app(CertificateProviderManager::class)->extend('array', fn (): CertificateProvider => $array);

    Certificates::for(['app.com', 'www.app.com'])->using('array')->issue();

    $record = Certificate::query()->where('domain', 'app.com')->first();

    expect($record->domains)->toBe(['app.com', 'www.app.com']);
});

it('falls back to single generate for one domain', function (): void {
    $array = new ArrayProvider;
    app(CertificateProviderManager::class)->extend('array', fn (): CertificateProvider => $array);

    Certificates::for('only.com')->using('array')->issue();

    expect($array->generatedCalls())->toHaveCount(1)
        ->and($array->generatedManyCalls())->toHaveCount(0);
});

it('rejects the whole batch when any SAN is invalid', function (): void {
    $action = app(IssueCertificateAction::class);

    $action->execute(IssueCertificateData::makeForDomains(['app.com', 'not a domain']));
})->throws(InvalidDomainException::class);
