<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Facades\Certificates;

/**
 * Regression (chat review C-17): the fake recorded every certificate without an explicit
 * driver on `array` and dropped its meta, where the real issue() records the configured
 * default driver and the meta — so a test of code reading either saw something production
 * never does.
 */
it('records the configured default driver and the meta, like a real issue', function (): void {
    config()->set('certificates.default', 'kubernetes');
    Certificates::fake();

    $certificate = Certificates::for('shop.example.com')->meta(['plan' => 'pro'])->issue();

    expect($certificate->driver)->toBe('kubernetes')
        ->and($certificate->meta)->toBe(['plan' => 'pro'])
        ->and(Certificates::find('shop.example.com', 'kubernetes'))->toBe($certificate);
});

it('keeps an explicit driver and records no meta when none is given', function (): void {
    Certificates::fake();

    $certificate = Certificates::for('shop.example.com')->using('acme')->issue();

    expect($certificate->driver)->toBe('acme')
        ->and($certificate->meta)->toBeNull();
});
