<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Certificates\CertificateService;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;

final class CertificateBuilder
{
    private ?string $driver = null;

    private ?string $issuer = null;

    private ?string $namespace = null;

    private ?int $validForDays = null;

    /** @var array<string, string> */
    private array $meta = [];

    private ?Model $owner = null;

    public function __construct(
        private readonly CertificateService $service,
        private readonly string $domain,
    ) {}

    public function using(string $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    public function issuer(string $issuer): self
    {
        $this->issuer = $issuer;

        return $this;
    }

    public function namespace(string $namespace): self
    {
        $this->namespace = $namespace;

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

    public function issue(): Certificate
    {
        return $this->service->issue($this->toData());
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
        return $this->service->exists($this->domain);
    }

    public function status(): ?CertificateStatus
    {
        return $this->service->status($this->domain);
    }

    public function find(): ?Certificate
    {
        return $this->service->find($this->domain, $this->driver);
    }

    private function toData(): IssueCertificateData
    {
        return new IssueCertificateData(
            domain: $this->domain,
            issuer: $this->issuer,
            namespace: $this->namespace,
            driver: $this->driver,
            validForDays: $this->validForDays,
            meta: $this->meta,
            owner: $this->owner,
        );
    }
}
