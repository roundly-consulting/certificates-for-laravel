<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Acme;

use Illuminate\Support\Facades\Storage;
use OpenSSLAsymmetricKey;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;

/**
 * Manages the ACME account key: generation, persistence on a Storage disk, the
 * public JWK + thumbprint used in key authorizations, and the account kid.
 */
final class AcmeAccount
{
    private ?OpenSSLAsymmetricKey $key = null;

    private ?string $kid = null;

    public function __construct(
        private readonly Jws $jws,
        private readonly string $disk = 'local',
        private readonly string $keyPath = 'acme/account.pem',
        private readonly string $keyType = 'EC',
        private readonly bool $autoRegister = true,
    ) {}

    public function generate(): void
    {
        $config = $this->keyType === 'RSA'
            ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]
            : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048];

        $key = openssl_pkey_new($config);

        if ($key === false) {
            throw AcmeException::keyGenerationFailed();
        }

        $pem = '';

        if (openssl_pkey_export($key, $pem) === false) {
            throw AcmeException::keyGenerationFailed();
        }

        $this->key = $key;
        Storage::disk($this->disk)->put($this->keyPath, $pem);
    }

    public function load(): OpenSSLAsymmetricKey
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

            /** @var OpenSSLAsymmetricKey $key */
            $key = $this->key;

            return $key;
        }

        $key = openssl_pkey_get_private((string) $disk->get($this->keyPath));

        if ($key === false) {
            throw AcmeException::accountFailed('the stored account key is invalid');
        }

        return $this->key = $key;
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
        $details = openssl_pkey_get_details($this->load());

        if ($details === false) {
            throw AcmeException::signingFailed();
        }

        if ($details['type'] === OPENSSL_KEYTYPE_EC) {
            return [
                'crv' => 'P-256',
                'kty' => 'EC',
                'x' => $this->jws->b64($this->pad($details['ec']['x'])),
                'y' => $this->jws->b64($this->pad($details['ec']['y'])),
            ];
        }

        if ($details['type'] === OPENSSL_KEYTYPE_RSA) {
            return [
                'e' => $this->jws->b64($details['rsa']['e']),
                'kty' => 'RSA',
                'n' => $this->jws->b64($details['rsa']['n']),
            ];
        }

        throw AcmeException::unexpectedKey();
    }

    /**
     * base64url(sha256(canonical JWK)) — used in challenge key authorizations.
     */
    public function thumbprint(): string
    {
        $jwk = $this->jwk();
        ksort($jwk);

        $json = json_encode($jwk, JSON_UNESCAPED_SLASHES);

        return $this->jws->b64(hash('sha256', $json === false ? '' : $json, true));
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

    private function kidPath(): string
    {
        return $this->keyPath.'.kid';
    }

    private function pad(string $value): string
    {
        return str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}
