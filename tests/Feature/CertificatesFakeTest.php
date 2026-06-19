<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Certificates\CertificateService;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Testing\CertificatesFake;

it('swaps the binding for a fake', function (): void {
    $fake = Certificates::fake();

    expect($fake)->toBeInstanceOf(CertificatesFake::class)
        ->and(app(CertificateService::class))->toBeInstanceOf(CertificatesFake::class);
});

it('records issued certificates and passes assertions', function (): void {
    $fake = Certificates::fake();

    Certificates::issue(IssueCertificateData::make('app.example.com'));

    $fake->assertIssued('app.example.com');
    $fake->assertRequested('app.example.com');
    $fake->assertIssuedCount(1);
    $fake->assertNotIssued('other.example.com');

    expect(Certificates::status('app.example.com'))->toBe(CertificateStatus::Issued)
        ->and(Certificates::exists('app.example.com'))->toBeTrue()
        ->and(Certificates::find('app.example.com'))->not->toBeNull()
        ->and(Certificates::get())->toHaveCount(1);
});

it('supports generate and issueIfMissing on the fake', function (): void {
    $fake = Certificates::fake();

    expect(Certificates::generate('gen.example.com'))->toBeTrue();
    $fake->assertIssued('gen.example.com');

    $first = Certificates::issueIfMissing('idem.example.com');
    $second = Certificates::issueIfMissing('idem.example.com');
    expect($second)->toBe($first);
});

it('issues through the fluent builder on the fake', function (): void {
    $fake = Certificates::fake();

    Certificates::for('fluent.example.com')->using('array')->issue();

    $fake->assertIssued('fluent.example.com');
});

it('fails assertions when expectations are not met', function (): void {
    $fake = Certificates::fake();

    expect(fn () => $fake->assertIssued('missing.example.com'))
        ->toThrow(ExpectationFailedException::class);

    $fake->recordFailure('boom.example.com');
    $fake->assertFailed('boom.example.com');

    expect(fn () => $fake->assertFailed('ok.example.com'))
        ->toThrow(ExpectationFailedException::class);
});
