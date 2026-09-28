<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;

/**
 * Regression: the filesystem driver reported issuance and renewal that never happened —
 * (a) without self_signed, generate() silently did nothing yet the row became Issued with
 * an invented now+90d expiry; (b) with self_signed, renewal returned early because material
 * existed, so the row said Renewed while the expiry never moved.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('certificates.default', 'filesystem');
});

function importPem(string $name, string $domain, int $days): void
{
    $certificate = selfSignedCertificate([$domain], $days);

    (new FilesystemCertificateStore(disk: 'local', path: 'certificates'))
        ->put($name, new StoredCertificate($certificate->leaf()->pem(), $certificate->leafKey->privatePem()));
}

it('refuses to issue without self-signing when no material was imported', function (): void {
    Event::fake([CertificateIssued::class, CertificateFailed::class]);
    config()->set('certificates.drivers.filesystem.self_signed', false);

    expect(fn () => Certificates::for('nomaterial.example.com')->using('filesystem')->issue())
        ->toThrow(CertificateException::class, 'not a CA');

    expect(Certificates::find('nomaterial.example.com'))
        ->status->toBe(CertificateStatus::Failed)
        ->expires_at->toBeNull();

    Event::assertDispatched(CertificateFailed::class);
    Event::assertNotDispatched(CertificateIssued::class);
});

it('registers imported material with its real expiry', function (): void {
    config()->set('certificates.drivers.filesystem.self_signed', false);
    importPem('generated-tls-imported-example-com', 'imported.example.com', 30);

    $certificate = Certificates::for('imported.example.com')->using('filesystem')->issue();

    expect($certificate->status)->toBe(CertificateStatus::Issued)
        ->and($certificate->daysUntilExpiry())->toBeIn([29, 30])
        ->and($certificate->fingerprint)->not->toBeNull();
});

it('re-mints a self-signed certificate on renewal so the expiry really moves', function (): void {
    config()->set('certificates.drivers.filesystem.self_signed', true);
    config()->set('certificates.drivers.filesystem.self_signed_days', 10);

    $certificate = Certificates::for('fs.example.com')->using('filesystem')->issue();
    $before = $certificate->expires_at;
    $fingerprint = $certificate->fingerprint;

    // OpenSSL signs against the real clock, so prove the move with a longer lifetime.
    config()->set('certificates.drivers.filesystem.self_signed_days', 20);
    app(CertificateProviderManager::class)->forgetDrivers();

    $renewed = Certificates::renew('fs.example.com');

    expect($renewed->status)->toBe(CertificateStatus::Renewed)
        ->and($renewed->expires_at?->greaterThan($before))->toBeTrue()
        ->and($renewed->fingerprint)->not->toBe($fingerprint);
});

it('fails a renewal of imported material that nobody replaced, and renews once it is', function (): void {
    Event::fake([CertificateFailed::class]);
    config()->set('certificates.drivers.filesystem.self_signed', false);
    importPem('generated-tls-imported-example-com', 'imported.example.com', 30);
    $certificate = Certificates::for('imported.example.com')->using('filesystem')->issue();

    expect(fn () => Certificates::renew('imported.example.com'))
        ->toThrow(CertificateException::class, 'did not produce a new certificate');

    expect($certificate->fresh()?->status)->toBe(CertificateStatus::Failed);
    Event::assertDispatched(CertificateFailed::class);

    importPem('generated-tls-imported-example-com', 'imported.example.com', 60);

    expect(Certificates::renew('imported.example.com'))
        ->status->toBe(CertificateStatus::Renewed)
        ->and($certificate->fresh()?->daysUntilExpiry())->toBeIn([59, 60]);
});
