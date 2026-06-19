<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Providers;

use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

/**
 * A no-op provider for local/dev where no certificate backend exists.
 */
final class NullProvider implements CertificateProvider
{
    /**
     * @return Collection<int, RemoteCertificate>
     */
    public function get(): Collection
    {
        /** @var Collection<int, RemoteCertificate> $collection */
        $collection = Collection::make();

        return $collection;
    }

    public function exists(string $name, string $domain): bool
    {
        return false;
    }

    public function generate(string $name, string $domain): void
    {
        // Intentionally does nothing.
    }
}
