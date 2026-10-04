<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Alerts;

use Illuminate\Notifications\Notification;
use RoundlyConsulting\Alerts\Check;
use RoundlyConsulting\Alerts\CheckResult;
use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Notifications\HealthCheckFailedNotification;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use RoundlyConsulting\Certificates\Support\Settings;

/**
 * An alerts health check that turns certificate expiry into a monitored signal.
 *
 * Two modes:
 *  - per-certificate — bound to a `certificate_id` (constructor arg or the scheduled
 *    row's meta); returns ok/warning/failed banded on lead time, and failed for a
 *    terminal certificate (expired/revoked/failed);
 *  - registry-wide — no certificate id; fails when any managed certificate is inside
 *    the critical window, past its expiry, Expired or Failed, otherwise warns/ok on the
 *    closest one (revoked certificates are not counted).
 */
final class CertificateExpiryCheck extends Check
{
    public function __construct(
        private readonly ?int $certificateId = null,
        private readonly ?int $warningDays = null,
        private readonly ?int $criticalDays = null,
        ?HealthCheck $healthCheck = null,
    ) {
        parent::__construct($healthCheck);
    }

    public function key(): string
    {
        return 'certificate_expiry';
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['certificates'];
    }

    public function check(): CheckResult
    {
        $certificateId = $this->certificateId();

        return $certificateId === null
            ? $this->checkRegistry()
            : $this->checkCertificate($certificateId);
    }

    public function notification(object $notifiable): Notification
    {
        return new HealthCheckFailedNotification($this, channels: $this->channels());
    }

    private function checkCertificate(int $certificateId): CheckResult
    {
        $certificate = CertificateModel::class()::query()->find($certificateId);

        if (! $certificate instanceof Certificate) {
            return CheckResult::skipped(
                (string) trans('certificates::messages.alerts.missing'),
                ['certificate_id' => $certificateId],
            );
        }

        return $this->evaluate($certificate);
    }

    private function checkRegistry(): CheckResult
    {
        $warningDays = $this->warningDays();

        /** @var list<array<string, mixed>> $affected */
        $affected = [];
        $worst = CheckResult::ok((string) trans('certificates::messages.alerts.registry_ok'));

        // Every certificate that is down or about to be: Issued/Renewed ones inside the
        // warning window — including any already past expiry — plus Failed and Expired ones.
        // Revoked rows are a deliberate decision (alerted once, via CertificateRevoked),
        // not something the registry signal should stay red over.
        CertificateModel::class()::query()
            ->where(function ($query) use ($warningDays): void {
                $query->whereIn('status', [CertificateStatus::Failed, CertificateStatus::Expired])
                    ->orWhere(function ($query) use ($warningDays): void {
                        $query->whereIn('status', [CertificateStatus::Issued, CertificateStatus::Renewed])
                            ->where(function ($query) use ($warningDays): void {
                                $query->whereNull('expires_at')
                                    ->orWhere('expires_at', '<=', now()->addDays($warningDays));
                            });
                    });
            })
            ->orderBy('expires_at')
            ->each(function (Certificate $certificate) use (&$affected, &$worst): void {
                $result = $this->evaluate($certificate);

                if ($result->status->severity() >= 2) {
                    $affected[] = $result->meta;
                }

                if ($result->status->severity() > $worst->status->severity()) {
                    $worst = $result;
                }
            });

        $critical = array_values(array_filter(
            $affected,
            static fn (array $meta): bool => ($meta['band'] ?? null) === 'critical',
        ));

        if ($critical !== []) {
            return CheckResult::failed(
                trans_choice('certificates::messages.alerts.registry_failed', count($critical)),
                ['band' => 'registry', 'warning_days' => $warningDays, 'affected' => $affected],
            );
        }

        if ($worst->status->severity() >= 2) {
            return CheckResult::warning($worst->message, [...$worst->meta, 'affected' => $affected]);
        }

        return $worst;
    }

    private function evaluate(Certificate $certificate): CheckResult
    {
        $meta = $this->metaFor($certificate);

        if ($certificate->status === CertificateStatus::Failed) {
            return CheckResult::failed(
                (string) trans('certificates::messages.alerts.failed', ['domain' => $certificate->domain]),
                [...$meta, 'band' => 'critical'],
            );
        }

        if ($certificate->status->isTerminal() || $certificate->isExpired()) {
            return CheckResult::failed(
                (string) trans('certificates::messages.alerts.expired', ['domain' => $certificate->domain]),
                [...$meta, 'band' => 'critical'],
            );
        }

        $days = $certificate->daysUntilExpiry();

        if ($days === null) {
            return CheckResult::skipped(
                (string) trans('certificates::messages.alerts.missing'),
                $meta,
            );
        }

        if ($days <= $this->criticalDays()) {
            return CheckResult::failed(
                trans_choice('certificates::messages.alerts.critical', $days, ['domain' => $certificate->domain, 'days' => $days]),
                [...$meta, 'band' => 'critical'],
            );
        }

        if ($days <= $this->warningDays()) {
            return CheckResult::warning(
                trans_choice('certificates::messages.alerts.warning', $days, ['domain' => $certificate->domain, 'days' => $days]),
                [...$meta, 'band' => 'warning'],
            );
        }

        return CheckResult::ok(
            trans_choice('certificates::messages.alerts.ok', $days, ['domain' => $certificate->domain, 'days' => $days]),
            [...$meta, 'band' => 'ok'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metaFor(Certificate $certificate): array
    {
        return [
            'certificate_id' => $certificate->id,
            'domain' => $certificate->domain,
            'driver' => $certificate->driver,
            'status' => $certificate->status->value,
            'days_until_expiry' => $certificate->daysUntilExpiry(),
            'expires_at' => $certificate->expires_at?->toIso8601String(),
        ];
    }

    private function certificateId(): ?int
    {
        if ($this->certificateId !== null) {
            return $this->certificateId;
        }

        $value = $this->healthCheck?->meta['certificate_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function warningDays(): int
    {
        return $this->warningDays
            ?? $this->metaInt('warning_days')
            ?? self::configuredWarningDays();
    }

    private function criticalDays(): int
    {
        return $this->criticalDays
            ?? $this->metaInt('critical_days')
            ?? self::configuredCriticalDays();
    }

    /**
     * `certificates.alerts.thresholds.warning_days` (0 or more). An int or a canonical
     * integer string; anything else throws rather than reading as 0.
     */
    public static function configuredWarningDays(): int
    {
        return Settings::integer('certificates.alerts.thresholds.warning_days', config('certificates.alerts.thresholds.warning_days'), 30, min: 0);
    }

    /**
     * `certificates.alerts.thresholds.critical_days` (0 or more), read like the warning one.
     */
    public static function configuredCriticalDays(): int
    {
        return Settings::integer('certificates.alerts.thresholds.critical_days', config('certificates.alerts.thresholds.critical_days'), 7, min: 0);
    }

    private function metaInt(string $key): ?int
    {
        $value = $this->healthCheck?->meta[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
