<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\Database\Factories\CertificateFactory;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateExpired;
use RoundlyConsulting\Certificates\Events\CertificateRevoked;

/**
 * @property int $id
 * @property string $name
 * @property string $domain
 * @property list<string>|null $domains
 * @property string $driver
 * @property CertificateStatus $status
 * @property string|null $issuer
 * @property string|null $serial
 * @property string|null $fingerprint
 * @property string|null $certifiable_type
 * @property int|null $certifiable_id
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $last_renewed_at
 * @property string|null $last_error
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Model|null $certifiable
 *
 * Deliberately not final: `certificates.model` documents swapping in a subclass
 * of this model, which final would make impossible.
 */
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('certificates.table', 'certificates');
    }

    public function getConnectionName(): ?string
    {
        $configured = config('certificates.connection');

        if ($this->connection === null && is_string($configured) && $configured !== '') {
            return $configured;
        }

        return parent::getConnectionName();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function certifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->status->isActive() && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        if ($this->status === CertificateStatus::Expired) {
            return true;
        }

        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expiresWithin(int $days): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lessThanOrEqualTo(CarbonImmutable::now()->addDays($days));
    }

    public function daysUntilExpiry(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) CarbonImmutable::now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }

    public function markIssued(CarbonInterface $expiresAt): self
    {
        $this->forceFill([
            'status' => CertificateStatus::Issued,
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => $expiresAt,
            'last_error' => null,
        ])->save();

        return $this;
    }

    public function markRenewed(CarbonInterface $expiresAt): self
    {
        $this->forceFill([
            'status' => CertificateStatus::Renewed,
            'expires_at' => $expiresAt,
            'last_renewed_at' => CarbonImmutable::now(),
            'last_error' => null,
        ])->save();

        return $this;
    }

    public function markFailed(?string $reason = null): self
    {
        $this->forceFill([
            'status' => CertificateStatus::Failed,
            'last_error' => $reason,
        ])->save();

        return $this;
    }

    /**
     * Mark the certificate as revoked and dispatch the CertificateRevoked event.
     */
    public function markRevoked(?string $reason = null): self
    {
        $this->forceFill([
            'status' => CertificateStatus::Revoked,
            'last_error' => $reason,
        ])->save();

        Event::dispatch(new CertificateRevoked($this, $reason));

        return $this;
    }

    /**
     * Mark the certificate as expired and dispatch the CertificateExpired event.
     */
    public function markExpired(): self
    {
        $this->forceFill([
            'status' => CertificateStatus::Expired,
        ])->save();

        Event::dispatch(new CertificateExpired($this));

        return $this;
    }

    /**
     * @param  Builder<Certificate>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [CertificateStatus::Issued, CertificateStatus::Renewed])
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now());
            });
    }

    /**
     * @param  Builder<Certificate>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->where('status', CertificateStatus::Expired)
                ->orWhere('expires_at', '<=', CarbonImmutable::now());
        });
    }

    /**
     * @param  Builder<Certificate>  $query
     */
    public function scopeExpiring(Builder $query, ?int $days = null): void
    {
        $days ??= (int) config('certificates.renewal.threshold_days', 21);

        $query->whereIn('status', [CertificateStatus::Issued, CertificateStatus::Renewed])
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [CarbonImmutable::now(), CarbonImmutable::now()->addDays($days)]);
    }

    /**
     * @param  Builder<Certificate>  $query
     */
    public function scopeForDomain(Builder $query, string $domain): void
    {
        $query->where('domain', $domain);
    }

    /**
     * @param  Builder<Certificate>  $query
     */
    public function scopeForDriver(Builder $query, string $driver): void
    {
        $query->where('driver', $driver);
    }

    /**
     * Match rows whose primary domain equals the given domain, or whose SAN
     * list (the json "domains" column) contains it.
     *
     * @param  Builder<Certificate>  $query
     */
    public function scopeCoveringDomain(Builder $query, string $domain): void
    {
        $query->where(function (Builder $query) use ($domain): void {
            $query->where('domain', $domain)
                ->orWhereJsonContains('domains', $domain);
        });
    }

    protected static function newFactory(): CertificateFactory
    {
        return CertificateFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'last_renewed_at' => 'immutable_datetime',
            'meta' => 'array',
            'domains' => 'array',
        ];
    }
}
