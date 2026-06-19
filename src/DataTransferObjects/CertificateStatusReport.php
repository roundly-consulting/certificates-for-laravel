<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;

final readonly class CertificateStatusReport
{
    public function __construct(
        public CertificateStatus $status,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $issuer = null,
        public ?string $serial = null,
        public ?string $fingerprint = null,
    ) {}
}
