<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

use RoundlyConsulting\Certificates\Models\Certificate;
use Throwable;

/**
 * One certificate a renew-due run could not renew (or could not queue), and why.
 */
final readonly class RenewalFailure
{
    public function __construct(
        public Certificate $certificate,
        public Throwable $exception,
    ) {}

    public function reason(): string
    {
        return $this->exception->getMessage();
    }
}
