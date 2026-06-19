<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;

final class CertificateService
{
    public function __construct(
        private readonly CertificateProvider $provider,
    ) {}

    /**
     * List every certificate managed by the active provider.
     *
     * @return Collection<int, Certificate>
     */
    public function get(): Collection
    {
        return $this->provider->get();
    }

    /**
     * Determine whether a certificate already exists for the given domain.
     */
    public function exists(string $domain): bool
    {
        return $this->provider->exists($this->certificateName($domain), $domain);
    }

    /**
     * Provision a certificate for the given domain.
     *
     * A cache lock guards against concurrent provisioning of the same domain.
     * Returns false when the lock could not be acquired.
     */
    public function generate(string $domain): bool
    {
        $name = $this->certificateName($domain);

        $lock = $this->generateLock($name);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->provider->generate($name, $domain);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * Build the deterministic, DNS-safe secret name for a domain.
     */
    public function certificateName(string $domain): string
    {
        $prefix = (string) config('certificates.name_prefix', 'generated-tls-');

        return $prefix.Str::of($domain)->kebab()->replace(['.', ':'], '-')->value();
    }

    private function generateLock(string $owner): Lock
    {
        return Cache::lock(
            name: (string) config('certificates.lock.name', 'certificates:generate'),
            seconds: (int) config('certificates.lock.locked_for_seconds', 5),
            owner: $owner,
        );
    }
}
