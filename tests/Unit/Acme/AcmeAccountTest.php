<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Acme\AcmeAccount;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;

/**
 * Frozen RFC 7638 thumbprints for the two committed account keys, computed from
 * the pre-crypto implementation. The thumbprint is the second half of every
 * challenge's key authorization: if it drifts by one byte, every DNS TXT record
 * and every http-01 token file we publish is wrong and no order ever validates.
 */
const EC_THUMBPRINT = 'DpAbuSaUplaRVIlOVIuqaTx0TmbkHsJ0Ww75jvLVhnQ';
const RSA_THUMBPRINT = 'OhZMC3VkAWpZpXknzUYp3bgjYiZmMfM5j8MQ3C7nbtg';

/**
 * The exact JWK members, and the exact canonical JSON the thumbprint is the
 * digest of. The members go on the wire in the newAccount protected header, and
 * the CA re-derives the thumbprint from them: member set, member order, and the
 * absence of whitespace are all load-bearing, so the bytes are pinned and not
 * just the digest.
 */
const EC_JWK = [
    'crv' => 'P-256',
    'kty' => 'EC',
    'x' => 'ghggVMUPwnvokrwVD4wxY2qbl30bMxj83XZ0Kks7N7I',
    'y' => 'XnDEzRuAjaFn3yU-kVRV3Tn9AetuIf-zl52T4TynVrQ',
];

const RSA_JWK = [
    'e' => 'AQAB',
    'kty' => 'RSA',
    'n' => 'rqo3D-YdxItJTr4CadjOarBGgwUssLVFx_-mbyLWRmUM4nWpbWbsK2BGkLteUMijsXqBp2VRYO6Niia-oCVIY6AkTupuy8IPPVrwbcEs6ZM9ceO-djNdZmJHTHLEoeDQeK05OUqDqfToJ1cNchNpUk2i2mJEP76cYv8XYa7XwEuzIJRsHnq3VV5QC9JWnxXdPeY6ZW-hF-MAWZzhWRd2vHDjb3it35q_yczMuRpgpKur-IzX8Ej76XEBybWzZpKeolZQjfIV9POqo9URyGZzh53vum8C6aaZnAPveJJB3juOGIFoyGLg1mzokn9VTol2d3flLFZFTF8U-juTUjEWYw',
];

const EC_JWK_JSON = '{"crv":"P-256","kty":"EC","x":"ghggVMUPwnvokrwVD4wxY2qbl30bMxj83XZ0Kks7N7I","y":"XnDEzRuAjaFn3yU-kVRV3Tn9AetuIf-zl52T4TynVrQ"}';
const RSA_JWK_JSON = '{"e":"AQAB","kty":"RSA","n":"rqo3D-YdxItJTr4CadjOarBGgwUssLVFx_-mbyLWRmUM4nWpbWbsK2BGkLteUMijsXqBp2VRYO6Niia-oCVIY6AkTupuy8IPPVrwbcEs6ZM9ceO-djNdZmJHTHLEoeDQeK05OUqDqfToJ1cNchNpUk2i2mJEP76cYv8XYa7XwEuzIJRsHnq3VV5QC9JWnxXdPeY6ZW-hF-MAWZzhWRd2vHDjb3it35q_yczMuRpgpKur-IzX8Ej76XEBybWzZpKeolZQjfIV9POqo9URyGZzh53vum8C6aaZnAPveJJB3juOGIFoyGLg1mzokn9VTol2d3flLFZFTF8U-juTUjEWYw"}';

beforeEach(function (): void {
    Storage::fake('local');
});

function account(string $type = 'EC', bool $autoRegister = true): AcmeAccount
{
    return new AcmeAccount(disk: 'local', keyPath: 'acme/account.pem', keyType: $type, autoRegister: $autoRegister);
}

/**
 * Seed the disk with one of the committed account keys.
 */
function storedAccount(string $type): AcmeAccount
{
    Storage::disk('local')->put('acme/account.pem', accountKeyPem(strtolower($type)));

    return account($type);
}

it('generates and persists an EC key', function (): void {
    $account = account('EC');
    $account->generate();

    expect(Storage::disk('local')->exists('acme/account.pem'))->toBeTrue()
        ->and($account->exists())->toBeTrue()
        ->and($account->load())->toBeInstanceOf(EcKey::class);

    $jwk = $account->jwk();

    expect($jwk['kty'])->toBe('EC')
        ->and($jwk['crv'])->toBe('P-256')
        ->and(array_keys($jwk))->toBe(['crv', 'kty', 'x', 'y']);
});

it('generates an RSA key with an RSA jwk', function (): void {
    $account = account('RSA');
    $account->generate();

    $jwk = $account->jwk();

    expect($account->load())->toBeInstanceOf(RsaKey::class)
        ->and($jwk['kty'])->toBe('RSA')
        ->and(array_keys($jwk))->toBe(['e', 'kty', 'n']);
});

it('rejects an unsupported configured key type', function (): void {
    account('Ed25519')->generate();
})->throws(AcmeException::class, 'unsupported type');

it('auto-registers a key on load when missing', function (): void {
    $account = account('EC');

    expect($account->exists())->toBeFalse();

    $account->load();

    expect($account->exists())->toBeTrue();
});

it('reloads a persisted key without regenerating it', function (): void {
    $account = account('EC');
    $account->generate();
    $thumbprint = $account->thumbprint();

    expect(account('EC')->thumbprint())->toBe($thumbprint);
});

it('caches the loaded key', function (): void {
    $account = storedAccount('EC');

    expect($account->load())->toBe($account->load());
});

it('throws when no key exists and auto-registration is disabled', function (): void {
    account('EC', autoRegister: false)->load();
})->throws(AcmeException::class);

it('throws when the stored account key is unreadable', function (): void {
    Storage::disk('local')->put('acme/account.pem', 'not a pem');

    account('EC')->load();
})->throws(AcmeException::class, 'the stored account key is invalid');

it('persists and reuses the account kid', function (): void {
    $account = account('EC');
    $account->generate();
    $account->setKid('https://acme.test/acct/1');

    expect(account('EC')->kid())->toBe('https://acme.test/acct/1')
        ->and($account->kid())->toBe('https://acme.test/acct/1');
});

it('has no kid before registration', function (): void {
    expect(account('EC')->kid())->toBeNull();
});

it('computes the exact RFC 7638 thumbprint the pre-crypto code produced', function (string $type, string $expected): void {
    expect(storedAccount($type)->thumbprint())->toBe($expected);
})->with([
    'ec' => ['EC', EC_THUMBPRINT],
    'rsa' => ['RSA', RSA_THUMBPRINT],
]);

it('publishes the exact JWK members the thumbprint is taken over', function (string $type, array $expected): void {
    expect(storedAccount($type)->jwk())->toBe($expected);
})->with([
    'ec' => ['EC', EC_JWK],
    'rsa' => ['RSA', RSA_JWK],
]);

it('canonicalizes the JWK to the exact bytes the thumbprint digests', function (string $type, string $json): void {
    $jwk = storedAccount($type)->jwk();
    ksort($jwk);

    // RFC 7638 §3.3: required members only, lexicographic, no whitespace,
    // slashes unescaped. The digest of these bytes IS the thumbprint.
    expect(json_encode($jwk, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))->toBe($json)
        ->and(Base64Url::encode((new Digest)->raw($json)))
        ->toBe($type === 'EC' ? EC_THUMBPRINT : RSA_THUMBPRINT);
})->with([
    'ec' => ['EC', EC_JWK_JSON],
    'rsa' => ['RSA', RSA_JWK_JSON],
]);

it('rejects an EC account key on a curve other than P-256', function (): void {
    Storage::disk('local')->put('acme/account.pem', EcKey::generate('P-384')->privatePem());

    account('EC')->load();
})->throws(AcmeException::class, 'unsupported type');
