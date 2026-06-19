<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

final readonly class IssueCertificateData
{
    /**
     * @param  array<string, string>  $meta
     */
    public function __construct(
        public string $domain,
        public ?string $issuer = null,
        public ?string $namespace = null,
        public ?string $driver = null,
        public ?int $validForDays = null,
        public array $meta = [],
        public ?Model $owner = null,
    ) {}

    public static function make(string $domain): self
    {
        return new self(domain: $domain);
    }
}
