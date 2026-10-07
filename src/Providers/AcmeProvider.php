<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Providers;

use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\Acme\AcmeClient;
use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\Contracts\AcmeChallengeSolver;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Contracts\CertificateStore;
use RoundlyConsulting\Certificates\Contracts\ProvisionsMultipleDomains;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\AcmeChallenge;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Support\CertificateMapper;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;
use Throwable;

/**
 * Provisions certificates from a real ACME v2 CA (e.g. Let's Encrypt) in pure
 * PHP, storing the issued material on a CertificateStore.
 */
final class AcmeProvider implements CertificateProvider, ProvisionsMultipleDomains, ReportsCertificateStatus
{
    public function __construct(
        private readonly AcmeClient $client,
        private readonly Csr $csr,
        private readonly CertificateStore $store,
        private readonly AcmeChallengeSolver $solver,
        private readonly CertificateMapper $parser,
        private readonly int $pollAttempts = 30,
        private readonly int $pollSeconds = 2,
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
        $material = $this->store->get($name);

        if ($material === null) {
            return false;
        }

        return ! $this->parser->parse($material->certificatePem)->isExpired();
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
        $this->client->registerAccount();

        $order = $this->client->newOrder($domains);

        foreach ($order->authorizationUrls as $authorizationUrl) {
            // The solver decides which challenge is answered: a DNS solver gets dns-01. An
            // authorization the CA already holds as valid has nothing left to solve.
            $challenge = $this->client->pendingChallenge($authorizationUrl, $this->solver->type());

            if ($challenge === null) {
                continue;
            }

            try {
                $this->solver->solve($challenge);
                $this->client->respondToChallenge($challenge);
                $this->client->pollAuthorization(
                    $authorizationUrl,
                    $challenge->domain,
                    $this->pollAttempts,
                    $this->pollSeconds,
                );
            } finally {
                $this->cleanup($challenge);
            }
        }

        $key = $this->csr->newKey();
        $csrDer = $this->csr->forDomains($domains, $key);

        $finalized = $this->client->finalize($order->finalizeUrl, $csrDer, $order->orderUrl);
        $completed = $this->client->pollOrder($finalized->orderUrl, $this->pollAttempts, $this->pollSeconds);

        $pem = $this->client->downloadCertificate((string) $completed->certificateUrl);

        $this->store->put($name, new StoredCertificate(
            certificatePem: $this->leaf($pem),
            privateKeyPem: $this->csr->exportKey($key),
            chainPem: $this->chain($pem),
        ));
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

    private function cleanup(AcmeChallenge $challenge): void
    {
        try {
            $this->solver->cleanup($challenge);
        } catch (Throwable) {
            // Cleanup is best-effort; never mask the original outcome.
        }
    }

    private function leaf(string $pem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $matches) === 1) {
            return $matches[0]."\n";
        }

        return $pem;
    }

    private function chain(string $pem): ?string
    {
        if (preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $matches) > 1) {
            $blocks = $matches[0];
            array_shift($blocks);

            return implode("\n", $blocks)."\n";
        }

        return null;
    }
}
