<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Certificates\Actions\RenewCertificateAction;
use RoundlyConsulting\Certificates\Models\Certificate;

final class RenewCertificateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $certificateId,
    ) {
        $queue = config('certificates.renewal.queue');

        if (is_string($queue)) {
            $this->onQueue($queue);
        }
    }

    public function handle(RenewCertificateAction $action): void
    {
        $certificate = Certificate::query()->find($this->certificateId);

        if ($certificate === null) {
            return;
        }

        $action->execute($certificate);
    }
}
