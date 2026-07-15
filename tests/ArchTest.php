<?php

declare(strict_types=1);

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

/*
 * Crypto primitives are crypto-for-laravel's, not ours: base64url, SHA-256
 * digests, RSA/ECDSA signing, the ECDSA DER ↔ raw `r‖s` conversion, account key
 * generation/loading, the JWK + its RFC 7638 thumbprint, and X.509 parsing all
 * route through RoundlyConsulting\Crypto.
 *
 * Exactly ONE carve-out is left: Acme\Csr — CSR generation and the self-signed
 * fallback (openssl_csr_*, openssl_pkey_new/_export, openssl_x509_export, and
 * the base64 of a PEM body). Crypto has no CSR module by design: enrollment is
 * certificate *content* policy, which is ours. Every other openssl_* in this
 * package is gone.
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
        'openssl_x509_read',
        'openssl_x509_parse',
        'openssl_x509_fingerprint',
        'openssl_x509_verify',
        'random_bytes',
        'base64_encode',
        'base64_decode',
    ])
    ->ignoring([
        'RoundlyConsulting\Certificates\Acme\Csr',
    ]);

it('keeps CSR generation out of crypto')
    ->expect('RoundlyConsulting\Certificates\Acme\Csr')
    ->not->toUse('RoundlyConsulting\Crypto');

it('leaves openssl in the CSR generator alone', function (): void {
    $offenders = [];

    foreach (phpFilesIn(__DIR__.'/../src') as $file) {
        if ($file->getBasename() === 'Csr.php') {
            continue;
        }

        if (preg_match('/\bopenssl_[a-z0-9_]+\s*\(/i', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getBasename();
        }
    }

    expect($offenders)->toBe([]);
});

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

    // The scan is dynamic, so a newly-@internal crypto class is covered the day
    // it lands. These two are named to prove the scan really sees them — the
    // X.509 gateway is the one this package came closest to needing.
    expect($internal)
        ->toContain('RoundlyConsulting\Crypto\X509\OpenSslX509')
        ->toContain('RoundlyConsulting\Crypto\Signature\OpenSsl');

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
