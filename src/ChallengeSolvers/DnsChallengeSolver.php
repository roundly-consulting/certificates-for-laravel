<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\ChallengeSolvers;

use RoundlyConsulting\Certificates\Contracts\AcmeChallengeSolver;
use RoundlyConsulting\Certificates\DataTransferObjects\AcmeChallenge;

/**
 * Base class for ACME dns-01 solvers. The package ships no concrete DNS
 * provider (that would require a third-party SDK); host apps extend this and
 * implement publishRecord()/removeRecord() against their DNS provider's API.
 *
 * Register the concrete solver via the certificates.drivers.acme.solver config
 * key (its fully-qualified class name).
 */
abstract class DnsChallengeSolver implements AcmeChallengeSolver
{
    public function type(): string
    {
        return 'dns-01';
    }

    public function solve(AcmeChallenge $challenge): void
    {
        $this->publishRecord($challenge->dnsRecordName(), $challenge->dnsRecordValue());
    }

    public function cleanup(AcmeChallenge $challenge): void
    {
        $this->removeRecord($challenge->dnsRecordName(), $challenge->dnsRecordValue());
    }

    /**
     * Publish the TXT record so the ACME server can validate domain control.
     */
    abstract protected function publishRecord(string $name, string $value): void;

    /**
     * Remove the TXT record once validation completes (or fails).
     */
    abstract protected function removeRecord(string $name, string $value): void;
}
