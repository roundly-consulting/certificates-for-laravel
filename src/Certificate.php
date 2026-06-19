<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

/**
 * @deprecated Use {@see RemoteCertificate} instead. Kept as a backward-compatible
 *             alias for the value object returned by providers; removed in the next major.
 */
final readonly class Certificate
{
    public function __construct(
        public string $name,
        public string $domain,
    ) {}

    public function toRemoteCertificate(): RemoteCertificate
    {
        return new RemoteCertificate(name: $this->name, domain: $this->domain);
    }
}
