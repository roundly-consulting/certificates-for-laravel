<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Exceptions\InvalidDomainException;
use RoundlyConsulting\Certificates\Facades\Certificates;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

/**
 * Regression: the README says a status that can't make the move throws — but renewLater()
 * never checked, so it queued a job for a revoked certificate that then failed later in
 * the worker, far from the caller.
 */
it('refuses to queue the renewal of a certificate that cannot renew', function (Closure $renewLater): void {
    Queue::fake();
    Certificates::revoke(Certificates::issue(IssueCertificateData::make('revoked.example.com')));

    expect(fn () => $renewLater())
        ->toThrow(CertificateException::class, 'Cannot transition a certificate from "revoked" to "renewing".');

    Queue::assertNothingPushed();
})->with([
    'flat' => fn () => Certificates::renewLater('revoked.example.com'),
    'handle' => fn () => Certificates::for('revoked.example.com')->renewLater(),
]);

it('refuses it under the fake too', function (): void {
    $fake = Certificates::fake();
    Certificates::revoke(Certificates::issue(IssueCertificateData::make('revoked.example.com')));

    expect(fn () => Certificates::renewLater('revoked.example.com'))
        ->toThrow(CertificateException::class, 'Cannot transition a certificate from "revoked" to "renewing".');

    $fake->assertNothingRenewedLater();
});

/**
 * Regression: the fake skipped domain validation, so `Certificates::for('not a domain')`
 * "issued" under test while the real manager throws InvalidDomainException.
 */
it('rejects an invalid domain under the fake, as the real manager does', function (array $domains): void {
    $fake = Certificates::fake();

    expect(fn () => Certificates::for($domains)->issue())
        ->toThrow(InvalidDomainException::class);

    $fake->assertNothingIssued();
    expect(Certificates::find($domains[0]))->toBeNull();
})->with([
    'primary' => [['not a domain']],
    'a SAN' => [['app.example.com', 'bad domain']],
]);
