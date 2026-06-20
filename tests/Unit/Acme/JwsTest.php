<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Acme\Jws;

it('round-trips base64url', function (): void {
    $jws = new Jws;
    $raw = random_bytes(40);

    $encoded = $jws->b64($raw);

    expect($encoded)->not->toContain('+', '/', '=')
        ->and($jws->b64decode($encoded))->toBe($raw);
});

it('decodes invalid base64url to an empty string', function (): void {
    expect((new Jws)->b64decode('@@@@invalid'))->toBe('');
});

it('signs with an RSA key (RS256) verifiable by openssl', function (): void {
    $jws = new Jws;
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

    $jwt = $jws->signWithKid(['nonce' => 'n', 'url' => 'https://acme.test/x'], ['hello' => 'world'], $key, 'kid-1');

    expect($jwt)->toHaveKeys(['protected', 'payload', 'signature']);

    $details = openssl_pkey_get_details($key);
    $public = openssl_pkey_get_public($details['key']);

    $signature = $jws->b64decode($jwt['signature']);
    $verified = openssl_verify($jwt['protected'].'.'.$jwt['payload'], $signature, $public, OPENSSL_ALGO_SHA256);

    expect($verified)->toBe(1)
        ->and($jws->algorithm($key))->toBe('RS256');
});

it('signs with an EC key (ES256) producing a 64-byte raw signature', function (): void {
    $jws = new Jws;
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048]);

    $jwt = $jws->signWithJwk(['nonce' => 'n', 'url' => 'https://acme.test/x'], ['a' => 1], $key, ['kty' => 'EC']);

    $signature = $jws->b64decode($jwt['signature']);

    expect(strlen($signature))->toBe(64)
        ->and($jws->algorithm($key))->toBe('ES256');
});

it('handles an empty payload as post-as-get', function (): void {
    $jws = new Jws;
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

    $jwt = $jws->signWithKid(['nonce' => 'n', 'url' => 'u'], '', $key, 'kid');

    expect($jwt['payload'])->toBe('');
});

it('converts a der ecdsa signature to raw r||s', function (): void {
    $jws = new Jws;
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048]);

    $der = '';
    openssl_sign('payload', $der, $key, OPENSSL_ALGO_SHA256);

    expect(strlen($jws->derToRaw($der)))->toBe(64);
});
