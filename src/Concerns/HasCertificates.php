<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CertificateModel;

/**
 * @phpstan-require-extends Model
 */
trait HasCertificates
{
    /**
     * @return MorphMany<Certificate, $this>
     */
    public function certificates(): MorphMany
    {
        return $this->morphMany(CertificateModel::class(), 'certifiable');
    }

    /**
     * Issue a certificate owned by this model — through the manager, so
     * `Certificates::fake()` records it.
     */
    public function requestCertificate(string $domain, ?string $driver = null): Certificate
    {
        return app(CertificatesManager::class)->issue(new IssueCertificateData(
            domain: $domain,
            driver: $driver,
            owner: $this,
        ));
    }

    public function certificateFor(string $domain): ?Certificate
    {
        return $this->certificates()->where('domain', $domain)->latest('id')->first();
    }

    public function hasCertificateFor(string $domain): bool
    {
        return $this->certificates()->where('domain', $domain)->exists();
    }

    /**
     * @return Collection<int, Certificate>
     */
    public function activeCertificates(): Collection
    {
        return $this->certificates()->active()->get();
    }

    /**
     * @return Collection<int, Certificate>
     */
    public function expiringCertificates(?int $days = null): Collection
    {
        return $this->certificates()->expiring($days)->get();
    }
}
