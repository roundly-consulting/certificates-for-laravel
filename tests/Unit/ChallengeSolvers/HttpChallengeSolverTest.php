<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\ChallengeSolvers\DnsChallengeSolver;
use RoundlyConsulting\Certificates\ChallengeSolvers\HttpChallengeSolver;
use RoundlyConsulting\Certificates\DataTransferObjects\AcmeChallenge;

beforeEach(function (): void {
    Storage::fake('local');
});

function httpChallenge(): AcmeChallenge
{
    return new AcmeChallenge('http-01', 'app.com', 'tok', 'tok.thumb', 'authz', 'chall');
}

it('writes the key authorization file and reports its type', function (): void {
    $solver = new HttpChallengeSolver(disk: 'local', path: 'acme-challenge');

    expect($solver->type())->toBe('http-01');

    $solver->solve(httpChallenge());

    Storage::disk('local')->assertExists('acme-challenge/tok');
    expect(Storage::disk('local')->get('acme-challenge/tok'))->toBe('tok.thumb');
});

it('cleans up the challenge file', function (): void {
    $solver = new HttpChallengeSolver(disk: 'local', path: 'acme-challenge');
    $solver->solve(httpChallenge());

    $solver->cleanup(httpChallenge());

    Storage::disk('local')->assertMissing('acme-challenge/tok');
});

it('exposes a dns-01 extension point that publishes and removes records', function (): void {
    $solver = new class extends DnsChallengeSolver
    {
        /** @var list<array{name: string, value: string}> */
        public array $published = [];

        /** @var list<array{name: string, value: string}> */
        public array $removed = [];

        protected function publishRecord(string $name, string $value): void
        {
            $this->published[] = ['name' => $name, 'value' => $value];
        }

        protected function removeRecord(string $name, string $value): void
        {
            $this->removed[] = ['name' => $name, 'value' => $value];
        }
    };

    $challenge = new AcmeChallenge('dns-01', 'app.com', 'tok', 'tok.thumb', 'authz', 'chall');

    expect($solver->type())->toBe('dns-01');

    $solver->solve($challenge);
    $solver->cleanup($challenge);

    expect($solver->published[0]['name'])->toBe('_acme-challenge.app.com')
        ->and($solver->removed)->toHaveCount(1);
});
