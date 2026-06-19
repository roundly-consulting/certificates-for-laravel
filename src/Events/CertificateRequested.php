<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Events;

use RoundlyConsulting\Certificates\Models\Certificate;

final readonly class CertificateRequested
{
    public function __construct(
        public Certificate $certificate,
    ) {}
}
