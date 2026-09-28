<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\ProvisionsMultipleDomains;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Events\CertificateRequested;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Exceptions\InvalidDomainException;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Rules\ValidDomain;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use Throwable;

/**
 * Issue (or re-issue) a certificate: validate every domain, upsert the registry row,
 * provision it through the driver under a per-name cache lock, and record the outcome.
 *
 * Reach it through `Certificates::issue()` / `Certificates::for($domain)->issue()`.
 */
final readonly class IssueCertificateAction
{
    public function __construct(
        private CertificateProviderManager $manager,
    ) {}

    public function execute(IssueCertificateData $data, ?string $connection = null): Certificate
    {
        $rule = new ValidDomain;

        foreach ($data->allDomains() as $candidate) {
            if (! $rule->passes($candidate)) {
                throw InvalidDomainException::forDomain($candidate);
            }
        }

        $driver = $data->driver ?? $this->manager->getDefaultDriver();
        $provider = $this->manager->provider($driver);
        $name = $this->certificateName($data->domain);

        $certificate = $this->upsertCertificate($name, $data, $driver, $connection);

        Event::dispatch(new CertificateRequested($certificate));

        $lock = $this->generateLock($name);

        if (! $lock->get()) {
            return $certificate;
        }

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
            $lock->release();
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
        $model = CertificateModel::class()::on($connection)->firstOrNew([
            'driver' => $driver,
            'name' => $name,
        ]);

        $allDomains = $data->allDomains();

        $model->forceFill([
            'domain' => $data->domain,
            'domains' => count($allDomains) > 1 ? $allDomains : null,
            'status' => CertificateStatus::Requested,
            'issuer' => $data->issuer,
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

    private function certificateName(string $domain): string
    {
        $prefix = (string) config('certificates.name_prefix', 'generated-tls-');

        return $prefix.Str::of($domain)->kebab()->replace(['.', ':'], '-')->value();
    }

    private function generateLock(string $owner): Lock
    {
        return Cache::lock(
            name: (string) config('certificates.lock.name', 'certificates:generate'),
            seconds: (int) config('certificates.lock.locked_for_seconds', 5),
            owner: $owner,
        );
    }
}
