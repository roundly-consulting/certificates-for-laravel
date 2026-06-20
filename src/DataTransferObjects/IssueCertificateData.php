<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

final readonly class IssueCertificateData
{
    /**
     * @param  array<string, string>  $meta
     * @param  list<string>  $domains  All domains incl. primary; empty falls back to [domain].
     */
    public function __construct(
        public string $domain,
        public ?string $issuer = null,
        public ?string $namespace = null,
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
