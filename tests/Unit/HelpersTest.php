<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Testing\CertificatesFake;

it('resolves the facade root via the certificates() helper', function (): void {
    expect(certificates())
        ->toBeInstanceOf(CertificatesManager::class)
        ->toBe(Certificates::getFacadeRoot());
});

it('resolves the fake via the helper once faked', function (): void {
    $fake = Certificates::fake();

    expect(certificates())->toBeInstanceOf(CertificatesFake::class)->toBe($fake);
});
