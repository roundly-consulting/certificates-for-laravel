<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\CertificatesServiceProvider;

it('does not register the registry-wide check by default', function (): void {
    expect(Health::find('certificate_expiry'))->toBeNull();
});

it('registers the registry-wide check when register_check is enabled', function (): void {
    config()->set('certificates.alerts.register_check', true);

    (new CertificatesServiceProvider($this->app))->boot();

    expect(Health::find('certificate_expiry'))->toBeInstanceOf(CertificateExpiryCheck::class);
});
