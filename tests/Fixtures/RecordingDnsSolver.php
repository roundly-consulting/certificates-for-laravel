<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use RoundlyConsulting\Certificates\ChallengeSolvers\DnsChallengeSolver;

/**
 * A dns-01 solver that records the TXT records it would publish instead of calling a DNS API.
 */
final class RecordingDnsSolver extends DnsChallengeSolver
{
    /** @var list<string> */
    public array $published = [];

    /** @var list<string> */
    public array $removed = [];

    protected function publishRecord(string $name, string $value): void
    {
        $this->published[] = $name;
    }

    protected function removeRecord(string $name, string $value): void
    {
        $this->removed[] = $name;
    }
}
