<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Certificates\Events\CertificateExpiring as CertificateExpiringEvent;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Notifications\CertificateExpiring as CertificateExpiringNotification;

/**
 * Read-only monitoring: scans the registry for certificates nearing expiry,
 * fires the CertificateExpiring event for each, and (opt-in) notifies. It never
 * renews — that is certificates:renew's job.
 */
final class CheckCertificatesCommand extends Command
{
    protected $signature = 'certificates:check {--threshold=} {--notify} {--driver=} {--connection=}';

    protected $description = 'Scan for expiring certificates and optionally notify (never renews)';

    public function handle(): int
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

            if ($this->shouldNotify()) {
                $this->notify($certificate, $days);
            }
        }

        $this->table(['Domain', 'Driver', 'Days left', 'Expires at'], $rows);

        return self::SUCCESS;
    }

    private function shouldNotify(): bool
    {
        return (bool) $this->option('notify') || (bool) config('certificates.notifications.enabled', false);
    }

    private function notify(Certificate $certificate, int $days): void
    {
        $notification = new CertificateExpiringNotification($certificate, $days);

        $notifiable = config('certificates.notifications.notifiable');

        if (is_string($notifiable) && $notifiable !== '') {
            Notification::send(app($notifiable), $notification);

            return;
        }

        /** @var array<string, mixed> $configured */
        $configured = config('certificates.notifications.route', []);

        /** @var array<string, string> $route */
        $route = array_filter($configured, static fn (mixed $value): bool => is_string($value) && $value !== '');

        if ($route === []) {
            $this->warn((string) trans('certificates::messages.commands.no_notifiable'));

            return;
        }

        $notifier = new AnonymousNotifiable;

        foreach ($route as $channel => $destination) {
            $notifier->route($channel, $destination);
        }

        $notifier->notify($notification);
    }
}
