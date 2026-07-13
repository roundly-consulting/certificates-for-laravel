<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Acme\AcmeAccount;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;
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

it('publishes the exact JWK members the thumbprint is taken over', function (): void {
    expect(storedAccount('EC')->jwk())->toBe([
        'crv' => 'P-256',
        'kty' => 'EC',
        'x' => 'ghggVMUPwnvokrwVD4wxY2qbl30bMxj83XZ0Kks7N7I',
        'y' => 'XnDEzRuAjaFn3yU-kVRV3Tn9AetuIf-zl52T4TynVrQ',
    ]);
});

it('rejects an EC account key on a curve other than P-256', function (): void {
    Storage::disk('local')->put('acme/account.pem', EcKey::generate('P-384')->privatePem());

    account('EC')->load();
})->throws(AcmeException::class, 'unsupported type');
