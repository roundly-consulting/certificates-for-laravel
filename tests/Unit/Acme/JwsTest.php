<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Acme\Jws;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Jose\FlattenedJws;
use RoundlyConsulting\Crypto\Jose\Jwk;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;
use RoundlyConsulting\Crypto\Signature\Rs;

/**
 * Frozen vectors, computed from the pre-crypto implementation with the committed
 * account keys. RSA PKCS#1 v1.5 is deterministic, so the whole flattened JWS is
 * pinned byte-for-byte; ECDSA is not, so ES256 is verified instead.
 */
const RS256_PROTECTED = 'eyJub25jZSI6ImZpeGVkLW5vbmNlIiwidXJsIjoiaHR0cHM6Ly9hY21lLnRlc3Qvb3JkZXIiLCJraWQiOiJodHRwczovL2FjbWUudGVzdC9hY2N0LzEiLCJhbGciOiJSUzI1NiJ9';
const RS256_PAYLOAD = 'eyJ0ZXJtc09mU2VydmljZUFncmVlZCI6dHJ1ZX0';
const RS256_SIGNATURE = 'GoAuBH6LR29KHmT9b6Em9Xp2Y5u5jH1QJc0NRA_EqmmU1imC0af-cWhL3juXWqD4mcgDtvi60jN5rFl41GbUVqQD1AYrqVMMke9oN8V1w6NzuMMh1jSv8_CfeLIgJZWvBENdhbQJz-s-Mx1SQCIpDfTaZFCFmrJYBnlFX9GH2KpQ3fiaOcZ3K_127deUjrN56QrkefcUUe6cGlvzQpiue_eHepFNw_-2ZvBWONow5za1o3lWBSvfJGbqhE341P3PobyL7T2hslitCqfchjbqB0W78sbun06nU-JOyL_wLEkXopjfKOjhzERdZEItLenfrCW0kIvpBKeMIqj9xfDqxw';

it('emits the exact flattened JWS the pre-crypto signer produced (RS256)', function (): void {
    $jws = (new Jws)->signWithKid(
        ['nonce' => 'fixed-nonce', 'url' => 'https://acme.test/order'],
        ['termsOfServiceAgreed' => true],
        RsaKey::private(accountKeyPem('rsa')),
        'https://acme.test/acct/1',
    );

    expect($jws)->toBeInstanceOf(FlattenedJws::class)
        ->and($jws->protected)->toBe(RS256_PROTECTED)
        ->and($jws->payload)->toBe(RS256_PAYLOAD)
        ->and($jws->signature)->toBe(RS256_SIGNATURE)
        ->and($jws->jsonSerialize())->toBe([
            'protected' => RS256_PROTECTED,
            'payload' => RS256_PAYLOAD,
            'signature' => RS256_SIGNATURE,
        ]);
});

it('keeps the protected header ACME expects, with the jwk before the alg', function (): void {
    $key = EcKey::private(accountKeyPem('ec'));

    $jws = (new Jws)->signWithJwk(
        ['nonce' => 'n', 'url' => 'https://acme.test/new-acct'],
        ['termsOfServiceAgreed' => true],
        $key,
        Jwk::fromPublicKey($key),
    );

    // The JWK serializes in place, members lexicographic — the exact bytes the
    // CA thumbprints.
    expect(Base64Url::decode($jws->protected))
        ->toBe('{"nonce":"n","url":"https://acme.test/new-acct","jwk":{"crv":"P-256","kty":"EC","x":"ghggVMUPwnvokrwVD4wxY2qbl30bMxj83XZ0Kks7N7I","y":"XnDEzRuAjaFn3yU-kVRV3Tn9AetuIf-zl52T4TynVrQ"},"alg":"ES256"}');
});

it('signs a P-384 account key under an ES384 header', function (): void {
    $key = EcKey::generate('P-384');

    $jws = (new Jws)->signWithJwk(
        ['nonce' => 'n', 'url' => 'https://acme.test/new-acct'],
        ['termsOfServiceAgreed' => true],
        $key,
        Jwk::fromPublicKey($key),
    );

    /** @var array{alg: string, jwk: array{crv: string}} $header */
    $header = json_decode(Base64Url::decode($jws->protected), true, 512, JSON_THROW_ON_ERROR);

    // An ES256 header over a P-384 key is rejected by the CA outright; the alg
    // and the curve must agree, and both come from the key.
    expect($header['alg'])->toBe('ES384')
        ->and($header['jwk']['crv'])->toBe('P-384')
        ->and((new Jws)->algorithm($key))->toBe('ES384')
        ->and(strlen(Base64Url::decode($jws->signature)))->toBe(96)
        ->and((new Es(EcKey::public($key->publicPem())))->verify(
            $jws->protected.'.'.$jws->payload,
            Base64Url::decode($jws->signature),
        ))->toBeTrue();
});

it('signs with the kid, never the jwk, once the account is registered', function (): void {
    $jws = (new Jws)->signWithKid(
        ['nonce' => 'n', 'url' => 'https://acme.test/order'],
        '',
        EcKey::private(accountKeyPem('ec')),
        'https://acme.test/acct/1',
    );

    /** @var array<string, mixed> $header */
    $header = json_decode(Base64Url::decode($jws->protected), true, 512, JSON_THROW_ON_ERROR);

    expect($header)->toHaveKey('kid', 'https://acme.test/acct/1')
        ->and($header)->not->toHaveKey('jwk');
});

it('produces a raw 64-byte r||s signature for ES256, not DER', function (): void {
    $key = EcKey::private(accountKeyPem('ec'));

    $jws = (new Jws)->signWithJwk(['nonce' => 'n', 'url' => 'u'], ['a' => 1], $key, Jwk::fromPublicKey($key));

    $signature = Base64Url::decode($jws->signature);

    // DER would start with a 0x30 SEQUENCE tag and vary in length; ACME requires
    // the fixed-width JOSE form. Let's Encrypt rejects anything else.
    expect(strlen($signature))->toBe(64)
        ->and(bin2hex($signature[0]))->not->toBe('30')
        ->and((new Es(EcKey::public($key->publicPem())))->verify($jws->protected.'.'.$jws->payload, $signature))->toBeTrue();
});

it('produces a signature the account public key verifies (RS256)', function (): void {
    $key = RsaKey::private(accountKeyPem('rsa'));

    $jws = (new Jws)->signWithKid(['nonce' => 'n', 'url' => 'u'], ['a' => 1], $key, 'kid');

    $verifier = new Rs(RsaKey::public($key->publicPem()));

    expect($verifier->verify($jws->protected.'.'.$jws->payload, Base64Url::decode($jws->signature)))->toBeTrue()
        ->and($verifier->verify('tampered.'.$jws->payload, Base64Url::decode($jws->signature)))->toBeFalse();
});

it('encodes an empty payload as an empty segment (post-as-get)', function (): void {
    $jws = (new Jws)->signWithKid(['nonce' => 'n', 'url' => 'u'], '', RsaKey::private(accountKeyPem('rsa')), 'kid');

    expect($jws->payload)->toBe('');
});

it('encodes an empty payload array as an empty JSON object, never an array', function (): void {
    // RFC 8555 §7.5.1 — the challenge response is `{}`. PHP's natural encoding of
    // `[]` is `[]`, which Boulder rejects; the signed bytes must be `{}` exactly.
    $jws = (new Jws)->signWithKid(['nonce' => 'n', 'url' => 'u'], [], RsaKey::private(accountKeyPem('rsa')), 'kid');

    expect(Base64Url::decode($jws->payload))->toBe('{}')
        ->and($jws->payload)->toBe(Base64Url::encode('{}'))
        ->and($jws->payload)->not->toBe('')
        ->and(Base64Url::decode($jws->payload))->not->toBe('[]');
});

it('leaves every non-empty payload byte-identical', function (array $payload, string $json): void {
    $jws = (new Jws)->signWithKid(['nonce' => 'n', 'url' => 'u'], $payload, RsaKey::private(accountKeyPem('rsa')), 'kid');

    expect(Base64Url::decode($jws->payload))->toBe($json);
})->with([
    'newAccount' => [
        ['termsOfServiceAgreed' => true, 'contact' => ['mailto:ops@app.com']],
        '{"termsOfServiceAgreed":true,"contact":["mailto:ops@app.com"]}',
    ],
    // A nested JSON array (the identifiers list) must survive as an array — this
    // is why the empty-object fix cannot be a global JSON_FORCE_OBJECT.
    'newOrder' => [
        ['identifiers' => [['type' => 'dns', 'value' => 'app.com'], ['type' => 'dns', 'value' => 'www.app.com']]],
        '{"identifiers":[{"type":"dns","value":"app.com"},{"type":"dns","value":"www.app.com"}]}',
    ],
    'finalize' => [['csr' => 'MIIB-abc'], '{"csr":"MIIB-abc"}'],
]);

it('names the algorithm from the account key type', function (string $type, string $algorithm): void {
    $key = $type === 'ec'
        ? EcKey::private(accountKeyPem('ec'))
        : RsaKey::private(accountKeyPem('rsa'));

    expect((new Jws)->algorithm($key))->toBe($algorithm);
})->with([
    'ec' => ['ec', 'ES256'],
    'rsa' => ['rsa', 'RS256'],
]);

it('falls back to an empty object when a payload cannot be encoded', function (): void {
    $jws = (new Jws)->signWithKid(
        ['nonce' => 'n', 'url' => 'u'],
        ['broken' => "\xB1\x31"], // invalid UTF-8 — json_encode returns false
        RsaKey::private(accountKeyPem('rsa')),
        'kid',
    );

    expect(Base64Url::decode($jws->payload))->toBe('{}');
});

it('translates a crypto failure into an AcmeException', function (): void {
    // A public key cannot sign — crypto throws, and the boundary re-wraps it.
    $public = RsaKey::public(RsaKey::private(accountKeyPem('rsa'))->publicPem());

    (new Jws)->signWithKid(['nonce' => 'n', 'url' => 'u'], ['a' => 1], $public, 'kid');
})->throws(AcmeException::class, 'Failed to sign the ACME request payload.');
