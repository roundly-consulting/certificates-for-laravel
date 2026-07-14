<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RoundlyConsulting\Certificates\CertificateManager;
use RoundlyConsulting\Certificates\Contracts\ProvisionsMultipleDomains;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Events\CertificateRequested;
use RoundlyConsulting\Certificates\Exceptions\InvalidDomainException;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Rules\ValidDomain;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use Throwable;

final class IssueCertificateAction
{
    public function __construct(
        private readonly CertificateManager $manager,
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
        } catch (Throwable $e) {
            $certificate->markFailed($e->getMessage());
            Event::dispatch(new CertificateFailed($certificate, $e->getMessage()));

            throw $e;
        } finally {
            $lock->release();
        }

        $expiresAt = $this->resolveExpiry($provider, $name, $data);

        $certificate->markIssued($expiresAt ?? now()->addDays($data->validForDays ?? 90));

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

    private function resolveExpiry(object $provider, string $name, IssueCertificateData $data): ?CarbonInterface
    {
        if (! $provider instanceof ReportsCertificateStatus) {
            return null;
        }

        return $provider->status($name, $data->domain)->expiresAt;
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
