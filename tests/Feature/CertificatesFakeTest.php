<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateExpiring;
use RoundlyConsulting\Certificates\Events\CertificateRenewed;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;
use RoundlyConsulting\Certificates\Testing\CertificatesFake;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;

it('swaps the binding for a fake', function (): void {
    $fake = Certificates::fake();

    expect($fake)->toBeInstanceOf(CertificatesFake::class)
        ->and(app(CertificatesManager::class))->toBe($fake);
});

/**
 * Regression: the fake's constructor skipped parent::__construct(), so every inherited
 * method that touched the provider manager died with "must not be accessed before
 * initialization" — `Certificates::fake()->driver()` threw instead of returning a driver.
 */
it('builds the fake through the parent constructor so inherited methods work', function (): void {
    $fake = Certificates::fake();

    expect($fake->driver())->toBeInstanceOf(ArrayProvider::class)
        ->and(Certificates::driver('kubernetes'))->toBeInstanceOf(ArrayProvider::class)
        ->and(Certificates::driver('kubernetes'))->toBe(Certificates::driver('kubernetes'))
        ->and(Certificates::extend('custom', fn (): CertificateProvider => new ArrayProvider))->toBe($fake);
});

it('hands the fake to constructor-injected managers', function (): void {
    $fake = Certificates::fake();

    $consumer = new class(app(CertificatesManager::class))
    {
        public function __construct(public readonly CertificatesManager $certificates) {}
    };

    $consumer->certificates->issue(IssueCertificateData::make('di.example.com'));

    expect($consumer->certificates)->toBe($fake);
    $fake->assertIssued('di.example.com');
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
        ->and(Certificates::find('app.example.com', 'kubernetes'))->toBeNull()
        ->and(Certificates::statusReport('app.example.com')?->status)->toBe(CertificateStatus::Issued)
        ->and(Certificates::statusReport('missing.example.com'))->toBeNull()
        ->and(Certificates::on('tenant')->get())->toHaveCount(1);
});

it('supports generate and issueIfMissing on the fake', function (): void {
    $fake = Certificates::fake();

    expect(Certificates::generate('gen.example.com'))->toBeTrue();
    $fake->assertIssued('gen.example.com');

    $first = Certificates::issueIfMissing('idem.example.com');
    $second = Certificates::issueIfMissing('idem.example.com');
    expect($second)->toBe($first);

    $fake->assertIssuedCount(2);
});

it('records an issuance made through the HasCertificates trait', function (): void {
    $fake = Certificates::fake();
    $tenant = Tenant::query()->create(['name' => 'acme']);

    $certificate = $tenant->requestCertificate('trait.example.com');

    expect($certificate->certifiable_id)->toBe($tenant->id)
        ->and(Certificate::query()->count())->toBe(0);
    $fake->assertIssued('trait.example.com');
    expect(fn () => $fake->assertIssued('not-requested.example.com'))->toThrow(ExpectationFailedException::class);
});

it('issues through the fluent builder on the fake', function (): void {
    $fake = Certificates::fake();

    Certificates::for(['fluent.example.com', 'www.fluent.example.com'])->using('array')->issue();

    $fake->assertIssued('fluent.example.com');
});

it('fails issuance assertions when expectations are not met', function (): void {
    $fake = Certificates::fake();

    $fake->assertNothingIssued();

    expect(fn () => $fake->assertIssued('missing.example.com'))
        ->toThrow(ExpectationFailedException::class);

    Certificates::issue(IssueCertificateData::make('one.example.com'));

    expect(fn () => $fake->assertNothingIssued())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNotIssued('one.example.com'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertIssuedCount(2))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertRequested('other.example.com'))->toThrow(ExpectationFailedException::class);

    $fake->recordFailure('boom.example.com');
    $fake->assertFailed('boom.example.com');

    expect(fn () => $fake->assertFailed('ok.example.com'))
        ->toThrow(ExpectationFailedException::class);
});

it('records renewals made flat, through the handle and via renewDue', function (): void {
    Event::fake([CertificateExpiring::class, CertificateRenewed::class]);
    $fake = Certificates::fake();

    $fake->assertNothingRenewed();
    $fake->assertNothingRenewedDue();

    Certificates::issue(IssueCertificateData::make('flat.example.com'));
    Certificates::issue(new IssueCertificateData(domain: 'handle.example.com', validForDays: 5));
    Certificates::issue(new IssueCertificateData(domain: 'due.example.com', validForDays: 3));

    $renewed = Certificates::renew('flat.example.com');
    Certificates::for('handle.example.com')->renew();
    $due = Certificates::renewDue(4);

    expect($renewed->status)->toBe(CertificateStatus::Renewed)
        ->and($due->pluck('domain')->all())->toBe(['due.example.com']);

    $fake->assertRenewed('flat.example.com');
    $fake->assertRenewed('handle.example.com');
    $fake->assertRenewed('due.example.com');
    $fake->assertRenewedDue();
    $fake->assertRenewedDue(4);
    $fake->assertNotRenewed('other.example.com');

    expect(fn () => $fake->assertRenewed('other.example.com'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNotRenewed('flat.example.com'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingRenewed())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertRenewedDue(30))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingRenewedDue())->toThrow(ExpectationFailedException::class);

    Event::assertNotDispatched(CertificateExpiring::class);
    Event::assertNotDispatched(CertificateRenewed::class);
});

it('fails renew-due assertions when renewDue never ran', function (): void {
    $fake = Certificates::fake();

    expect(fn () => $fake->assertRenewedDue())->toThrow(ExpectationFailedException::class);
});

it('records queued renewals without touching the queue', function (): void {
    Queue::fake();
    $fake = Certificates::fake();

    $fake->assertNothingRenewedLater();

    Certificates::issue(IssueCertificateData::make('later.example.com'));
    Certificates::issue(new IssueCertificateData(domain: 'due.example.com', validForDays: 2));

    Certificates::for('later.example.com')->renewLater();
    Certificates::renewDue(7, queue: true);

    $fake->assertRenewedLater('later.example.com');
    $fake->assertRenewedLater('due.example.com');
    $fake->assertNothingRenewed();

    expect(fn () => $fake->assertRenewedLater('other.example.com'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingRenewedLater())->toThrow(ExpectationFailedException::class);

    Queue::assertNothingPushed();
});

it('records revocations with their reason', function (): void {
    $fake = Certificates::fake();

    $fake->assertNothingRevoked();

    Certificates::issue(IssueCertificateData::make('flat.example.com'));
    Certificates::issue(IssueCertificateData::make('handle.example.com'));

    expect(Certificates::revoke('flat.example.com', 'key compromise')->status)->toBe(CertificateStatus::Revoked);
    Certificates::for('handle.example.com')->revoke();

    $fake->assertRevoked('flat.example.com');
    $fake->assertRevoked('flat.example.com', 'key compromise');
    $fake->assertRevoked('handle.example.com');
    $fake->assertNotRevoked('other.example.com');

    expect(fn () => $fake->assertRevoked('flat.example.com', 'superseded'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertRevoked('other.example.com'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNotRevoked('handle.example.com'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingRevoked())->toThrow(ExpectationFailedException::class);
});

it('records expirations', function (): void {
    $fake = Certificates::fake();

    $fake->assertNothingExpired();

    Certificates::issue(IssueCertificateData::make('exp.example.com'));
    Certificates::for('exp.example.com')->expire();

    $fake->assertExpired('exp.example.com');

    expect(Certificates::status('exp.example.com'))->toBe(CertificateStatus::Expired)
        ->and(fn () => $fake->assertExpired('other.example.com'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingExpired())->toThrow(ExpectationFailedException::class);
});

it('records syncs and prunes without touching the registry', function (): void {
    $fake = Certificates::fake();

    $fake->assertNothingSynced();
    $fake->assertNothingPruned();

    expect(Certificates::sync('kubernetes'))->toBe(0)
        ->and(Certificates::prune(14, CertificateStatus::Failed))->toBe(0);

    $fake->assertSynced();
    $fake->assertSynced('kubernetes');
    $fake->assertPruned();
    $fake->assertPruned(14);

    expect(fn () => $fake->assertSynced('acme'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingSynced())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertPruned(30))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingPruned())->toThrow(ExpectationFailedException::class);
});

it('fails sync and prune assertions when nothing ran', function (): void {
    $fake = Certificates::fake();

    expect(fn () => $fake->assertSynced())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertPruned())->toThrow(ExpectationFailedException::class);
});

it('mirrors the real lifecycle rules', function (): void {
    $fake = Certificates::fake();

    expect(fn () => Certificates::renew('unknown.example.com'))
        ->toThrow(CertificateException::class, 'No certificate is registered for "unknown.example.com".');

    Certificates::issue(IssueCertificateData::make('dead.example.com'));
    Certificates::revoke('dead.example.com');

    expect(fn () => Certificates::renew('dead.example.com'))
        ->toThrow(CertificateException::class, 'Cannot transition a certificate from "revoked" to "renewing".');

    $fake->assertNothingRenewed();
});

it('seeds certificates it did not issue', function (): void {
    $fake = Certificates::fake();

    $seeded = Certificate::factory()->expiring(3)->forDomain('seeded.example.com')->make(['driver' => 'kubernetes']);

    expect($fake->seed($seeded))->toBe($fake)
        ->and(Certificates::expiring(7, 'kubernetes')->all())->toBe([$seeded])
        ->and(Certificates::expiring(7, 'acme'))->toBeEmpty()
        ->and(Certificates::issueIfMissing('seeded.example.com'))->toBe($seeded);

    $fake->assertNothingIssued();

    Certificates::renew($seeded);

    $fake->assertRenewed('seeded.example.com');
});

it('re-issues through issueIfMissing when the stored certificate is inactive', function (): void {
    $fake = Certificates::fake();

    $fake->seed(Certificate::factory()->failed()->forDomain('retry.example.com')->make());

    expect(Certificates::issueIfMissing('retry.example.com')->status)->toBe(CertificateStatus::Issued);

    $fake->assertIssued('retry.example.com');
});
