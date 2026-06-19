<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\CertificateManager;

it('resolves the manager via the certificates() helper', function (): void {
    expect(certificates())->toBeInstanceOf(CertificateManager::class);
});
