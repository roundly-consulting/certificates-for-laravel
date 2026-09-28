<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Certificates\Actions\ExpireCertificateAction;
use RoundlyConsulting\Certificates\Actions\PruneCertificatesAction;
use RoundlyConsulting\Certificates\Actions\RenewDueCertificatesAction;
use RoundlyConsulting\Certificates\Actions\RevokeCertificateAction;
use RoundlyConsulting\Certificates\Actions\SyncCertificatesAction;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateExpired;
use RoundlyConsulting\Certificates\Events\CertificateRevoked;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('renew-due renews what is due, and only that', function (): void {
    $due = Certificate::factory()->expiring(2)->forDomain('due.com')->create(['driver' => 'array']);
    Certificate::factory()->expiring(20)->forDomain('later.com')->create(['driver' => 'array']);

    $renewed = app(RenewDueCertificatesAction::class)->execute(7);

    expect($renewed->modelKeys())->toBe([$due->id])
        ->and($due->fresh()?->status)->toBe(CertificateStatus::Renewed);
});

it('renew-due queues onto the job with the connection', function (): void {
    Queue::fake();
    Certificate::factory()->expiring(2)->create(['driver' => 'array']);

    app(RenewDueCertificatesAction::class)->execute(7, queue: true, connection: 'testing');

    Queue::assertPushed(RenewCertificateJob::class, fn (RenewCertificateJob $job): bool => $job->databaseConnection === 'testing');
});

it('revoke records the reason and fires the event', function (): void {
    Event::fake([CertificateRevoked::class]);
    $certificate = Certificate::factory()->issued()->create(['driver' => 'array']);

    app(RevokeCertificateAction::class)->execute($certificate, 'superseded');

    expect($certificate->fresh())
        ->status->toBe(CertificateStatus::Revoked)
        ->last_error->toBe('superseded');
    Event::assertDispatched(CertificateRevoked::class);
});

it('revoke refuses a terminal certificate', function (): void {
    app(RevokeCertificateAction::class)->execute(Certificate::factory()->expired()->create());
})->throws(CertificateException::class);

it('expire marks a renewed certificate expired and fires the event', function (): void {
    Event::fake([CertificateExpired::class]);
    $certificate = Certificate::factory()->issued()->create(['status' => CertificateStatus::Renewed]);

    app(ExpireCertificateAction::class)->execute($certificate);

    expect($certificate->fresh()?->status)->toBe(CertificateStatus::Expired);
    Event::assertDispatched(CertificateExpired::class);
});

it('expire refuses a certificate that was never issued', function (): void {
    app(ExpireCertificateAction::class)->execute(Certificate::factory()->create());
})->throws(CertificateException::class, 'Cannot transition a certificate from "pending" to "expired".');

it('sync refreshes status, expiry and issuer from a reporting provider', function (): void {
    $expiresAt = now()->addDays(40)->toImmutable();

    app(CertificateProviderManager::class)->extend('reporting', fn (): CertificateProvider => new class($expiresAt) implements CertificateProvider, ReportsCertificateStatus
    {
        public function __construct(private readonly CarbonImmutable $expiresAt) {}

        public function get(): Collection
        {
            return collect([new RemoteCertificate('generated-tls-r-com', 'r.com')]);
        }

        public function exists(string $name, string $domain): bool
        {
            return true;
        }

        public function generate(string $name, string $domain): void {}

        public function status(string $name, string $domain): CertificateStatusReport
        {
            return new CertificateStatusReport(status: CertificateStatus::Renewed, expiresAt: $this->expiresAt, issuer: 'Test CA');
        }
    });

    expect(app(SyncCertificatesAction::class)->execute('reporting'))->toBe(1);

    expect(Certificate::query()->forDomain('r.com')->forDriver('reporting')->first())
        ->status->toBe(CertificateStatus::Renewed)
        ->issuer->toBe('Test CA')
        ->expires_at->toDateTimeString()->toBe($expiresAt->toDateTimeString());
});

it('sync keeps an existing row\'s status for a non-reporting provider', function (): void {
    app(CertificateProviderManager::class)->extend('plain', fn (): CertificateProvider => new class implements CertificateProvider
    {
        public function get(): Collection
        {
            return collect([new RemoteCertificate('generated-tls-p-com', 'p.com')]);
        }

        public function exists(string $name, string $domain): bool
        {
            return true;
        }

        public function generate(string $name, string $domain): void {}
    });

    Certificate::factory()->create([
        'name' => 'generated-tls-p-com',
        'domain' => 'p.com',
        'driver' => 'plain',
        'status' => CertificateStatus::Renewing,
    ]);

    expect(app(SyncCertificatesAction::class)->execute('plain'))->toBe(1)
        ->and(Certificate::query()->forDomain('p.com')->first()?->status)->toBe(CertificateStatus::Renewing);
});

it('prune soft-deletes stale dead ends only', function (): void {
    $this->travelTo(now()->subDays(40));
    Certificate::factory()->failed()->create();
    Certificate::factory()->issued()->create();
    $this->travelBack();

    expect(app(PruneCertificatesAction::class)->execute(30))->toBe(1)
        ->and(Certificate::query()->count())->toBe(1)
        ->and(Certificate::withTrashed()->count())->toBe(2);
});
