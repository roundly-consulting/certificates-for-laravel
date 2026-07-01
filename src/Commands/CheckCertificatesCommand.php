<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\Alerts\ExpiryNotifiableResolver;
use RoundlyConsulting\Certificates\Events\CertificateExpiring as CertificateExpiringEvent;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Read-only monitoring: scans the registry for certificates nearing expiry,
 * fires the CertificateExpiring event for each, and (opt-in) routes an expiry
 * health check through alerts-for-laravel so an Alert row + throttled
 * notification is produced by the engine. It never renews — that is
 * certificates:renew's job.
 */
final class CheckCertificatesCommand extends Command
{
    protected $signature = 'certificates:check {--threshold=} {--alert} {--driver=} {--connection=}';

    protected $description = 'Scan for expiring certificates and optionally raise health alerts (never renews)';

    public function handle(ExpiryNotifiableResolver $resolver): int
    {
        $connection = is_string($connection = $this->option('connection')) && $connection !== ''
            ? $connection
            : null;

        $threshold = is_numeric($this->option('threshold'))
            ? (int) $this->option('threshold')
            : (int) config('certificates.renewal.threshold_days', 21);

        $query = Certificate::on($connection)->expiring($threshold);

        if (is_string($driver = $this->option('driver')) && $driver !== '') {
            $query->forDriver($driver);
        }

        $certificates = $query->orderBy('expires_at')->get();

        if ($certificates->isEmpty()) {
            $this->info((string) trans('certificates::messages.commands.none_expiring'));

            return self::SUCCESS;
        }

        $alerting = $this->alerting();
        $rows = [];

        foreach ($certificates as $certificate) {
            $days = $certificate->daysUntilExpiry() ?? 0;

            Event::dispatch(new CertificateExpiringEvent($certificate, $days));

            $rows[] = [
                $certificate->domain,
                $certificate->driver,
                (string) $days,
                $certificate->expires_at?->toDateTimeString() ?? '—',
            ];

            if ($alerting) {
                $this->raiseAlert($certificate, $resolver);
            }
        }

        $this->table(['Domain', 'Driver', 'Days left', 'Expires at'], $rows);

        return self::SUCCESS;
    }

    private function alerting(): bool
    {
        return (bool) $this->option('alert') || config('certificates.alerts.enabled', false) === true;
    }

    private function raiseAlert(Certificate $certificate, ExpiryNotifiableResolver $resolver): void
    {
        $notifiable = $resolver->resolve($certificate);

        if (! $notifiable instanceof Model) {
            $this->warn((string) trans('certificates::messages.commands.no_alert_notifiable', [
                'domain' => $certificate->domain,
            ]));

            return;
        }

        Health::run(new CertificateExpiryCheck(certificateId: $certificate->id), $notifiable);

        $this->info((string) trans('certificates::messages.commands.alerted', [
            'domain' => $certificate->domain,
        ]));
    }
}
