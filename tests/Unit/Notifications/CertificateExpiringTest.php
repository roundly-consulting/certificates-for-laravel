<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Notifications\CertificateExpiring;

function expiringNotification(): CertificateExpiring
{
    $certificate = Certificate::factory()->create(['domain' => 'app.com', 'driver' => 'array']);

    return new CertificateExpiring($certificate, 5);
}

it('reads channels from config', function (): void {
    config()->set('certificates.notifications.channels', ['mail', 'database']);

    expect(expiringNotification()->via(new stdClass))->toBe(['mail', 'database']);
});

it('builds a mail message containing the domain and days', function (): void {
    $mail = expiringNotification()->toMail(new stdClass);

    expect($mail->subject)->toContain('app.com')
        ->and(implode(' ', $mail->introLines))->toContain('5');
});

it('builds an array payload', function (): void {
    $payload = expiringNotification()->toArray(new stdClass);

    expect($payload)->toMatchArray([
        'domain' => 'app.com',
        'driver' => 'array',
        'days_until_expiry' => 5,
    ]);
});
