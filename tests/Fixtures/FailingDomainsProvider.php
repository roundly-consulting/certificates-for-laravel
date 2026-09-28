<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RuntimeException;

/**
 * Generates every domain except the ones it was told to fail on, which throw — a CA
 * rejecting one order while the rest go through.
 */
final class FailingDomainsProvider implements CertificateProvider
{
    /** @var list<string> */
    public array $generated = [];

    /**
     * @param  list<string>  $failing
     */
    public function __construct(private readonly array $failing) {}

    public function get(): Collection
    {
        return Collection::make();
    }

    public function exists(string $name, string $domain): bool
    {
        return in_array($domain, $this->generated, true);
    }

    public function generate(string $name, string $domain): void
    {
        if (in_array($domain, $this->failing, true)) {
            throw new RuntimeException("CA rejected the order for {$domain}.");
        }

        $this->generated[] = $domain;
    }
}
