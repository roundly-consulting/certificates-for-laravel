<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

final readonly class Certificate
{
    public function __construct(
        public string $name,
        public string $domain,
    ) {}
}
