<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Contracts;

use RoundlyConsulting\Certificates\DataTransferObjects\AcmeChallenge;

/**
 * Publishes (and cleans up) ACME challenges so the ACME server can validate
 * domain control. HTTP-01 ships with the package; DNS-01 is an extension point.
 */
interface AcmeChallengeSolver
{
    /** The ACME challenge type this solver satisfies, e.g. "http-01" or "dns-01". */
    public function type(): string;

    /** Publish the challenge so the ACME server can validate it. */
    public function solve(AcmeChallenge $challenge): void;

    /** Remove anything published by solve() once validation completes (or fails). */
    public function cleanup(AcmeChallenge $challenge): void;
}
