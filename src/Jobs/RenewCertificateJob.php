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
 */
final class RenewCertificateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $certificateId,
        public readonly ?string $databaseConnection = null,
    ) {
        $queue = Settings::optionalString('certificates.renewal.queue', config('certificates.renewal.queue'));

        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    public function handle(RenewCertificateAction $action): void
    {
        $certificate = CertificateModel::class()::on($this->databaseConnection)->find($this->certificateId);

        if ($certificate === null) {
            return;
        }

        $action->execute($certificate);
    }
}
