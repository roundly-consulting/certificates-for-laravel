<?php

declare(strict_types=1);

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

/*
 * Crypto primitives are crypto-for-laravel's, not ours: base64url, SHA-256
 * digests, RSA/ECDSA signing, the ECDSA DER ↔ raw `r‖s` conversion, and account
 * key generation/loading all route through RoundlyConsulting\Crypto.
 *
 * Deliberately NOT covered by the ban:
 *
 *  - Acme\Csr — CSR generation and the self-signed fallback (openssl_csr_*,
 *    openssl_pkey_new/_export, openssl_x509_export, and the base64 of a PEM
 *    body). crypto has no X.509 or CSR module: it owns algorithms, we own
 *    certificate requests.
 *  - Support\X509Parser — certificate introspection (openssl_x509_parse /
 *    _fingerprint). Same carve-out: trust and certificate handling stay here.
 *  - Acme\AcmeAccount — openssl_pkey_get_details, to read the raw public
 *    members a JWK is built from. That is key serialization, not an algorithm,
 *    and crypto exposes no JWK export.
 */
arch('no crypto primitive is re-implemented locally')
    ->expect('RoundlyConsulting\Certificates')
    ->not->toUse([
        'hash',
        'hash_hmac',
        'hash_equals',
        'openssl_sign',
        'openssl_verify',
        'openssl_pkey_new',
        'openssl_pkey_get_private',
        'openssl_pkey_get_public',
        'openssl_pkey_get_details',
        'openssl_pkey_export',
        'random_bytes',
        'base64_encode',
        'base64_decode',
    ])
    ->ignoring([
        'RoundlyConsulting\Certificates\Acme\AcmeAccount',
        'RoundlyConsulting\Certificates\Acme\Csr',
        'RoundlyConsulting\Certificates\Support\X509Parser',
    ]);

it('keeps CSR and X.509 handling out of crypto')
    ->expect([
        'RoundlyConsulting\Certificates\Acme\Csr',
        'RoundlyConsulting\Certificates\Support\X509Parser',
    ])
    ->not->toUse('RoundlyConsulting\Crypto');

it('builds on crypto-for-laravel rather than a third-party crypto vendor')
    ->expect('RoundlyConsulting\Certificates')
    ->not->toUse([
        'Firebase\JWT',
        'Lcobucci\JWT',
        'Jose\Component',
        'ParagonIE',
        'phpseclib3',
        'AcmePhp',
    ]);

it('does not import a crypto class marked @internal', function (): void {
    $internal = [];

    foreach (phpFilesIn(__DIR__.'/../vendor/roundly-consulting/crypto-for-laravel/src') as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        if (! str_contains($contents, '@internal') || preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) !== 1) {
            continue;
        }

        $internal[] = $namespace[1].'\\'.$file->getBasename('.php');
    }

    expect($internal)->not->toBeEmpty();

    $offenders = [];

    foreach (phpFilesIn(__DIR__.'/../src') as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        foreach ($internal as $class) {
            if (str_contains($contents, 'use '.$class.';')) {
                $offenders[] = $file->getBasename().' → '.$class;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * @return list<SplFileInfo>
 */
function phpFilesIn(string $directory): array
{
    $files = [];

    /** @var iterable<SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file;
        }
    }

    return $files;
}
