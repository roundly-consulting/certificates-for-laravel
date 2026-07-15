<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Providers;

use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Contracts\CertificateStore;
use RoundlyConsulting\Certificates\Contracts\ProvisionsMultipleDomains;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Support\CertificateMapper;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

/**
 * Manages PEM certificate material on a Storage disk. This provider is not a CA:
 * it reads/parses material produced elsewhere (e.g. an external ACME run) and,
 * when self_signed is enabled, can generate self-signed certificates for local
 * development and as a realistic test backend.
 */
final class LocalFilesystemProvider implements CertificateProvider, ProvisionsMultipleDomains, ReportsCertificateStatus
{
    public function __construct(
        private readonly CertificateStore $store,
        private readonly CertificateMapper $parser,
        private readonly ?Csr $csr = null,
        private readonly int $selfSignedDays = 90,
    ) {}

    public function get(): Collection
    {
        return Collection::make($this->store->names())
            ->map(function (string $name): ?RemoteCertificate {
                $material = $this->store->get($name);

                if ($material === null) {
                    return null;
                }

                return new RemoteCertificate($name, $this->parser->parse($material->certificatePem)->commonName);
            })
            ->filter()
            ->values();
    }

    public function exists(string $name, string $domain): bool
    {
        return $this->store->exists($name);
    }

    public function generate(string $name, string $domain): void
    {
        $this->generateMany($name, [$domain]);
    }

    /**
     * @param  list<string>  $domains
     */
    public function generateMany(string $name, array $domains): void
    {
        if ($this->store->exists($name)) {
            return;
        }

        if ($this->csr === null) {
            // Not a CA and no self-signing configured: nothing to import.
            return;
        }

        [$cert, $key] = $this->csr->selfSigned($domains, $this->selfSignedDays);

        $this->store->put($name, new StoredCertificate(certificatePem: $cert, privateKeyPem: $key));
    }

    public function status(string $name, string $domain): CertificateStatusReport
    {
        $material = $this->store->get($name);

        if ($material === null) {
            return new CertificateStatusReport(status: CertificateStatus::Pending);
        }

        $parsed = $this->parser->parse($material->certificatePem);

        return new CertificateStatusReport(
            status: $parsed->isExpired() ? CertificateStatus::Expired : CertificateStatus::Issued,
            expiresAt: $parsed->notAfter,
            issuer: $parsed->issuer,
            serial: $parsed->serial,
            fingerprint: $parsed->fingerprint,
            domains: $parsed->subjectAltNames,
        );
    }
}
