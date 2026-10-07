<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Rules\ValidDomain;

/**
 * Regression (chat review C-14): the CSR's common name was always the first domain, but
 * RFC 5280 caps a CN at 64 characters while a hostname may run to 253 — so a long hostname
 * passed ValidDomain and then failed in openssl_csr_new() for ACME and self-signed alike.
 */
function longHost(): string
{
    return str_repeat('a', 60).'.'.str_repeat('b', 10).'.example.com';
}

function derToPem(string $der): string
{
    return "-----BEGIN CERTIFICATE REQUEST-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE REQUEST-----\n";
}

it('builds a CSR for a hostname longer than a common name may be', function (): void {
    $host = longHost();
    expect(strlen($host))->toBeGreaterThan(64)
        ->and((new ValidDomain)->passes($host))->toBeTrue();

    $csr = new Csr;
    $der = $csr->forDomains([$host], $csr->newKey());

    expect(openssl_csr_get_subject(derToPem($der)))->toBe([])
        ->and(str_contains($der, $host))->toBeTrue();
});

it('takes the first domain that fits as the common name', function (): void {
    $csr = new Csr;
    $der = $csr->forDomains([longHost(), 'short.example.com'], $csr->newKey());

    expect(openssl_csr_get_subject(derToPem($der)))->toBe(['CN' => 'short.example.com'])
        ->and(str_contains($der, longHost()))->toBeTrue();
});

it('self-signs a hostname longer than a common name may be', function (): void {
    [$certificate] = (new Csr)->selfSigned([longHost()], 30);

    $parsed = openssl_x509_parse($certificate);

    expect($parsed)->toBeArray()
        ->and($parsed['subject'] ?? null)->toBe([])
        ->and($parsed['extensions']['subjectAltName'] ?? '')->toBe('DNS:'.longHost());
});

it('issues a long hostname on the self-signing filesystem driver', function (): void {
    Storage::fake('local');
    config()->set('certificates.drivers.filesystem.self_signed', true);

    expect(Certificates::for(longHost())->using('filesystem')->issue()->status)->toBe(CertificateStatus::Issued);
});
