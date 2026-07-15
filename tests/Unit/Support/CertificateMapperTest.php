<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Support\CertificateMapper;

/**
 * Frozen ParsedCertificate vectors for the committed `leaf` fixture, computed
 * from this engine. `ParsedCertificate` is the package's public contract: its
 * fingerprint is persisted on every certificate row and compared as a string,
 * so the members below are wire data — in particular the fingerprint is
 * UPPER-case hex, which OpenSSL does not emit and this parser folds by hand.
 */
const LEAF_COMMON_NAME = 'frozen.example';
const LEAF_SANS = ['frozen.example', 'www.frozen.example', '*.api.frozen.example'];
const LEAF_ISSUER = 'frozen.example';
const LEAF_SERIAL = '1234567890ABCDEF';
const LEAF_FINGERPRINT = '977509CF01EAAD1F31AF55982E7E6583716CCCD8F3494F2537B2AE7D9ED93FD1';
const LEAF_NOT_BEFORE = 1783943029;
const LEAF_NOT_AFTER = 2414663029;

/** The `org-only` fixture carries no CN at all — it pins the O fallbacks. */
const ORG_ISSUER = 'Roundly Frozen Authority';
const ORG_SERIAL = '0123456789';
const ORG_FINGERPRINT = '8EC5F9A1E98DF06F9C1171007159F00DD4F810437110D71C2B7AE46BE27B3D8A';

it('parses every frozen field of the committed leaf certificate', function (): void {
    $parsed = (new CertificateMapper)->parse(fixtureCertificatePem('leaf'));

    expect($parsed->commonName)->toBe(LEAF_COMMON_NAME)
        ->and($parsed->subjectAltNames)->toBe(LEAF_SANS)
        ->and($parsed->issuer)->toBe(LEAF_ISSUER)
        ->and($parsed->serial)->toBe(LEAF_SERIAL)
        ->and($parsed->notBefore->getTimestamp())->toBe(LEAF_NOT_BEFORE)
        ->and($parsed->notAfter->getTimestamp())->toBe(LEAF_NOT_AFTER)
        ->and($parsed->notBefore)->toBeInstanceOf(CarbonImmutable::class)
        ->and($parsed->notAfter)->toBeInstanceOf(CarbonImmutable::class);
});

it('persists the certificate fingerprint as UPPER-case sha256 hex', function (): void {
    $parsed = (new CertificateMapper)->parse(fixtureCertificatePem('leaf'));

    // OpenSSL emits lower-case; the stored form is upper-case. Both halves are
    // asserted, because a stored fingerprint is only ever compared as a string.
    expect($parsed->fingerprint)->toBe(LEAF_FINGERPRINT)
        ->and($parsed->fingerprint)->toBe(strtoupper((string) $parsed->fingerprint))
        ->and($parsed->fingerprint)->toHaveLength(64);
});

it('falls back to the issuer organization when the issuer carries no common name', function (): void {
    $parsed = (new CertificateMapper)->parse(fixtureCertificatePem('org-only'));

    expect($parsed->issuer)->toBe(ORG_ISSUER)
        ->and($parsed->commonName)->toBe('')
        ->and($parsed->subjectAltNames)->toBe([])
        ->and($parsed->serial)->toBe(ORG_SERIAL)
        ->and($parsed->fingerprint)->toBe(ORG_FINGERPRINT);
});

it('parses a self-signed certificate', function (): void {
    $certificate = selfSignedCertificate(['example.com', 'www.example.com'], days: 30);

    $parsed = (new CertificateMapper)->parse($certificate->leaf()->pem());

    expect($parsed->commonName)->toBe('example.com')
        ->and($parsed->subjectAltNames)->toContain('example.com', 'www.example.com')
        ->and($parsed->notAfter->isFuture())->toBeTrue()
        ->and($parsed->notBefore->isPast())->toBeTrue()
        ->and($parsed->fingerprint)->not->toBeNull()
        ->and($parsed->serial)->not->toBeNull()
        ->and($parsed->isExpired())->toBeFalse();
});

it('parses only the first certificate in a bundle', function (): void {
    $bundle = fixtureCertificatePem('leaf')."\n".fixtureCertificatePem('org-only');

    $parsed = (new CertificateMapper)->parse($bundle);

    expect($parsed->commonName)->toBe(LEAF_COMMON_NAME)
        ->and($parsed->fingerprint)->toBe(LEAF_FINGERPRINT);
});

it('throws on unparseable pem', function (): void {
    (new CertificateMapper)->parse('not a certificate');
})->throws(CertificateException::class);
