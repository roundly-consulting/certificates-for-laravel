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
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Support\CertificateMapper;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

/**
 * Manages PEM certificate material on a Storage disk. This provider is not a CA:
 * it reads/parses material produced elsewhere (e.g. an external ACME run) and,
 * when self_signed is enabled, can generate self-signed certificates for local
 * development and as a realistic test backend.
 *
 * Without self-signing, provisioning a name with no stored material throws
 * rather than pretending; with it, every provisioning call mints fresh material
 * (never over material a real CA issued).
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

                $parsed = $this->parser->parse($material->certificatePem);

                // A certificate may carry no common name (its SAN extension names the hosts).
                return new RemoteCertificate($name, $parsed->commonName !== '' ? $parsed->commonName : ($parsed->subjectAltNames[0] ?? ''));
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
        $existing = $this->store->get($name);

        if ($this->csr === null) {
            // Not a CA: imported material is registered as-is, and there is nothing to
            // provision without it — reporting success here would record a certificate
            // that does not exist.
            if ($existing === null) {
                throw CertificateException::noMaterial($name);
            }

            return;
        }

        // Material a real CA issued is never overwritten with a self-signed one.
        if ($existing !== null && ! $this->parser->parse($existing->certificatePem)->selfSigned) {
            return;
        }

        // Self-signing mints fresh material every time, so a renewal is a real renewal
        // (a new certificate and a later expiry), never the old material relabelled.
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
