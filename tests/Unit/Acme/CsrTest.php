<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\Support\X509Parser;

it('generates a DER csr for a single domain', function (): void {
    $csr = new Csr;
    $key = $csr->newKey();

    $der = $csr->forDomains(['single.com'], $key);

    expect($der)->not->toBeEmpty();

    // The PEM round-trips back to a readable subject.
    $pem = "-----BEGIN CERTIFICATE REQUEST-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END CERTIFICATE REQUEST-----';
    $subject = openssl_csr_get_subject($pem);

    expect($subject['CN'])->toBe('single.com');
});

it('exports the leaf private key', function (): void {
    $csr = new Csr;
    $key = $csr->newKey();

    expect($csr->exportKey($key))->toContain('PRIVATE KEY');
});

it('generates a self-signed certificate covering every SAN', function (): void {
    $csr = new Csr;

    [$cert, $key] = $csr->selfSigned(['app.com', 'www.app.com', '*.app.com'], days: 30);

    expect($cert)->toContain('BEGIN CERTIFICATE')
        ->and($key)->toContain('PRIVATE KEY');

    $parsed = (new X509Parser)->parse($cert);

    expect($parsed->commonName)->toBe('app.com')
        ->and($parsed->subjectAltNames)->toContain('app.com', 'www.app.com', '*.app.com');
});
