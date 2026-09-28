<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\Events\CertificateRenewed;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\CustomCertificate;

/**
 * Regression: RenewCertificateJob re-read its row with `Certificate::query()`, bypassing
 * the `certificates.model` seam, so a queued renewal handed the host's listeners the
 * packaged Certificate instead of the host's own model — and fired none of its events.
 */
it('re-reads a queued renewal through the certificates.model seam', function (): void {
    Event::fake([CertificateRenewed::class]);

    // The packaged factory builds the base class; only the job's re-read decides which
    // class the listeners receive.
    $certificate = Certificate::factory()->issued()->create(['domain' => 'queued.test', 'driver' => 'array']);

    // The sync queue runs the job inline, so this is the real dispatch → handle path.
    Certificates::renewLater($certificate);

    Event::assertDispatched(
        CertificateRenewed::class,
        fn (CertificateRenewed $event): bool => $event->certificate instanceof CustomCertificate
            && $event->certificate->is($certificate),
    );
});
