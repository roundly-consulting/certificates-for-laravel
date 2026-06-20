<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Contracts;

use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;

/**
 * Persists issued certificate material (leaf, private key, chain) so providers
 * can store and retrieve PEM identically regardless of the issuance backend.
 */
interface CertificateStore
{
    public function put(string $name, StoredCertificate $material): void;

    public function get(string $name): ?StoredCertificate;

    public function exists(string $name): bool;

    public function delete(string $name): void;

    /**
     * List the names of every stored certificate.
     *
     * @return list<string>
     */
    public function names(): array;
}
