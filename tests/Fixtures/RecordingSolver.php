<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use RoundlyConsulting\Certificates\Contracts\AcmeChallengeSolver;
use RoundlyConsulting\Certificates\DataTransferObjects\AcmeChallenge;

/**
 * A challenge solver of any type that only records what it was asked to solve.
 */
final class RecordingSolver implements AcmeChallengeSolver
{
    /** @var list<AcmeChallenge> */
    public array $solved = [];

    public function __construct(private readonly string $type = 'http-01') {}

    public function type(): string
    {
        return $this->type;
    }

    public function solve(AcmeChallenge $challenge): void
    {
        $this->solved[] = $challenge;
    }

    public function cleanup(AcmeChallenge $challenge): void {}
}
