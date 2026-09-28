<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Support\CertificateBuilder;

afterEach(function (): void {
    CertificatesManager::flushMacros();
    CertificateBuilder::flushMacros();
});

it('registers and resolves a macro on the service', function (): void {
    CertificatesManager::macro('answer', fn (): int => 42);

    expect(CertificatesManager::hasMacro('answer'))->toBeTrue()
        ->and(Certificates::answer())->toBe(42);
});

it('binds $this to the service inside a macro', function (): void {
    CertificatesManager::macro('myName', fn (string $domain): string => $this->certificateName($domain));

    expect(Certificates::myName('app.com'))->toBe('generated-tls-app-com');
});

it('registers a macro on the builder', function (): void {
    CertificateBuilder::macro('primary', fn (): string => $this->find()?->domain ?? 'app.com');

    expect(Certificates::for('app.com')->primary())->toBe('app.com');
});
