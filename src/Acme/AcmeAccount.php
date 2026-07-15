<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Acme;

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Crypto\Exceptions\CryptoException;
use RoundlyConsulting\Crypto\Jose\Jwk;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;

/**
 * Manages the ACME account key: generation, persistence on a Storage disk, the
 * public JWK + thumbprint used in key authorizations, and the account kid.
 *
 * Key generation, loading, the JWK serialization and the RFC 7638 thumbprint are
 * all crypto-for-laravel's. What stays here is the ACME account itself: where
 * the key lives, which key types a CA accepts, and the kid the server hands
 * back. The curve label and the coordinate padding come from the key, so an EC
 * account key is always advertised as the curve it actually is.
 */
final class AcmeAccount
{
    /** Let's Encrypt accepts a P-256 or a P-384 EC account key; P-256 is the default. */
    private const array CURVES = ['P-256', 'P-384'];

    private EcKey|RsaKey|null $key = null;

    private ?Jwk $jwk = null;

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
        $this->jwk = null;

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
     * The account's public JWK (RFC 7517) — embedded in the protected header of
     * newAccount, and the input to the thumbprint.
     */
    public function jwk(): Jwk
    {
        if ($this->jwk !== null) {
            return $this->jwk;
        }

        try {
            return $this->jwk = Jwk::fromPublicKey($this->load());
        } catch (CryptoException $e) {
            throw AcmeException::signingFailed($e);
        }
    }

    /**
     * base64url(sha256(canonical JWK)) — RFC 7638, used in challenge key
     * authorizations. Get one byte of it wrong and every challenge silently
     * fails, so the canonicalization is crypto's, pinned by frozen vectors.
     */
    public function thumbprint(): string
    {
        return $this->jwk()->thumbprint();
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

            // A curve the CA does not accept is a misconfiguration; the JWK
            // itself describes whichever of the two this is, and signs with the
            // matching alg (ES256 / ES384).
            return in_array($key->curve, self::CURVES, true) ? $key : throw AcmeException::unexpectedKey();
        } catch (CryptoException) {
            // Not an EC key — an RSA account key is equally valid.
        }

        try {
            return RsaKey::private($pem);
        } catch (CryptoException) {
            throw AcmeException::accountFailed('the stored account key is invalid');
        }
    }

    private function kidPath(): string
    {
        return $this->keyPath.'.kid';
    }
}
