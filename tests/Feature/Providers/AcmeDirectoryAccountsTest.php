<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Acme\AcmeAccount;
use RoundlyConsulting\Certificates\Acme\AcmeClient;
use RoundlyConsulting\Certificates\Acme\Jws;
use RoundlyConsulting\Crypto\Codec\Base64Url;

/*
 * An ACME account is a (CA, key) pair: the kid a CA hands back is an URL on THAT CA and
 * means nothing anywhere else. The documented workflow — test against Let's Encrypt
 * staging, then switch `directory` to production — must therefore register a second
 * account, not replay staging's kid at production (`accountDoesNotExist`).
 */

const STAGING = 'https://staging.acme.test/directory';
const PRODUCTION = 'https://prod.acme.test/directory';

beforeEach(function (): void {
    Storage::fake('local');
    fakeTwoCertificateAuthorities();
});

function fakeTwoCertificateAuthorities(): void
{
    $headers = ['Replay-Nonce' => 'nonce-'.uniqid()];

    foreach (['staging' => 'https://staging.acme.test', 'prod' => 'https://prod.acme.test'] as $name => $origin) {
        Http::fake([
            "{$origin}/directory" => Http::response([
                'newNonce' => "{$origin}/new-nonce",
                'newAccount' => "{$origin}/new-acct",
                'newOrder' => "{$origin}/new-order",
            ], 200, $headers),
            "{$origin}/new-nonce" => Http::response('', 200, $headers),
            // A CA answers newAccount for a key it already knows with 200 + the SAME
            // account URL (RFC 8555 §7.3.1), so re-registering is always safe.
            "{$origin}/new-acct" => Http::response(['status' => 'valid'], 201, $headers + ['Location' => "{$origin}/acct/{$name}-1"]),
            "{$origin}/new-order" => Http::response([
                'status' => 'pending',
                'identifiers' => [['type' => 'dns', 'value' => 'app.com']],
                'authorizations' => [],
                'finalize' => "{$origin}/finalize/1",
            ], 201, $headers + ['Location' => "{$origin}/order/1"]),
        ]);
    }
}

function acmeClientFor(string $directory): AcmeClient
{
    return new AcmeClient(
        jws: new Jws,
        account: new AcmeAccount(disk: 'local', keyPath: 'acme/account.pem', keyType: 'EC'),
        directoryUrl: $directory,
    );
}

/** @return list<string> */
function newAccountPostsTo(string $origin): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === "{$origin}/new-acct")
        ->map(fn (Request $request): string => $request->url())
        ->values()
        ->all();
}

/** The kid the JWS of the last order request was signed under. */
function kidOfLastOrder(string $origin): ?string
{
    $order = collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->last(fn (Request $request): bool => $request->url() === "{$origin}/new-order");

    /** @var array{protected: string} $body */
    $body = json_decode($order->body(), true, 512, JSON_THROW_ON_ERROR);

    return json_decode(Base64Url::decode($body['protected']), true, 512, JSON_THROW_ON_ERROR)['kid'] ?? null;
}

it('registers a new account when the directory switches from staging to production', function (): void {
    expect(acmeClientFor(STAGING)->registerAccount())->toBe('https://staging.acme.test/acct/staging-1');

    $production = acmeClientFor(PRODUCTION);

    expect($production->registerAccount())->toBe('https://prod.acme.test/acct/prod-1')
        ->and(newAccountPostsTo('https://prod.acme.test'))->toHaveCount(1);

    // Every later production request is signed under PRODUCTION's kid.
    $production->newOrder(['app.com']);

    expect(kidOfLastOrder('https://prod.acme.test'))->toBe('https://prod.acme.test/acct/prod-1');
});

it('keeps each directory its own account on the way back', function (): void {
    acmeClientFor(STAGING)->registerAccount();
    acmeClientFor(PRODUCTION)->registerAccount();

    $staging = acmeClientFor(STAGING);

    expect($staging->registerAccount())->toBe('https://staging.acme.test/acct/staging-1')
        // Staging's record was reused, not re-registered.
        ->and(newAccountPostsTo('https://staging.acme.test'))->toHaveCount(1);

    $staging->newOrder(['app.com']);

    expect(kidOfLastOrder('https://staging.acme.test'))->toBe('https://staging.acme.test/acct/staging-1');
});

it('ignores the old unkeyed kid record instead of replaying it at another CA', function (): void {
    // What every earlier version wrote: one kid next to the key, no CA recorded.
    Storage::disk('local')->put('acme/account.pem', accountKeyPem('ec'));
    Storage::disk('local')->put('acme/account.pem.kid', 'https://staging.acme.test/acct/staging-1');

    $production = acmeClientFor(PRODUCTION);

    expect($production->registerAccount())->toBe('https://prod.acme.test/acct/prod-1')
        ->and(newAccountPostsTo('https://prod.acme.test'))->toHaveCount(1);

    // Left in place: it is harmless, and deleting a file the host may still read is not ours to do.
    Storage::disk('local')->assertExists('acme/account.pem.kid');
});

it('does not reuse a kid that was registered for a different account key', function (): void {
    acmeClientFor(STAGING)->registerAccount();

    // The key is replaced (deleted and regenerated, or rotated by hand): the stored kid
    // belongs to the OLD key, and a JWS signed with the new one under it is rejected.
    Storage::disk('local')->delete('acme/account.pem');

    expect(acmeClientFor(STAGING)->registerAccount())->toBe('https://staging.acme.test/acct/staging-1')
        ->and(newAccountPostsTo('https://staging.acme.test'))->toHaveCount(2);
});

it('stores one record per directory, next to the key', function (): void {
    acmeClientFor(STAGING)->registerAccount();
    acmeClientFor(PRODUCTION)->registerAccount();

    $records = collect(Storage::disk('local')->files('acme'))
        ->filter(fn (string $path): bool => str_ends_with($path, '.kid'))
        ->values();

    expect($records)->toHaveCount(2)
        ->and($records->every(fn (string $path): bool => str_starts_with($path, 'acme/account.pem.')))->toBeTrue();
});
