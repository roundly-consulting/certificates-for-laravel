<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Contracts\ProvisionsMultipleDomains;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Events\CertificateRequested;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Exceptions\InvalidDomainException;
use RoundlyConsulting\Certificates\Exceptions\ProvisioningInProgressException;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Rules\ValidDomain;
use RoundlyConsulting\Certificates\Support\CachedStatusResolver;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use RoundlyConsulting\Certificates\Support\CertificateName;
use RoundlyConsulting\Certificates\Support\ProvisioningLock;
use Throwable;

/**
 * Issue (or re-issue) a certificate: validate every domain, take the certificate's own
 * provisioning lock (throwing when another process is already provisioning it), upsert the
 * registry row, provision it through the driver and record what the driver reports.
 *
 * Reach it through `Certificates::issue()` / `Certificates::for($domain)->issue()`.
 */
final readonly class IssueCertificateAction
{
    public function __construct(
        private CertificateProviderManager $manager,
        private CachedStatusResolver $statusCache,
    ) {}

    public function execute(IssueCertificateData $data, ?string $connection = null): Certificate
    {
        // Hostnames are case-insensitive: one host is one certificate, one row, one name.
        $domains = array_values(array_unique(array_map(
            static fn (string $domain): string => Str::lower(trim($domain)),
            $data->allDomains(),
        )));

        $rule = new ValidDomain;

        foreach ($domains as $candidate) {
            if (! $rule->passes($candidate)) {
                throw InvalidDomainException::forDomain($candidate);
            }
        }

        $data = new IssueCertificateData(
            domain: $domains[0],
            driver: $data->driver,
            validForDays: $data->validForDays,
            meta: $data->meta,
            owner: $data->owner,
            domains: count($domains) > 1 ? $domains : [],
        );

        $driver = $data->driver ?? $this->manager->getDefaultDriver();
        $provider = $this->manager->provider($driver);
        $name = CertificateName::for($data->domain);

        // Taken before the row is touched: a concurrent issuance of the same certificate
        // must neither reset the row it is about to record nor be told it succeeded.
        $lock = ProvisioningLock::for($name);

        if (! $lock->get()) {
            throw ProvisioningInProgressException::forDomain($data->domain);
        }

        try {
            return $this->provision($provider, $name, $data, $driver, $connection);
        } finally {
            $lock->release();
        }
    }

    private function provision(CertificateProvider $provider, string $name, IssueCertificateData $data, string $driver, ?string $connection): Certificate
    {
        $certificate = $this->upsertCertificate($name, $data, $driver, $connection);

        Event::dispatch(new CertificateRequested($certificate));

        try {
            if ($provider instanceof ProvisionsMultipleDomains && count($data->allDomains()) > 1) {
                $provider->generateMany($name, $data->allDomains());
            } else {
                $provider->generate($name, $data->domain);
            }

            // The status read is part of issuance: a backend that cannot report is a failed
            // issuance too, never a row stranded in Requested with no event.
            $report = $provider instanceof ReportsCertificateStatus ? $provider->status($name, $data->domain) : null;

            if ($report !== null && ! $report->status->isActive() && ! $this->inProgress($report->status)) {
                throw CertificateException::providerReported($driver, $data->domain, $report->status);
            }
        } catch (Throwable $e) {
            $certificate->markFailed($e->getMessage());
            Event::dispatch(new CertificateFailed($certificate, $e->getMessage()));

            throw $e;
        } finally {
            // Whatever happened, the backend changed: a cached report predates it.
            $this->statusCache->forget($driver, $name);
        }

        if ($report !== null && ! $report->status->isActive()) {
            // Still being issued (cert-manager issues asynchronously): the row stays
            // Requested until certificates:sync records the outcome.
            return $certificate;
        }

        $certificate->forceFill([
            'issuer' => $report->issuer ?? $certificate->issuer,
            'serial' => $report?->serial,
            'fingerprint' => $report?->fingerprint,
        ]);

        $certificate->markIssued($report->expiresAt ?? now()->addDays($data->validForDays ?? 90));

        Event::dispatch(new CertificateIssued($certificate));

        return $certificate;
    }

    private function upsertCertificate(string $name, IssueCertificateData $data, string $driver, ?string $connection): Certificate
    {
        // unique(driver, name) also covers pruned (soft-deleted) rows, so a re-issue must
        // find and revive the pruned row rather than insert a duplicate of it.
        $model = CertificateModel::class()::on($connection)->withTrashed()->firstOrNew([
            'driver' => $driver,
            'name' => $name,
        ]);

        if ($model->trashed()) {
            // A pruned row comes back as a fresh registration, not with its dead history.
            $model->forceFill([
                $model->getDeletedAtColumn() => null,
                'issuer' => null,
                'issued_at' => null,
                'expires_at' => null,
                'last_renewed_at' => null,
                'last_error' => null,
                'serial' => null,
                'fingerprint' => null,
                'certifiable_type' => null,
                'certifiable_id' => null,
            ]);
        }

        $allDomains = $data->allDomains();

        $model->forceFill([
            'domain' => $data->domain,
            'domains' => count($allDomains) > 1 ? $allDomains : null,
            'status' => CertificateStatus::Requested,
            'meta' => $data->meta === [] ? null : $data->meta,
        ]);

        if ($connection !== null) {
            $model->setConnection($connection);
        }

        if ($data->owner !== null) {
            $model->certifiable()->associate($data->owner);
        }

        $model->save();

        return $model;
    }

    private function inProgress(CertificateStatus $status): bool
    {
        return in_array($status, [CertificateStatus::Pending, CertificateStatus::Requested, CertificateStatus::Renewing], true);
    }
}
