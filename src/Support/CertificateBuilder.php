<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * `Certificates::for($domain)` — the handle for one domain (or a SAN set led by its
 * first domain). Configure and issue a certificate fluently, read its state, or run a
 * lifecycle verb on its registry row. Every call goes through CertificatesManager, so
 * `Certificates::fake()` records it; `using($driver)` also scopes the row lookup, so a
 * handle never acts on another driver's row for the same domain.
 */
final class CertificateBuilder
{
    use Macroable;

    /** @var list<string> */
    private array $domains;

    private string $domain;

    private ?string $driver = null;

    private ?int $validForDays = null;

    /** @var array<string, string> */
    private array $meta = [];

    private ?Model $owner = null;

    private bool $fresh = false;

    /**
     * @internal build it with `Certificates::for($domain)`
     *
     * @param  string|list<string>  $domain
     */
    public function __construct(
        private readonly CertificatesManager $manager,
        string|array $domain,
    ) {
        $this->domains = is_array($domain) ? $domain : [$domain];
        $this->domain = $this->domains[0];
    }

    public function alsoFor(string ...$domains): self
    {
        foreach ($domains as $domain) {
            $this->domains[] = $domain;
        }

        return $this;
    }

    public function using(string $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    public function validForDays(int $days): self
    {
        $this->validForDays = $days;

        return $this;
    }

    /**
     * @param  array<string, string>  $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    public function owner(Model $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function fresh(bool $fresh = true): self
    {
        $this->fresh = $fresh;

        return $this;
    }

    public function issue(): Certificate
    {
        return $this->manager->issue($this->toData());
    }

    public function issueIfMissing(): Certificate
    {
        $existing = $this->find();

        if ($existing !== null && $existing->isActive()) {
            return $existing;
        }

        return $this->issue();
    }

    public function exists(): bool
    {
        return $this->manager->exists($this->domain);
    }

    public function status(): ?CertificateStatus
    {
        return $this->manager->status($this->domain);
    }

    public function statusReport(): ?CertificateStatusReport
    {
        return $this->manager->statusReport($this->domain, $this->driver, $this->fresh);
    }

    public function find(): ?Certificate
    {
        return $this->manager->find($this->domain, $this->driver);
    }

    /**
     * Renew this domain's registry certificate now.
     *
     * @throws CertificateException when the domain (on the chosen driver) has no registry row
     */
    public function renew(): Certificate
    {
        return $this->manager->renew($this->registered());
    }

    /**
     * Queue a renewal of this domain's registry certificate.
     *
     * @throws CertificateException when the domain (on the chosen driver) has no registry row
     */
    public function renewLater(): Certificate
    {
        return $this->manager->renewLater($this->registered());
    }

    /**
     * Record a revocation of this domain's registry certificate.
     *
     * @throws CertificateException when the domain (on the chosen driver) has no registry row
     */
    public function revoke(?string $reason = null): Certificate
    {
        return $this->manager->revoke($this->registered(), $reason);
    }

    /**
     * Mark this domain's registry certificate expired.
     *
     * @throws CertificateException when the domain (on the chosen driver) has no registry row
     */
    public function expire(): Certificate
    {
        return $this->manager->expire($this->registered());
    }

    private function registered(): Certificate
    {
        return $this->find() ?? throw CertificateException::notFound($this->domain);
    }

    private function toData(): IssueCertificateData
    {
        return new IssueCertificateData(
            domain: $this->domain,
            driver: $this->driver,
            validForDays: $this->validForDays,
            meta: $this->meta,
            owner: $this->owner,
            domains: count($this->domains) > 1 ? $this->domains : [],
        );
    }
}
