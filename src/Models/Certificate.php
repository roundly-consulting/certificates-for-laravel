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
use Illuminate\Support\Str;
use RoundlyConsulting\Certificates\Database\Factories\CertificateFactory;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateExpired;
use RoundlyConsulting\Certificates\Events\CertificateRevoked;
use RoundlyConsulting\Certificates\Support\ProvisioningLock;
use RoundlyConsulting\Certificates\Support\Settings;

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
        return Settings::string('certificates.table', config('certificates.table'), 'certificates');
    }

    public function getConnectionName(): ?string
    {
        $configured = Settings::optionalString('certificates.connection', config('certificates.connection'));

        if ($this->connection === null && $configured !== null) {
            return $configured;
        }

        return parent::getConnectionName();
    }

    /**
     * `certificates.renewal.threshold_days`: renew this many days before expiry (at least
     * one). An int or a canonical integer string; anything else throws.
     */
    public static function thresholdDays(): int
    {
        return Settings::integer('certificates.renewal.threshold_days', config('certificates.renewal.threshold_days'), 21, min: 1);
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

    /**
     * Whether a renewal may start now: the status allows it, or an earlier renewal was
     * interrupted and left the row stuck in Renewing (see isStaleRenewal()).
     *
     * @internal the renew action's, renewLater()'s and the fake's guard
     */
    public function canRenew(): bool
    {
        return $this->status->canTransitionTo(CertificateStatus::Renewing) || $this->isStaleRenewal();
    }

    /**
     * Whether this row is stuck in Renewing: the renewal's process died (a queue timeout
     * mid-poll, a deploy) and the row has been untouched for longer than a provisioning lock
     * lives (`certificates.lock.locked_for_seconds`), so no process can still be renewing it.
     *
     * @internal see canRenew()
     */
    public function isStaleRenewal(): bool
    {
        return $this->status === CertificateStatus::Renewing
            && $this->updated_at !== null
            && $this->updated_at->lessThanOrEqualTo(self::staleRenewalCutoff());
    }

    /**
     * A Renewing row last touched at or before this instant is a stuck renewal.
     *
     * @internal see isStaleRenewal()
     */
    public static function staleRenewalCutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subSeconds(ProvisioningLock::seconds());
    }

    public function daysUntilExpiry(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) CarbonImmutable::now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }

    /**
     * @internal lifecycle mutator behind IssueCertificateAction — issue through `Certificates::issue()`
     */
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

    /**
     * @internal lifecycle mutator behind RenewCertificateAction — renew through `Certificates::renew()`
     */
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

    /**
     * @internal lifecycle mutator behind the issue/renew actions
     */
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
     *
     * @internal lifecycle mutator behind RevokeCertificateAction — revoke through
     *           `Certificates::revoke()`, which also guards the transition and is faked
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
     *
     * @internal lifecycle mutator behind ExpireCertificateAction — expire through
     *           `Certificates::expire()`, which also guards the transition and is faked
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
     * Certificates whose live certificate runs out within `$days` (default:
     * `certificates.renewal.threshold_days`) or already has: Issued, Renewed — and Failed, since a failed
     * renewal leaves the old certificate running out with nothing renewing it, as does a
     * renewal that was interrupted and left the row stuck in Renewing.
     *
     * @param  Builder<Certificate>  $query
     */
    public function scopeExpiring(Builder $query, ?int $days = null): void
    {
        $days ??= self::thresholdDays();
        $updatedAt = $query->getModel()->getUpdatedAtColumn() ?? 'updated_at';

        $query->where(function (Builder $query) use ($updatedAt): void {
            $query->whereIn('status', [CertificateStatus::Issued, CertificateStatus::Renewed, CertificateStatus::Failed])
                ->orWhere(function (Builder $query) use ($updatedAt): void {
                    $query->where('status', CertificateStatus::Renewing)
                        ->where($updatedAt, '<=', self::staleRenewalCutoff());
                });
        })
            ->whereNotNull('expires_at')
            // No lower bound: a certificate that already ran out while its renewal kept
            // failing is the one most in need of the next attempt.
            ->where('expires_at', '<=', CarbonImmutable::now()->addDays($days));
    }

    /**
     * @param  Builder<Certificate>  $query
     */
    public function scopeForDomain(Builder $query, string $domain): void
    {
        $query->where('domain', Str::lower(trim($domain)));
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
        $domain = Str::lower(trim($domain));

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
