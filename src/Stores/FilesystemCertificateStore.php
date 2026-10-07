<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Stores;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RoundlyConsulting\Certificates\Contracts\CertificateStore;
use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use Throwable;

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

    /**
     * Every file is written to a temporary name first and moved into place only once all of
     * them are written: a crash or a failed write while writing never leaves a new certificate
     * next to the old key (a pair status() would still report as issued).
     *
     * The moves are still one rename per file, not one atomic swap: a crash (or a failed move)
     * exactly between two of them can leave a mixed set — a new certificate.pem next to the
     * old private.key, or a new pair next to the old chain.pem — until the certificate is next
     * written. The window is two renames wide.
     */
    public function put(string $name, StoredCertificate $material): void
    {
        $disk = $this->disk();
        $base = $this->base($name);
        $suffix = '.'.Str::random(12).'.tmp';

        $files = ['certificate.pem' => $material->certificatePem, 'private.key' => $material->privateKeyPem];
        $chain = $material->chainPem !== null && trim($material->chainPem) !== '';

        if ($chain) {
            $files['chain.pem'] = (string) $material->chainPem;
        }

        $written = [];

        try {
            foreach ($files as $file => $contents) {
                if ($disk->put($base.'/'.$file.$suffix, $contents) === false) {
                    throw CertificateException::storeFailed($name);
                }

                $written[] = $base.'/'.$file.$suffix;
            }
        } catch (Throwable $e) {
            $disk->delete($written);

            throw $e;
        }

        foreach (array_keys($files) as $file) {
            if ($disk->move($base.'/'.$file.$suffix, $base.'/'.$file) === false) {
                throw CertificateException::storeFailed($name);
            }
        }

        if (! $chain) {
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
