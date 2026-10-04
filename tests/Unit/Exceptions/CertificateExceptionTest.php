<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;

it('resolves the unresolved alert notifiable error in the current locale', function (): void {
    expect(CertificateException::noAlertNotifiable('app.example.com')->getMessage())->toBe(
        'No alert notifiable could be resolved for [app.example.com]. Pass one to monitorExpiry(), '.
        'set certificates.alerts.notifiable, or associate the certificate with a certifiable owner.',
    );

    app()->setLocale('sk');

    expect(CertificateException::noAlertNotifiable('app.example.com')->getMessage())->toBe(
        'Pre „app.example.com“ sa nepodarilo určiť príjemcu upozornení. Odovzdajte ho metóde monitorExpiry(), '.
        'nastavte certificates.alerts.notifiable alebo certifikát priraďte vlastníkovi (certifiable).',
    );
});

it('names the reported provider status by its translated label', function (): void {
    expect(CertificateException::providerReported('array', 'app.example.com', CertificateStatus::Failed)->getMessage())
        ->toBe('The array provider reports the certificate for "app.example.com" as Failed.');

    app()->setLocale('sk');

    expect(CertificateException::providerReported('array', 'app.example.com', CertificateStatus::Failed)->getMessage())
        ->toBe('Poskytovateľ array hlási certifikát pre „app.example.com“ v stave „Neúspešný“.');
});
