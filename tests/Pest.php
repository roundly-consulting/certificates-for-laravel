<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Tests\Fixtures\RegisteredCheckTestCase;
use RoundlyConsulting\Certificates\Tests\Fixtures\SwappedCertificateTestCase;
use RoundlyConsulting\Certificates\Tests\TestCase;
use RoundlyConsulting\Crypto\Testing\TestCertificateChain;
use RoundlyConsulting\Crypto\Testing\TestCertificates;

// Explicit paths, not `->in(__DIR__)`: the ModelSwap directory below needs a different
// base case (certificates.model pointed at the host subclass BEFORE boot), and a blanket
// bind would claim it first. ArchTest.php is listed because `swappableModelsAreNotFinal`
// reads the `certificates.model` config default and so needs the app booted — an arch
// file is not automatically test-cased.
uses(TestCase::class)->in('ArchTest.php', 'Feature', 'Unit');

// The model-swap proofs need `certificates.model` pointed at the host subclass BEFORE
// the providers boot, so they run on their own base case in their own directory — Pest
// binds a test case per directory, not per file.
uses(SwappedCertificateTestCase::class)->in('ModelSwap');

// The alert-channel wiring is read during the provider's boot(), so it too needs its
// own before-boot base case and directory.
uses(RegisteredCheckTestCase::class)->in('AlertsCheck');

/**
 * A throwaway self-signed certificate (with SANs) and its private key.
 *
 * Minting one by hand means an `openssl.cnf` carrying the sections OpenSSL
 * needs, which many hosts' default config lacks — crypto's TestCertificates
 * writes its own, so the fixture is one call here rather than a helper class of
 * our own.
 *
 * @param  list<string>  $domains
 */
function selfSignedCertificate(array $domains, int $days = 90): TestCertificateChain
{
    return TestCertificates::selfSigned(
        dnsNames: $domains,
        keyType: 'RSA',
        days: $days,
        commonName: $domains[0],
    );
}

/**
 * A committed ACME account key PEM (`ec` or `rsa`).
 *
 * These two keys are fixed on purpose: every frozen vector in this suite — the
 * JWK thumbprints and the RS256 flattened JWS — was computed from them with the
 * pre-crypto implementation, so drift in the JWS or the thumbprint surfaces as a
 * failing byte comparison here rather than as a rejected certificate order.
 */
function accountKeyPem(string $type): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/keys/acme-account-'.$type.'.pem');
}

/**
 * A committed X.509 fixture certificate (`leaf` or `org-only`).
 *
 * Certificates minted on the fly are random, so nothing about them can be
 * frozen. These two are fixed: every field the package persists — the
 * UPPER-case SHA-256 fingerprint above all — is pinned against them, so a
 * change of parsing engine surfaces as a failing byte comparison rather than as
 * a certificate row that silently no longer matches the one on disk.
 */
function fixtureCertificatePem(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/certs/'.$name.'.pem');
}
