<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\Events\CertificateExpiring;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CertificateModel;

/**
 * Renew every active certificate expiring within the threshold (default:
 * `certificates.renewal.threshold_days`). CertificateExpiring fires for each; each one is
 * then renewed inline, or — with `$queue` — handed to RenewCertificateJob.
 *
 * Reach it through `Certificates::renewDue()` (what `certificates:renew` runs).
 */
final readonly class RenewDueCertificatesAction
{
    public function __construct(
        private RenewCertificateAction $renew,
    ) {}

    /**
     * @return Collection<int, Certificate> the certificates that were due, soonest first
     */
    public function execute(?int $thresholdDays = null, bool $queue = false, ?string $connection = null): Collection
    {
        $certificates = CertificateModel::class()::on($connection)
            ->expiring($thresholdDays)
            ->orderBy('expires_at')
            ->get();

        foreach ($certificates as $certificate) {
            Event::dispatch(new CertificateExpiring($certificate, $certificate->daysUntilExpiry() ?? 0));

            if ($queue) {
                RenewCertificateJob::dispatch($certificate->id, $connection);

                continue;
            }

            $this->renew->execute($certificate);
        }

        return $certificates;
    }
}
