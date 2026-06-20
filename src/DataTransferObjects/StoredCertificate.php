<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

final readonly class StoredCertificate
{
    public function __construct(
        public string $certificatePem,
        public string $privateKeyPem,
        public ?string $chainPem = null,
    ) {}

    /**
     * The leaf certificate followed by any intermediate chain.
     */
    public function fullChainPem(): string
    {
        if ($this->chainPem === null || trim($this->chainPem) === '') {
            return $this->certificatePem;
        }

        return rtrim($this->certificatePem)."\n".ltrim($this->chainPem);
    }
}
