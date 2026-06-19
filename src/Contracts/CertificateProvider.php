<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Contracts;

use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\Certificate;

interface CertificateProvider
{
    /**
     * List every certificate the provider currently manages.
     *
     * @return Collection<int, Certificate>
     */
    public function get(): Collection;

    /**
     * Determine whether a certificate for the given name/domain exists.
     */
    public function exists(string $name, string $domain): bool;

    /**
     * Provision a certificate for the given name/domain.
     */
    public function generate(string $name, string $domain): void;
}
