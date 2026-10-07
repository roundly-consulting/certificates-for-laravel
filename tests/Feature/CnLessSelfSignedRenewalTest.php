<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Providers\LocalFilesystemProvider;
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;
use RoundlyConsulting\Certificates\Support\CertificateMapper;

/**
 * Regression (batch 11 follow-up #77): the filesystem driver told its own self-signed
 * material from CA-issued material by comparing the issuer CN with the subject CN. Since
 * hostnames over 64 characters issue without a CN (C-14), both sides were empty for such a
 * certificate, so it counted as CA-issued, was never re-minted, and renew() failed.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('certificates.default', 'filesystem');
    config()->set('certificates.drivers.filesystem.self_signed', true);

    $this->host = str_repeat('c', 60).'.'.str_repeat('d', 10).'.example.com';
});

it('renews a self-signed certificate that has no common name', function (): void {
    config()->set('certificates.drivers.filesystem.self_signed_days', 10);

    $certificate = Certificates::for($this->host)->using('filesystem')->issue();
    $before = $certificate->expires_at;
    $fingerprint = $certificate->fingerprint;

    // OpenSSL signs against the real clock, so prove the move with a longer lifetime.
    config()->set('certificates.drivers.filesystem.self_signed_days', 20);
    app(CertificateProviderManager::class)->forgetDrivers();

    $renewed = Certificates::renew($this->host);

    expect($renewed->status)->toBe(CertificateStatus::Renewed)
        ->and($renewed->expires_at?->greaterThan($before))->toBeTrue()
        ->and($renewed->fingerprint)->not->toBe($fingerprint);
});

it('still never overwrites CA-issued material that has no common name', function (): void {
    $csr = new Csr;

    $caKey = $csr->newKey();
    $caRequest = openssl_csr_new(['commonName' => 'Test CA'], $caKey);
    expect($caRequest)->toBeInstanceOf(OpenSSLCertificateSigningRequest::class);
    $ca = openssl_csr_sign($caRequest, null, $caKey, 30);

    $leafKey = $csr->newKey();
    $der = $csr->forDomains([$this->host], $leafKey);
    $request = "-----BEGIN CERTIFICATE REQUEST-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE REQUEST-----\n";
    $leaf = openssl_csr_sign($request, $ca, $caKey, 30);

    $leafPem = '';
    openssl_x509_export($leaf, $leafPem);
    expect(openssl_x509_parse($leafPem)['subject'] ?? null)->toBe([]);

    $store = new FilesystemCertificateStore(disk: 'local', path: 'certificates');
    $store->put('tls-ca-cnless', new StoredCertificate($leafPem, $csr->exportKey($leafKey)));

    (new LocalFilesystemProvider($store, new CertificateMapper, $csr))->generate('tls-ca-cnless', $this->host);

    expect($store->get('tls-ca-cnless')?->certificatePem)->toBe($leafPem);
});
