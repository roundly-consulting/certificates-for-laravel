<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateExpired;
use RoundlyConsulting\Certificates\Events\CertificateExpiring;
use RoundlyConsulting\Certificates\Events\CertificateRenewed;
use RoundlyConsulting\Certificates\Events\CertificateRevoked;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;
use RoundlyConsulting\Certificates\Providers\NullProvider;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('renews a certificate by model and by domain', function (): void {
    Event::fake([CertificateRenewed::class]);

    $byModel = Certificate::factory()->issued()->forDomain('model.com')->create(['driver' => 'array']);
    Certificate::factory()->issued()->forDomain('domain.com')->create(['driver' => 'array']);

    expect(Certificates::renew($byModel)->status)->toBe(CertificateStatus::Renewed)
        ->and(Certificates::renew('domain.com')->status)->toBe(CertificateStatus::Renewed)
        ->and(Certificates::find('domain.com')?->last_renewed_at)->not->toBeNull();

    Event::assertDispatchedTimes(CertificateRenewed::class, 2);
});

it('refuses to renew an unknown domain', function (): void {
    Certificates::renew('nowhere.com');
})->throws(CertificateException::class, 'No certificate is registered for "nowhere.com".');

it('queues a renewal on the configured queue and connection', function (): void {
    Queue::fake();
    config()->set('certificates.renewal.queue', 'certs');

    $certificate = Certificate::factory()->issued()->forDomain('later.com')->create(['driver' => 'array']);

    expect(Certificates::renewLater('later.com')->is($certificate))->toBeTrue();

    Queue::assertPushedOn('certs', RenewCertificateJob::class, fn (RenewCertificateJob $job): bool => $job->certificateId === $certificate->id
        && $job->databaseConnection === $certificate->getConnectionName());
    expect($certificate->fresh()?->status)->toBe(CertificateStatus::Issued);
});

it('lists expiring certificates soonest first, optionally per driver', function (): void {
    Certificate::factory()->expiring(9)->forDomain('nine.com')->create(['driver' => 'array']);
    Certificate::factory()->expiring(3)->forDomain('three.com')->create(['driver' => 'array']);
    Certificate::factory()->expiring(4)->forDomain('k8s.com')->create(['driver' => 'kubernetes']);
    Certificate::factory()->issued()->forDomain('healthy.com')->create(['driver' => 'array']);

    $expiring = Certificates::expiring(10);

    expect($expiring)->toBeInstanceOf(EloquentCollection::class)
        ->and($expiring->pluck('domain')->all())->toBe(['three.com', 'k8s.com', 'nine.com'])
        ->and(Certificates::expiring(5)->pluck('domain')->all())->toBe(['three.com', 'k8s.com'])
        ->and(Certificates::expiring(10, 'kubernetes')->pluck('domain')->all())->toBe(['k8s.com']);
});

it('defaults the expiring window to the renewal threshold', function (): void {
    config()->set('certificates.renewal.threshold_days', 5);

    Certificate::factory()->expiring(3)->forDomain('in.com')->create(['driver' => 'array']);
    Certificate::factory()->expiring(8)->forDomain('out.com')->create(['driver' => 'array']);

    expect(Certificates::expiring()->pluck('domain')->all())->toBe(['in.com']);
});

it('renews every due certificate inline and announces each', function (): void {
    Event::fake([CertificateExpiring::class, CertificateRenewed::class]);

    Certificate::factory()->expiring(3)->forDomain('due.com')->create(['driver' => 'array']);
    Certificate::factory()->issued()->forDomain('fine.com')->create(['driver' => 'array']);

    $due = Certificates::renewDue(7);

    expect($due->pluck('domain')->all())->toBe(['due.com'])
        ->and(Certificates::status('due.com'))->toBe(CertificateStatus::Renewed)
        ->and(Certificates::status('fine.com'))->toBe(CertificateStatus::Issued);

    Event::assertDispatched(CertificateExpiring::class, fn (CertificateExpiring $e): bool => $e->certificate->domain === 'due.com' && $e->daysUntilExpiry === 3);
    Event::assertDispatchedTimes(CertificateRenewed::class, 1);
});

it('queues every due renewal with queue: true', function (): void {
    Queue::fake();

    Certificate::factory()->expiring(3)->forDomain('q1.com')->create(['driver' => 'array']);
    Certificate::factory()->expiring(4)->forDomain('q2.com')->create(['driver' => 'array']);

    expect(Certificates::renewDue(7, queue: true))->toHaveCount(2)
        ->and(Certificates::status('q1.com'))->toBe(CertificateStatus::Issued);

    Queue::assertPushed(RenewCertificateJob::class, 2);
});

it('revokes with a reason and fires the event', function (): void {
    Event::fake([CertificateRevoked::class]);

    Certificate::factory()->issued()->forDomain('rev.com')->create(['driver' => 'array']);

    $revoked = Certificates::revoke('rev.com', 'key compromise');

    expect($revoked->status)->toBe(CertificateStatus::Revoked)
        ->and($revoked->fresh()?->last_error)->toBe('key compromise');

    Event::assertDispatched(CertificateRevoked::class, fn (CertificateRevoked $e): bool => $e->reason === 'key compromise');
});

it('refuses to revoke a certificate that cannot be revoked', function (): void {
    $failed = Certificate::factory()->failed()->forDomain('dead.com')->create(['driver' => 'array']);

    Certificates::revoke($failed);
})->throws(CertificateException::class, 'Cannot transition a certificate from "failed" to "revoked".');

it('expires a certificate and fires the event', function (): void {
    Event::fake([CertificateExpired::class]);

    Certificate::factory()->issued()->forDomain('exp.com')->create(['driver' => 'array']);

    expect(Certificates::expire('exp.com')->status)->toBe(CertificateStatus::Expired);

    Event::assertDispatched(CertificateExpired::class);
});

it('refuses to expire a revoked certificate', function (): void {
    Certificate::factory()->issued()->forDomain('gone.com')->create(['driver' => 'array', 'status' => CertificateStatus::Revoked]);

    Certificates::expire('gone.com');
})->throws(CertificateException::class, 'Cannot transition a certificate from "revoked" to "expired".');

it('syncs the default or a named driver and counts the rows', function (): void {
    $provider = new ArrayProvider;
    $provider->generate('generated-tls-a-com', 'a.com');
    $provider->generate('generated-tls-b-com', 'b.com');

    Certificates::extend('array', fn (): CertificateProvider => $provider);

    expect(Certificates::sync())->toBe(2)
        ->and(Certificates::sync('array'))->toBe(2)
        ->and(Certificate::query()->count())->toBe(2)
        ->and(Certificates::find('a.com'))->status->toBe(CertificateStatus::Issued);
});

it('prunes stale dead ends, or exactly one status', function (): void {
    $this->travelTo(now()->subDays(60));
    Certificate::factory()->expired()->forDomain('old-expired.com')->create();
    Certificate::factory()->issued()->forDomain('old-issued.com')->create();
    $this->travelBack();

    Certificate::factory()->expired()->forDomain('new-expired.com')->create();

    expect(Certificates::prune(30))->toBe(1)
        ->and(Certificates::prune(30, CertificateStatus::Issued))->toBe(1)
        ->and(Certificates::prune(30, 'issued'))->toBe(0)
        ->and(Certificate::query()->pluck('domain')->all())->toBe(['new-expired.com']);
});

it('registers a custom driver through the facade', function (): void {
    $custom = new NullProvider;

    expect(Certificates::extend('custom', fn (): CertificateProvider => $custom))->toBeInstanceOf(CertificatesManager::class)
        ->and(Certificates::driver('custom'))->toBe($custom);
});

it('runs the same API through an injected manager', function (): void {
    Event::fake([CertificateRevoked::class]);

    Certificate::factory()->issued()->forDomain('di.com')->create(['driver' => 'array']);

    $manager = app(CertificatesManager::class);

    expect($manager)->toBe(Certificates::getFacadeRoot())
        ->and($manager->renew('di.com')->status)->toBe(CertificateStatus::Renewed)
        ->and($manager->revoke('di.com')->status)->toBe(CertificateStatus::Revoked);

    Event::assertDispatched(CertificateRevoked::class);
});
