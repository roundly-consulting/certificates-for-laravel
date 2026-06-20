<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\ChallengeSolvers;

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Contracts\AcmeChallengeSolver;
use RoundlyConsulting\Certificates\DataTransferObjects\AcmeChallenge;

/**
 * Solves the ACME http-01 challenge by writing the key authorization to a
 * Storage disk. The host app must serve that file at
 * /.well-known/acme-challenge/{token}.
 */
final class HttpChallengeSolver implements AcmeChallengeSolver
{
    public function __construct(
        private readonly string $disk = 'local',
        private readonly string $path = 'acme-challenge',
    ) {}

    public function type(): string
    {
        return 'http-01';
    }

    public function solve(AcmeChallenge $challenge): void
    {
        Storage::disk($this->disk)->put($this->file($challenge), $challenge->keyAuthorization);
    }

    public function cleanup(AcmeChallenge $challenge): void
    {
        Storage::disk($this->disk)->delete($this->file($challenge));
    }

    private function file(AcmeChallenge $challenge): string
    {
        return trim($this->path, '/').'/'.$challenge->token;
    }
}
