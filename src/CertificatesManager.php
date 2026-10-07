<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Traits\Macroable;
use RoundlyConsulting\Alerts\HealthManager;
use RoundlyConsulting\Alerts\Support\PendingScheduledCheck;
use RoundlyConsulting\Certificates\Actions\ExpireCertificateAction;
use RoundlyConsulting\Certificates\Actions\IssueCertificateAction;
use RoundlyConsulting\Certificates\Actions\PruneCertificatesAction;
use RoundlyConsulting\Certificates\Actions\RenewCertificateAction;
use RoundlyConsulting\Certificates\Actions\RenewDueCertificatesAction;
use RoundlyConsulting\Certificates\Actions\RevokeCertificateAction;
use RoundlyConsulting\Certificates\Actions\SyncCertificatesAction;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\Alerts\ExpiryNotifiableResolver;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\DataTransferObjects\RenewalReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Exceptions\ProvisioningInProgressException;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CachedStatusResolver;
use RoundlyConsulting\Certificates\Support\CertificateBuilder;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use RoundlyConsulting\Certificates\Support\CertificateName;
use RoundlyConsulting\Certificates\Support\ProvisioningLock;
use RoundlyConsulting\Certificates\Support\Settings;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

/**
 * The `Certificates` facade root — inject it to use the same API without the facade.
 *
 * Every state-changing method resolves its action from the container, so a host
 * override of an action and `Certificates::fake()` both see every call. Deliberately not
 * final: the fake extends it, so constructor-injected code keeps working under the fake.
 */
class CertificatesManager
{
    use Macroable;

    protected ?string $connection = null;

    private ?CachedStatusResolver $statusResolver;

    public function __construct(
        protected readonly Container $container,
        protected readonly CertificateProviderManager $providers,
        ?CachedStatusResolver $statusResolver = null,
        ?string $connection = null,
    ) {
        $this->statusResolver = $statusResolver;

        $this->connection = $connection ?? Settings::optionalString('certificates.connection', config('certificates.connection'));
    }

    /**
     * Return a connection-bound clone targeting a chosen DB connection.
     */
    public function on(?string $connection): static
    {
        $clone = clone $this;
        $clone->connection = $connection;

        return $clone;
    }

    /**
     * List every certificate managed by the active provider.
     *
     * @return Collection<int, RemoteCertificate>
     */
    public function get(): Collection
    {
        return $this->providers->provider()->get();
    }

    /**
     * Determine whether a certificate already exists for the given domain.
     */
    public function exists(string $domain): bool
    {
        return $this->providers->provider()->exists($this->certificateName($domain), $domain);
    }

    /**
     * Provision a certificate for the given domain.
     *
     * A per-certificate cache lock guards against concurrent provisioning of the same
     * domain: returns false, without provisioning, while another process holds it. When
     * the registry table exists, the lifecycle is recorded and events fire via the action.
     */
    public function generate(string $domain): bool
    {
        if ($this->registryAvailable()) {
            try {
                $this->issue(IssueCertificateData::make($domain));
            } catch (ProvisioningInProgressException) {
                return false;
            }

            return true;
        }

        $lock = ProvisioningLock::for($this->certificateName($domain));

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->providers->provider()->generate($this->certificateName($domain), $domain);
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
        return $this->container->make(IssueCertificateAction::class)->execute($data, $this->connection);
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
     * A handle for one domain (or set of SAN domains): fluent issuance plus the
     * lifecycle verbs (renew, renewLater, revoke, expire) and reads for that domain.
     *
     * @param  string|list<string>  $domain
     */
    public function for(string|array $domain): CertificateBuilder
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

        return CertificateModel::class()::on($this->connection)
            ->forDomain($domain)
            ->when($driver !== null, fn ($query) => $query->forDriver($driver))
            ->latest('id')
            ->first();
    }

    /**
     * Report the current status of a certificate by domain (registry enum).
     */
    public function status(string $domain): ?CertificateStatus
    {
        return $this->find($domain)?->status;
    }

    /**
     * Report the live, cache-aware status of a certificate by domain.
     */
    public function statusReport(string $domain, ?string $driver = null, bool $fresh = false): ?CertificateStatusReport
    {
        $driver ??= $this->providers->getDefaultDriver();

        return $this->resolver()->resolve($driver, $this->certificateName($domain), $domain, $fresh);
    }

    /**
     * Registry certificates expiring within `$days` (default:
     * `certificates.renewal.threshold_days`), soonest first — Issued, Renewed, and Failed
     * ones whose live certificate still runs out (so a failed renewal is retried).
     *
     * @return EloquentCollection<int, Certificate>
     */
    public function expiring(?int $days = null, ?string $driver = null): EloquentCollection
    {
        return CertificateModel::class()::on($this->connection)
            ->expiring($days)
            ->when($driver !== null, fn ($query) => $query->forDriver($driver))
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Renew a certificate now, through its own driver. A domain resolves to its most
     * recent registry row.
     *
     * @throws CertificateException when the domain has no registry row or its status cannot renew
     */
    public function renew(Certificate|string $certificate): Certificate
    {
        return $this->container->make(RenewCertificateAction::class)->execute($this->resolve($certificate));
    }

    /**
     * Queue a renewal (RenewCertificateJob on `certificates.renewal.queue`). The status is
     * checked now, not in the worker: a certificate that cannot renew is refused here.
     *
     * @throws CertificateException when the domain has no registry row or its status cannot renew
     */
    public function renewLater(Certificate|string $certificate): Certificate
    {
        $certificate = $this->resolve($certificate);

        if (! $certificate->canRenew()) {
            throw CertificateException::illegalTransition($certificate->status, CertificateStatus::Renewing);
        }

        RenewCertificateJob::dispatch($certificate->id, $certificate->getConnectionName());

        return $certificate;
    }

    /**
     * Renew — inline, or queued with `$queue` — every certificate expiring within the
     * threshold, dispatching CertificateExpiring for each. Each is attempted on its own:
     * a failure is reported in the result and the run continues.
     */
    public function renewDue(?int $thresholdDays = null, bool $queue = false): RenewalReport
    {
        return $this->container->make(RenewDueCertificatesAction::class)->execute($thresholdDays, $queue, $this->connection);
    }

    /**
     * Record a revocation in the registry and dispatch CertificateRevoked.
     *
     * @throws CertificateException when the domain has no registry row or its status cannot be revoked
     */
    public function revoke(Certificate|string $certificate, ?string $reason = null): Certificate
    {
        return $this->container->make(RevokeCertificateAction::class)->execute($this->resolve($certificate), $reason);
    }

    /**
     * Mark a certificate expired and dispatch CertificateExpired.
     *
     * @throws CertificateException when the domain has no registry row or its status cannot expire
     */
    public function expire(Certificate|string $certificate): Certificate
    {
        return $this->container->make(ExpireCertificateAction::class)->execute($this->resolve($certificate));
    }

    /**
     * Pull a driver's live certificates (default driver when null) into the registry.
     *
     * @return int how many remote certificates were written
     */
    public function sync(?string $driver = null): int
    {
        return $this->container->make(SyncCertificatesAction::class)->execute($driver, $this->connection);
    }

    /**
     * Soft-delete registry rows untouched for `$days` days — expired, failed and revoked
     * ones, or exactly `$status` when given.
     *
     * @return int how many rows were pruned
     */
    public function prune(int $days = 30, CertificateStatus|string|null $status = null): int
    {
        return $this->container->make(PruneCertificatesAction::class)->execute($days, $status, $this->connection);
    }

    /**
     * Schedule alerts-for-laravel expiry monitoring for a certificate.
     *
     * Returns the alerts PendingScheduledCheck builder so the host chains
     * frequency/failAfter/notifyVia/escalate before ->save(). The notifiable is
     * resolved with the precedence: explicit arg -> config FQCN -> certifiable owner.
     *
     * @throws CertificateException when no notifiable can be resolved
     */
    public function monitorExpiry(Certificate $certificate, ?Model $notifiable = null): PendingScheduledCheck
    {
        $target = $this->container->make(ExpiryNotifiableResolver::class)->resolve($certificate, $notifiable);

        if ($target === null) {
            throw CertificateException::noAlertNotifiable($certificate->domain);
        }

        return $this->container->make(HealthManager::class)
            ->for($target)
            ->monitor(CertificateExpiryCheck::class)
            ->tags(['certificates'])
            ->meta(['certificate_id' => $certificate->id]);
    }

    /**
     * Resolve a provider driver by name (default when null).
     */
    public function driver(?string $name = null): CertificateProvider
    {
        return $this->providers->provider($name);
    }

    /**
     * Register a custom provider driver.
     *
     * @param  Closure(Container): CertificateProvider  $callback
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->providers->extend($driver, $callback);

        return $this;
    }

    /**
     * Build the deterministic secret name for a domain: lowercase, DNS-1123 safe
     * (a wildcard `*.` becomes `wildcard-`) and at most 253 characters.
     */
    public function certificateName(string $domain): string
    {
        return CertificateName::for($domain);
    }

    /**
     * A registry row as given, or a domain's most recent row.
     *
     * @throws CertificateException when the domain has no registry row
     */
    protected function resolve(Certificate|string $certificate): Certificate
    {
        if ($certificate instanceof Certificate) {
            return $certificate;
        }

        return $this->find($certificate) ?? throw CertificateException::notFound($certificate);
    }

    protected function registryAvailable(): bool
    {
        return Schema::connection($this->connection)->hasTable(Settings::string('certificates.table', config('certificates.table'), 'certificates'));
    }

    private function resolver(): CachedStatusResolver
    {
        return $this->statusResolver ??= $this->container->make(CachedStatusResolver::class);
    }
}
