<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Events;

use RoundlyConsulting\Certificates\Models\Certificate;

final readonly class CertificateRevoked
{
    public function __construct(
        public Certificate $certificate,
        public ?string $reason = null,
    ) {}
}
