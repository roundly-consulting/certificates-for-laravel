<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Contracts;

/**
 * Opt-in capability interface for providers that can provision a single
 * certificate covering several domains (a SAN / multi-domain certificate).
 */
interface ProvisionsMultipleDomains
{
    /**
     * Provision a single certificate covering several domains (SAN).
     *
     * @param  list<string>  $domains  Primary first, then additional SANs.
     */
    public function generateMany(string $name, array $domains): void;
}
