<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Acme;

use OpenSSLAsymmetricKey;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;

/**
 * JSON Web Signature helpers for ACME (RFC 8555): base64url encoding and the
 * flattened JWS used for newAccount (JWK) and all kid-authenticated requests.
 */
final class Jws
{
    /**
     * base64url-encode (RFC 4648 §5): standard base64 with +/ → -_ and no padding.
     */
    public function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function b64decode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Sign a request with the embedded JWK (used for newAccount, before a kid).
     *
     * @param  array<string, mixed>  $protected
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, mixed>  $jwk
     * @return array{protected: string, payload: string, signature: string}
     */
    public function signWithJwk(array $protected, array|string $payload, OpenSSLAsymmetricKey $key, array $jwk): array
    {
        $protected['jwk'] = $jwk;

        return $this->sign($protected, $payload, $key, $this->algorithm($key));
    }

    /**
     * Sign a request with the account key id (used for every post-account request).
     *
     * @param  array<string, mixed>  $protected
     * @param  array<string, mixed>|string  $payload
     * @return array{protected: string, payload: string, signature: string}
     */
    public function signWithKid(array $protected, array|string $payload, OpenSSLAsymmetricKey $key, string $kid): array
    {
        $protected['kid'] = $kid;

        return $this->sign($protected, $payload, $key, $this->algorithm($key));
    }

    public function algorithm(OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw AcmeException::signingFailed();
        }

        return match ($details['type']) {
            OPENSSL_KEYTYPE_EC => 'ES256',
            OPENSSL_KEYTYPE_RSA => 'RS256',
            default => throw AcmeException::unexpectedKey(),
        };
    }

    /**
     * @param  array<string, mixed>  $protected
     * @param  array<string, mixed>|string  $payload
     * @return array{protected: string, payload: string, signature: string}
     */
    private function sign(array $protected, array|string $payload, OpenSSLAsymmetricKey $key, string $alg): array
    {
        $protected['alg'] = $alg;

        $encodedProtected = $this->b64($this->json($protected));
        $encodedPayload = $payload === '' ? '' : $this->b64(is_string($payload) ? $payload : $this->json($payload));

        $signingInput = $encodedProtected.'.'.$encodedPayload;

        $signature = '';

        if (openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256) === false) {
            throw AcmeException::signingFailed();
        }

        if ($alg === 'ES256') {
            $signature = $this->derToRaw($signature);
        }

        return [
            'protected' => $encodedProtected,
            'payload' => $encodedPayload,
            'signature' => $this->b64($signature),
        ];
    }

    /**
     * Convert a DER-encoded ECDSA signature to the raw R||S form ACME expects
     * for ES256 (each integer left-padded to 32 bytes).
     */
    public function derToRaw(string $der): string
    {
        $offset = 0;
        $this->expect($der, $offset, 0x30); // SEQUENCE
        $this->readLength($der, $offset);

        $r = $this->readInteger($der, $offset);
        $s = $this->readInteger($der, $offset);

        return $this->pad($r).$this->pad($s);
    }

    private function pad(string $value): string
    {
        $value = ltrim($value, "\x00");

        return str_pad($value, 32, "\x00", STR_PAD_LEFT);
    }

    private function expect(string $der, int &$offset, int $tag): void
    {
        if (ord($der[$offset]) !== $tag) {
            throw AcmeException::signingFailed();
        }

        $offset++;
    }

    private function readLength(string $der, int &$offset): int
    {
        $length = ord($der[$offset]);
        $offset++;

        if ($length <= 0x80) {
            return $length;
        }

        $bytes = $length & 0x7F;
        $length = 0;

        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) | ord($der[$offset]);
            $offset++;
        }

        return $length;
    }

    private function readInteger(string $der, int &$offset): string
    {
        $this->expect($der, $offset, 0x02); // INTEGER
        $length = $this->readLength($der, $offset);

        $value = substr($der, $offset, $length);
        $offset += $length;

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }
}
