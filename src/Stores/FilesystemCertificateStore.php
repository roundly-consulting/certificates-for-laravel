<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Stores;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Contracts\CertificateStore;
use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;

/**
 * Stores certificate material as PEM files on a Laravel Storage disk. Each
 * certificate is a directory containing the leaf, private key, and chain.
 */
final class FilesystemCertificateStore implements CertificateStore
{
    public function __construct(
        private readonly string $disk = 'local',
        private readonly string $path = 'certificates',
    ) {}

    public function put(string $name, StoredCertificate $material): void
    {
        $disk = $this->disk();
        $base = $this->base($name);

        $disk->put($base.'/certificate.pem', $material->certificatePem);
        $disk->put($base.'/private.key', $material->privateKeyPem);

        if ($material->chainPem !== null && trim($material->chainPem) !== '') {
            $disk->put($base.'/chain.pem', $material->chainPem);
        } else {
            $disk->delete($base.'/chain.pem');
        }
    }

    public function get(string $name): ?StoredCertificate
    {
        $disk = $this->disk();
        $base = $this->base($name);

        if (! $disk->exists($base.'/certificate.pem') || ! $disk->exists($base.'/private.key')) {
            return null;
        }

        $chain = $disk->exists($base.'/chain.pem')
            ? (string) $disk->get($base.'/chain.pem')
            : null;

        return new StoredCertificate(
            certificatePem: (string) $disk->get($base.'/certificate.pem'),
            privateKeyPem: (string) $disk->get($base.'/private.key'),
            chainPem: $chain,
        );
    }

    public function exists(string $name): bool
    {
        return $this->disk()->exists($this->base($name).'/certificate.pem');
    }

    public function delete(string $name): void
    {
        $this->disk()->deleteDirectory($this->base($name));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $directories = $this->disk()->directories($this->path);

        $names = [];

        foreach ($directories as $directory) {
            $names[] = basename($directory);
        }

        return $names;
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    private function base(string $name): string
    {
        return trim($this->path, '/').'/'.$name;
    }
}
