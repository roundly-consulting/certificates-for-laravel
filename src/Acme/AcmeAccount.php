<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Acme;

use Illuminate\Support\Facades\Storage;
use OpenSSLAsymmetricKey;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Exceptions\CryptoException;
use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;

/**
 * Manages the ACME account key: generation, persistence on a Storage disk, the
 * public JWK + thumbprint used in key authorizations, and the account kid.
 *
 * Key generation, loading, and the digest are crypto-for-laravel's. The JWK
 * itself is JOSE serialization crypto does not model, so the member extraction
 * and the RFC 7638 canonicalization stay here — and they are frozen by test
 * vectors, because the thumbprint feeds every challenge's key authorization.
 */
final class AcmeAccount
{
    private EcKey|RsaKey|null $key = null;

    private ?string $kid = null;

    public function __construct(
        private readonly string $disk = 'local',
        private readonly string $keyPath = 'acme/account.pem',
        private readonly string $keyType = 'EC',
        private readonly bool $autoRegister = true,
    ) {}

    public function generate(): void
    {
        $key = $this->newKey();

        try {
            $pem = $key->privatePem();
        } catch (CryptoException) {
            throw AcmeException::keyGenerationFailed();
        }

        $this->key = $key;
        Storage::disk($this->disk)->put($this->keyPath, $pem);
    }

    public function load(): EcKey|RsaKey
    {
        if ($this->key !== null) {
            return $this->key;
        }

        $disk = Storage::disk($this->disk);

        if (! $disk->exists($this->keyPath)) {
            if (! $this->autoRegister) {
                throw AcmeException::accountFailed('no account key found and auto registration is disabled');
            }

            $this->generate();

            /** @var EcKey|RsaKey $key */
            $key = $this->key;

            return $key;
        }

        return $this->key = $this->read((string) $disk->get($this->keyPath));
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->keyPath);
    }

    /**
     * The public JWK with keys in lexicographic order for thumbprint stability.
     *
     * @return array<string, string>
     */
    public function jwk(): array
    {
        $key = $this->load();
        $details = $this->details($key->key);

        if ($key instanceof EcKey) {
            /** @var array{x: string, y: string} $ec */
            $ec = $details['ec'];

            return [
                'crv' => 'P-256',
                'kty' => 'EC',
                'x' => Base64Url::encode($this->pad($ec['x'])),
                'y' => Base64Url::encode($this->pad($ec['y'])),
            ];
        }

        /** @var array{e: string, n: string} $rsa */
        $rsa = $details['rsa'];

        return [
            'e' => Base64Url::encode($rsa['e']),
            'kty' => 'RSA',
            'n' => Base64Url::encode($rsa['n']),
        ];
    }

    /**
     * base64url(sha256(canonical JWK)) — RFC 7638, used in challenge key
     * authorizations. The member order is lexicographic and the JSON carries no
     * whitespace: get either wrong and every challenge silently fails.
     */
    public function thumbprint(): string
    {
        $jwk = $this->jwk();
        ksort($jwk);

        $json = json_encode($jwk, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return Base64Url::encode((new Digest)->raw($json));
    }

    public function kid(): ?string
    {
        if ($this->kid !== null) {
            return $this->kid;
        }

        $disk = Storage::disk($this->disk);

        if ($disk->exists($this->kidPath())) {
            return $this->kid = (string) $disk->get($this->kidPath());
        }

        return null;
    }

    public function setKid(string $kid): void
    {
        $this->kid = $kid;
        Storage::disk($this->disk)->put($this->kidPath(), $kid);
    }

    /**
     * ACME account keys are EC (P-256) or RSA (2048-bit); anything else is a
     * misconfiguration rather than a key we could sign with.
     */
    private function newKey(): EcKey|RsaKey
    {
        try {
            return match ($this->keyType) {
                'RSA' => RsaKey::generate(),
                'EC' => EcKey::generate(),
                default => throw AcmeException::unexpectedKey(),
            };
        } catch (CryptoException) {
            throw AcmeException::keyGenerationFailed();
        }
    }

    private function read(string $pem): EcKey|RsaKey
    {
        try {
            $key = EcKey::private($pem);

            // jwk() publishes `crv: P-256` and pads both coordinates to 32
            // bytes, so a larger curve would be advertised — and thumbprinted —
            // as something it is not.
            return $key->curve === 'P-256' ? $key : throw AcmeException::unexpectedKey();
        } catch (CryptoException) {
            // Not an EC key — an RSA account key is equally valid.
        }

        try {
            return RsaKey::private($pem);
        } catch (CryptoException) {
            throw AcmeException::accountFailed('the stored account key is invalid');
        }
    }

    /**
     * A JWK carries the key's raw public members, which no crypto primitive
     * exposes — this is key serialization, not an algorithm.
     *
     * @return array<string, mixed>
     */
    private function details(OpenSSLAsymmetricKey $key): array
    {
        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw AcmeException::signingFailed();
        }

        return $details;
    }

    private function kidPath(): string
    {
        return $this->keyPath.'.kid';
    }

    private function pad(string $value): string
    {
        return str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}
