<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;

/**
 * Regression (chat review C-20): the store wrote the certificate, then the key, straight into
 * place. A crash (or a failed write) in between left a new certificate next to the old key —
 * a mismatched pair that status() still reported as Issued.
 */
beforeEach(function (): void {
    $this->disk = Storage::fake('local');
    $this->store = new FilesystemCertificateStore(disk: 'local', path: 'certificates');

    $old = selfSignedCertificate(['old.example.com']);
    $this->oldCertificate = $old->leaf()->pem();
    $this->store->put('generated-tls-app-com', new StoredCertificate($this->oldCertificate, $old->leafKey->privatePem()));

    $new = selfSignedCertificate(['app.example.com']);
    $this->replacement = new StoredCertificate($new->leaf()->pem(), $new->leafKey->privatePem(), "chain\n");
});

/**
 * Put a double of the `local` disk in place whose writes of `$file` fail — by throwing, or by
 * returning false as a disk without `throw` does.
 */
function failWritesOf(FilesystemAdapter $disk, string $file, bool $throw): void
{
    Storage::set('local', new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig(), $file, $throw) extends FilesystemAdapter
    {
        public function __construct($driver, $adapter, array $config, private readonly string $file, private readonly bool $throw)
        {
            parent::__construct($driver, $adapter, $config);
        }

        public function put($path, $contents, $options = [])
        {
            if (str_contains($path, $this->file)) {
                return $this->throw ? throw new RuntimeException('disk full') : false;
            }

            return parent::put($path, $contents, $options);
        }
    });
}

function assertOldPairIntact(): void
{
    $stored = test()->store->get('generated-tls-app-com');

    expect($stored?->certificatePem)->toBe(test()->oldCertificate)
        ->and(openssl_x509_check_private_key((string) $stored?->certificatePem, (string) $stored?->privateKeyPem))->toBeTrue()
        ->and(test()->disk->allFiles('certificates'))->toBe([
            'certificates/generated-tls-app-com/certificate.pem',
            'certificates/generated-tls-app-com/private.key',
        ]);
}

it('keeps the previous pair when the key write throws', function (): void {
    failWritesOf($this->disk, 'private.key', throw: true);

    expect(fn () => $this->store->put('generated-tls-app-com', $this->replacement))->toThrow(RuntimeException::class, 'disk full');

    assertOldPairIntact();
});

it('keeps the previous pair and says so when a write fails quietly', function (string $file): void {
    failWritesOf($this->disk, $file, throw: false);

    expect(fn () => $this->store->put('generated-tls-app-com', $this->replacement))->toThrow(CertificateException::class, 'generated-tls-app-com');

    assertOldPairIntact();
})->with(['certificate.pem', 'private.key', 'chain.pem']);

it('replaces the whole pair when every write succeeds', function (): void {
    $this->store->put('generated-tls-app-com', $this->replacement);

    $stored = $this->store->get('generated-tls-app-com');

    expect($stored?->certificatePem)->toBe($this->replacement->certificatePem)
        ->and($stored?->privateKeyPem)->toBe($this->replacement->privateKeyPem)
        ->and($stored?->chainPem)->toBe("chain\n")
        ->and($this->disk->allFiles('certificates'))->toHaveCount(3);
});
