<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;

final readonly class CertificateStatusReport
{
    /**
     * @param  list<string>  $domains  SAN domains covered by the certificate.
     */
    public function __construct(
        public CertificateStatus $status,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $issuer = null,
        public ?string $serial = null,
        public ?string $fingerprint = null,
        public array $domains = [],
    ) {}
}
