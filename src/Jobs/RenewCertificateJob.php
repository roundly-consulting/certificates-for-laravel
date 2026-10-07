<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Certificates\Actions\RenewCertificateAction;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use RoundlyConsulting\Certificates\Support\Settings;

/**
 * The queued form of a renewal, dispatched by `Certificates::renewLater()` and
 * `Certificates::renewDue(queue: true)`. It re-reads the row through the
 * `certificates.model` seam on the database connection it was queued from.
 *
 * A job a sweep queued carries the sweep's threshold and does nothing once the certificate
 * is no longer due — an earlier job or a manual renew() got there first. A renewLater()
 * job carries none and always renews.
 */
final class RenewCertificateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The threshold of the sweep that queued this renewal; null renews unconditionally.
     * A plain property with a default, so a job serialized before it existed still runs.
     */
    public ?int $thresholdDays = null;

    public function __construct(
        public readonly int $certificateId,
        public readonly ?string $databaseConnection = null,
        ?int $thresholdDays = null,
    ) {
        $this->thresholdDays = $thresholdDays;

        $queue = Settings::optionalString('certificates.renewal.queue', config('certificates.renewal.queue'));

        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    public function handle(RenewCertificateAction $action): void
    {
        $certificate = CertificateModel::class()::on($this->databaseConnection)
            ->whereKey($this->certificateId)
            ->when($this->thresholdDays !== null, fn ($query) => $query->expiring($this->thresholdDays))
            ->first();

        if ($certificate === null) {
            return;
        }

        $action->execute($certificate);
    }
}
