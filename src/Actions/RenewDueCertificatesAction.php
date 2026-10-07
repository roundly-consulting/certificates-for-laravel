<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\DataTransferObjects\RenewalFailure;
use RoundlyConsulting\Certificates\DataTransferObjects\RenewalReport;
use RoundlyConsulting\Certificates\Events\CertificateExpiring;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use Throwable;

/**
 * Renew every certificate expiring within the threshold (default:
 * `certificates.renewal.threshold_days`) or already past expiry — Issued, Renewed, or Failed
 * by an earlier attempt. CertificateExpiring fires for each; each one is
 * then renewed inline, or — with `$queue` — handed to RenewCertificateJob.
 *
 * Each certificate is attempted independently: one that fails is reported (and handed to
 * the exception handler) and the run moves on, so a single CA rejection cannot leave the
 * rest of the due set to expire. A failed inline renewal is already marked Failed with
 * CertificateFailed by RenewCertificateAction; a failed dispatch leaves the row as it was,
 * so the next run picks it up again.
 *
 * Reach it through `Certificates::renewDue()` (what `certificates:renew` runs).
 */
final readonly class RenewDueCertificatesAction
{
    public function __construct(
        private RenewCertificateAction $renew,
        private Dispatcher $bus,
    ) {}

    public function execute(?int $thresholdDays = null, bool $queue = false, ?string $connection = null): RenewalReport
    {
        $model = CertificateModel::class();
        $thresholdDays ??= $model::thresholdDays();

        $certificates = $model::on($connection)
            ->expiring($thresholdDays)
            ->orderBy('expires_at')
            ->get();

        $renewed = [];
        $queued = [];
        $failed = [];

        foreach ($certificates as $certificate) {
            try {
                Event::dispatch(new CertificateExpiring($certificate, $certificate->daysUntilExpiry() ?? 0));

                if ($queue) {
                    $this->bus->dispatch(new RenewCertificateJob($certificate->id, $connection, $thresholdDays));
                    $queued[] = $certificate;

                    continue;
                }

                $renewed[] = $this->renew->execute($certificate);
            } catch (Throwable $e) {
                report($e);

                $failed[] = new RenewalFailure($certificate, $e);
            }
        }

        return new RenewalReport($renewed, $queued, $failed);
    }
}
