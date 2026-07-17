<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\Tests\Fixtures\RegisteredCheckTestCase;

/**
 * `certificates.alerts.channels` was a shipped, documented key that nothing read.
 *
 * This is media #27's exact shape — a `max_file_size` cap that never applied, i.e. an
 * upload endpoint with no size limit — and it was found the same way: the REVERSE
 * direction of the config contract reported the key as read by nothing, which was true.
 *
 * The provider did `Health::check(new CertificateExpiryCheck)` and never passed the
 * configured channels, so `channels()` returned the alerts `Check` base's own null
 * default. A host setting `channels => ['slack']` in config/certificates.php got
 * whatever alerts defaulted to — silently, forever. For an expiry alert, "the
 * notification went somewhere other than where you configured" is the whole feature.
 *
 * The config is applied before boot by {@see RegisteredCheckTestCase}, which this
 * directory is bound to — the check is constructed during `boot()`, so a body-time
 * `config()->set()` could not have caught this.
 */
it('registers the expiry check with the configured notification channels', function (): void {
    $check = Health::all()->first(
        fn (object $check): bool => $check instanceof CertificateExpiryCheck,
    );

    expect($check)->not->toBeNull()
        ->and($check->channels())->toBe(['slack', 'mail']);
});
