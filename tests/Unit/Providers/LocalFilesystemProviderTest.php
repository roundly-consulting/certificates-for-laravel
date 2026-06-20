<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Providers\LocalFilesystemProvider;
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;
use RoundlyConsulting\Certificates\Support\X509Parser;
use RoundlyConsulting\Certificates\Tests\Helpers\Pem;

beforeEach(function (): void {
    Storage::fake('local');
});

function fsStore(): FilesystemCertificateStore
{
    return new FilesystemCertificateStore(disk: 'local', path: 'certificates');
}

function selfSigningProvider(): LocalFilesystemProvider
{
    return new LocalFilesystemProvider(fsStore(), new X509Parser, new Csr, selfSignedDays: 30);
}

function importOnlyProvider(): LocalFilesystemProvider
{
    return new LocalFilesystemProvider(fsStore(), new X509Parser);
}

it('generates a self-signed certificate and reports it issued', function (): void {
    $provider = selfSigningProvider();
    $provider->generate('tls-app', 'app.com');

    $report = $provider->status('tls-app', 'app.com');

    expect($report->status)->toBe(CertificateStatus::Issued)
        ->and($report->expiresAt->isFuture())->toBeTrue()
        ->and($provider->exists('tls-app', 'app.com'))->toBeTrue();
});

it('generates a SAN certificate covering every domain', function (): void {
    $provider = selfSigningProvider();
    $provider->generateMany('tls-app', ['app.com', 'www.app.com', '*.app.com']);

    $report = $provider->status('tls-app', 'app.com');

    expect($report->domains)->toContain('app.com', 'www.app.com', '*.app.com');
});

it('is a no-op when material already exists', function (): void {
    $provider = selfSigningProvider();
    $provider->generate('tls-app', 'app.com');
    $first = fsStore()->get('tls-app')->certificatePem;

    $provider->generate('tls-app', 'app.com');

    expect(fsStore()->get('tls-app')->certificatePem)->toBe($first);
});

it('does nothing when not self-signing and no material is present', function (): void {
    $provider = importOnlyProvider();
    $provider->generate('tls-missing', 'app.com');

    expect($provider->exists('tls-missing', 'app.com'))->toBeFalse();
});

it('reads imported material and lists it', function (): void {
    $pem = Pem::selfSigned(['imported.com']);
    fsStore()->put('tls-imported', new StoredCertificate($pem['cert'], $pem['key']));

    $provider = importOnlyProvider();

    expect($provider->get())->toHaveCount(1)
        ->and($provider->get()->first()->domain)->toBe('imported.com')
        ->and($provider->status('tls-imported', 'imported.com')->status)->toBe(CertificateStatus::Issued);
});

it('reports an expired certificate', function (): void {
    $pem = Pem::selfSigned(['old.com'], days: 1);
    fsStore()->put('tls-old', new StoredCertificate($pem['cert'], $pem['key']));

    // Advance the clock past the (1-day) validity window.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(3));

    try {
        expect(importOnlyProvider()->status('tls-old', 'old.com')->status)->toBe(CertificateStatus::Expired);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('reports pending when nothing is stored', function (): void {
    expect(importOnlyProvider()->status('tls-none', 'none.com')->status)->toBe(CertificateStatus::Pending);
});
