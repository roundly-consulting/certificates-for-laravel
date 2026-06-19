<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\ValueObjects;

final readonly class RemoteCertificate
{
    public function __construct(
        public string $name,
        public string $domain,
    ) {}
}
