<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Acme;

use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Crypto\Exceptions\CryptoException;
use RoundlyConsulting\Crypto\Jose\FlattenedJws;
use RoundlyConsulting\Crypto\Jose\Jwk;
use RoundlyConsulting\Crypto\Jose\Jws as JoseJws;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;
use RoundlyConsulting\Crypto\Signature\Rs;
use RoundlyConsulting\Crypto\Signature\Signer;

/**
 * The ACME (RFC 8555) side of JWS: which mode a request is signed in, and how
 * an ACME payload becomes a JWS payload.
 *
 * The cryptography itself — flattened serialization, base64url, RSA/ECDSA
 * signing, and the DER → raw `r‖s` conversion ES256 needs — belongs to
 * crypto-for-laravel. What stays here is protocol: ACME embeds the account
 * **jwk** in the protected header for newAccount/keyChange and uses the account
 * **kid** for every other request, and that distinction is load-bearing.
 */
final class Jws
{
    public function __construct(private readonly JoseJws $jose = new JoseJws) {}

    /**
     * Sign a request with the embedded JWK (used for newAccount, before a kid).
     *
     * The JWK serializes itself into the header, so its member set and order are
     * the ones the CA re-derives the thumbprint from.
     *
     * @param  array<string, mixed>  $protected
     * @param  array<string, mixed>|string  $payload
     */
    public function signWithJwk(array $protected, array|string $payload, EcKey|RsaKey $key, Jwk $jwk): FlattenedJws
    {
        $protected['jwk'] = $jwk;

        return $this->sign($protected, $payload, $key);
    }

    /**
     * Sign a request with the account key id (used for every post-account request).
     *
     * @param  array<string, mixed>  $protected
     * @param  array<string, mixed>|string  $payload
     */
    public function signWithKid(array $protected, array|string $payload, EcKey|RsaKey $key, string $kid): FlattenedJws
    {
        $protected['kid'] = $kid;

        return $this->sign($protected, $payload, $key);
    }

    /**
     * The JOSE `alg` an account key signs with — ACME accepts an EC or an RSA
     * account key, and the header name follows from the key's own type and
     * curve (ES256 for P-256, ES384 for P-384, RS256 for RSA). A P-384 key
     * signed under an ES256 header is rejected by the CA, so the mapping is the
     * key's, never a constant.
     */
    public function algorithm(EcKey|RsaKey $key): string
    {
        return $this->signer($key)->algorithm()->value;
    }

    /**
     * @param  array<string, mixed>  $protected
     * @param  array<string, mixed>|string  $payload
     */
    private function sign(array $protected, array|string $payload, EcKey|RsaKey $key): FlattenedJws
    {
        try {
            return $this->jose->flattened($protected, $this->payload($payload), $this->signer($key));
        } catch (CryptoException $e) {
            throw AcmeException::signingFailed($e);
        }
    }

    private function signer(EcKey|RsaKey $key): Signer
    {
        return $key instanceof EcKey ? new Es($key) : new Rs($key);
    }

    /**
     * ACME payloads are either an already-serialized string (`''` for
     * POST-as-GET) or a JSON object to encode.
     *
     * An empty payload array is the body-less challenge response of RFC 8555
     * §7.5.1, which must still be an empty JSON *object*. PHP encodes `[]` as a
     * JSON array, and Boulder rejects that as malformed — so it is pinned to
     * `{}` here. Only an empty array is special-cased; every other payload is
     * encoded exactly as before.
     *
     * @param  array<string, mixed>|string  $payload
     */
    private function payload(array|string $payload): string
    {
        if (is_string($payload)) {
            return $payload;
        }

        if ($payload === []) {
            return '{}';
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }
}
