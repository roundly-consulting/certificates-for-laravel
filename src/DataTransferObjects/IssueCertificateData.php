<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

final readonly class IssueCertificateData
{
    /**
     * The issuer and namespace are not per-certificate options: they belong to the driver's
     * configuration (e.g. `drivers.kubernetes.issuer` / `.namespace`) — register another
     * driver with `Certificates::extend()` for a second one. The registry row's `issuer`
     * records what the provider reports.
     *
     * @param  array<string, string>  $meta
     * @param  list<string>  $domains  All domains incl. primary; empty falls back to [domain].
     * @param  int|null  $validForDays  the recorded expiry when the driver cannot report one
     */
    public function __construct(
        public string $domain,
        public ?string $driver = null,
        public ?int $validForDays = null,
        public array $meta = [],
        public ?Model $owner = null,
        public array $domains = [],
    ) {}

    public static function make(string $domain): self
    {
        return new self(domain: $domain);
    }

    /**
     * @param  list<string>  $domains  Primary first, then additional SANs.
     */
    public static function makeForDomains(array $domains): self
    {
        return new self(domain: $domains[0], domains: $domains);
    }

    /**
     * Every domain the certificate should cover, including the primary.
     *
     * @return list<string>
     */
    public function allDomains(): array
    {
        return $this->domains === [] ? [$this->domain] : $this->domains;
    }
}
