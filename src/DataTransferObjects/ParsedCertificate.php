<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

use Carbon\CarbonImmutable;

final readonly class ParsedCertificate
{
    /**
     * @param  list<string>  $subjectAltNames
     */
    public function __construct(
        public CarbonImmutable $notBefore,
        public CarbonImmutable $notAfter,
        public string $commonName,
        public array $subjectAltNames,
        public ?string $issuer = null,
        public ?string $serial = null,
        public ?string $fingerprint = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->notAfter->isPast();
    }
}
