<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Acme\AcmeAccount;
use RoundlyConsulting\Certificates\Acme\Jws;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;

beforeEach(function (): void {
    Storage::fake('local');
});

function account(string $type = 'EC', bool $autoRegister = true): AcmeAccount
{
    return new AcmeAccount(new Jws, disk: 'local', keyPath: 'acme/account.pem', keyType: $type, autoRegister: $autoRegister);
}

it('generates and persists an EC key', function (): void {
    $account = account('EC');
    $account->generate();

    expect(Storage::disk('local')->exists('acme/account.pem'))->toBeTrue()
        ->and($account->exists())->toBeTrue();

    $jwk = $account->jwk();

    expect($jwk['kty'])->toBe('EC')
        ->and($jwk['crv'])->toBe('P-256');
});

it('generates an RSA key with an RSA jwk', function (): void {
    $account = account('RSA');
    $account->generate();

    $jwk = $account->jwk();

    expect($jwk['kty'])->toBe('RSA')
        ->and($jwk)->toHaveKeys(['e', 'n']);
});

it('auto-registers a key on load when missing', function (): void {
    $account = account('EC');

    expect($account->exists())->toBeFalse();

    $account->load();

    expect($account->exists())->toBeTrue();
});

it('reloads a persisted key', function (): void {
    $account = account('EC');
    $account->generate();
    $thumbprint = $account->thumbprint();

    $reloaded = account('EC');

    expect($reloaded->thumbprint())->toBe($thumbprint);
});

it('throws when no key exists and auto-registration is disabled', function (): void {
    account('EC', autoRegister: false)->load();
})->throws(AcmeException::class);

it('persists and reuses the account kid', function (): void {
    $account = account('EC');
    $account->generate();
    $account->setKid('https://acme.test/acct/1');

    expect(account('EC')->kid())->toBe('https://acme.test/acct/1');
});

it('returns a stable thumbprint', function (): void {
    $account = account('EC');
    $account->generate();

    expect($account->thumbprint())->toBe($account->thumbprint());
});
