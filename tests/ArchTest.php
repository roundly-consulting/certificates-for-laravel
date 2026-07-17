<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\CertificateService;
use RoundlyConsulting\Certificates\ChallengeSolvers\DnsChallengeSolver;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Testing\Arch\ArchPresets;

ArchPresets::strictTypes('RoundlyConsulting\Certificates');

/**
 * Four deliberate extension points: the registry model `certificates.model` invites a
 * host to subclass (pinned by the preset below instead), `CertificateException` as the
 * base every certificates error extends so a host can catch them uniformly,
 * `DnsChallengeSolver` as the abstract a host extends per DNS provider, and
 * `CertificateService`, the facade's backing service.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Certificates', [
    Certificate::class,
    CertificateException::class,
    DnsChallengeSolver::class,
    CertificateService::class,
]);

/**
 * The counter-weight, and the fleet's 7×-shipped fatal: `final` on a config-swappable
 * model is a PHP fatal error the moment a host uses the seam the config documents. The
 * preset also pins that `certificates.model` really defaults to the packaged model, so
 * the seam cannot rot in the other direction either.
 */
ArchPresets::swappableModelsAreNotFinal([
    Certificate::class => 'certificates.model',
]);

/**
 * `certificates.model` resolves through the CertificateModel seam in Support. Adopted
 * here rather than rejected as jwt rejected it: certificates has exactly the shape the
 * preset is aimed at — a real Eloquent model behind a conventionally named `model` key —
 * so the stray-literal half has something to say, and nothing here needs the late static
 * binding the preset bans (the seam returns a class-string and every call site goes
 * through `CertificateModel::class()`).
 */
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support');

/**
 * The Dependency Policy as a test — the assertion that caught bug #6 fleet-wide, where
 * CI installed testbench into `require` before the suite ran. No `alsoAllow`: this
 * package's `require` ships only php/ext/illuminate/roundly, and the workflow installs
 * test tooling with `--dev`. If it goes red the graph is wrong; never widen it to quiet
 * it.
 */
/**
 * The morph-key seam, guarded. The certifiable column migrated off raw `$table->morphs()`
 * onto `morphKey($name, KeyType::fromConfig(...))` so a uuid/ulid host can flip its whole
 * graph coherently — a hardcoded bigint id breaks those hosts on Postgres, and SQLite type
 * affinity hides it. This pin reds if a future migration reintroduces a raw morph.
 */
ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../database/migrations');

ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

/**
 * Replaces the hand-written `['dd', 'dump', 'ray']` rule above, which had a hole exactly
 * where it mattered: Pest's arch layer only sees a symbol that EXISTS, and `acme/ray`
 * is not in the dependency graph by policy, so `ray` was filtered out before the ban ran
 * and could never fail. The preset reads source tokens instead, and adds `var_dump` /
 * `print_r`, which this package never banned.
 */
ArchPresets::noDebuggingLeftovers([], __DIR__.'/../src');

/*
 * `ArchPresets::noLocalCryptoPrimitives` is deliberately NOT adopted, and this is the
 * one package where that is a strengthening rather than a gap.
 *
 * The bespoke rules below are strictly stronger than the preset on every axis that
 * matters here:
 *  - they ban more (`openssl_x509_*`, `hash_equals`, and named third-party crypto
 *    vendors the preset says nothing about);
 *  - the Csr carve-out is enforced by a TOKEN scan that skips exactly one FILE, rather
 *    than by Pest's `->ignoring()`, which is CLASS-scoped and would blind Csr to every
 *    other primitive at once — the same reasoning the preset's own docblock gives;
 *  - `it('keeps CSR generation out of crypto')` and the `@internal` scan express
 *    cross-package rules no preset has.
 * Adopting the preset on top would add nothing and would require exactly the
 * class-scoped exemption the bespoke rules exist to avoid. This package is
 * crypto-adjacent; its crypto rules are left as their authors wrote them.
 */

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
