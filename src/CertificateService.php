<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RoundlyConsulting\Certificates\Actions\IssueCertificateAction;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CertificateBuilder;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

class CertificateService
{
    public function __construct(
        protected readonly CertificateManager $manager,
        protected readonly IssueCertificateAction $issueAction,
    ) {}

    /**
     * List every certificate managed by the active provider.
     *
     * @return Collection<int, RemoteCertificate>
     */
    public function get(): Collection
    {
        return $this->manager->provider()->get();
    }

    /**
     * Determine whether a certificate already exists for the given domain.
     */
    public function exists(string $domain): bool
    {
        return $this->manager->provider()->exists($this->certificateName($domain), $domain);
    }

    /**
     * Provision a certificate for the given domain.
     *
     * A cache lock guards against concurrent provisioning of the same domain.
     * Returns false when the lock could not be acquired. When the registry
     * table exists, the lifecycle is recorded and events fire via the action.
     */
    public function generate(string $domain): bool
    {
        if ($this->registryAvailable()) {
            $this->issueAction->execute(IssueCertificateData::make($domain));

            return true;
        }

        $name = $this->certificateName($domain);
        $lock = $this->generateLock($name);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->manager->provider()->generate($name, $domain);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * Issue a certificate from a fully-specified DTO, recording it to the registry.
     */
    public function issue(IssueCertificateData $data): Certificate
    {
        return $this->issueAction->execute($data);
    }

    /**
     * Issue a certificate only when no active one already exists for the domain.
     */
    public function issueIfMissing(string $domain): Certificate
    {
        $existing = $this->find($domain);

        if ($existing !== null && $existing->isActive()) {
            return $existing;
        }

        return $this->issue(IssueCertificateData::make($domain));
    }

    /**
     * Begin a fluent issuance for a domain.
     */
    public function for(string $domain): CertificateBuilder
    {
        return new CertificateBuilder($this, $domain);
    }

    /**
     * Look up the most recent registry record for a domain.
     */
    public function find(string $domain, ?string $driver = null): ?Certificate
    {
        if (! $this->registryAvailable()) {
            return null;
        }

        return Certificate::query()
            ->forDomain($domain)
            ->when($driver !== null, fn ($query) => $query->forDriver($driver))
            ->latest('id')
            ->first();
    }

    /**
     * Report the current status of a certificate by domain.
     */
    public function status(string $domain): ?CertificateStatus
    {
        return $this->find($domain)?->status;
    }

    /**
     * Resolve a provider driver by name (default when null).
     */
    public function driver(?string $name = null): CertificateProvider
    {
        return $this->manager->provider($name);
    }

    /**
     * Build the deterministic, DNS-safe secret name for a domain.
     */
    public function certificateName(string $domain): string
    {
        $prefix = (string) config('certificates.name_prefix', 'generated-tls-');

        return $prefix.Str::of($domain)->kebab()->replace(['.', ':'], '-')->value();
    }

    private function registryAvailable(): bool
    {
        return Schema::hasTable((string) config('certificates.table', 'certificates'));
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
