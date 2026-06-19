<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('casts status, dates, and meta', function (): void {
    $certificate = Certificate::factory()->issued()->create(['meta' => ['k' => 'v']]);
    $certificate->refresh();

    expect($certificate->status)->toBeInstanceOf(CertificateStatus::class)
        ->and($certificate->issued_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($certificate->expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($certificate->meta)->toBe(['k' => 'v']);
});

it('honours the configured table name', function (): void {
    expect((new Certificate)->getTable())->toBe('certificates');
});

it('detects an expired certificate', function (): void {
    $expired = Certificate::factory()->expired()->make();
    $active = Certificate::factory()->issued()->make();

    expect($expired->isExpired())->toBeTrue()
        ->and($active->isExpired())->toBeFalse();
});

it('reports active state', function (): void {
    expect(Certificate::factory()->issued()->make()->isActive())->toBeTrue()
        ->and(Certificate::factory()->expired()->make()->isActive())->toBeFalse()
        ->and(Certificate::factory()->failed()->make()->isActive())->toBeFalse();
});

it('checks expiry windows', function (): void {
    $certificate = Certificate::factory()->expiring(5)->make();

    expect($certificate->expiresWithin(7))->toBeTrue()
        ->and($certificate->expiresWithin(3))->toBeFalse();

    expect(Certificate::factory()->make(['expires_at' => null])->expiresWithin(7))->toBeFalse();
});

it('calculates days until expiry', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 12:00:00');

    $certificate = Certificate::factory()->make(['expires_at' => CarbonImmutable::parse('2026-01-11 12:00:00')]);

    expect($certificate->daysUntilExpiry())->toBe(10);
    expect(Certificate::factory()->make(['expires_at' => null])->daysUntilExpiry())->toBeNull();
});

it('marks a certificate issued, renewed, and failed', function (): void {
    $certificate = Certificate::factory()->create();

    $certificate->markIssued(CarbonImmutable::now()->addDays(90));
    expect($certificate->fresh()->status)->toBe(CertificateStatus::Issued);

    $certificate->markRenewed(CarbonImmutable::now()->addDays(90));
    expect($certificate->fresh())
        ->status->toBe(CertificateStatus::Renewed)
        ->last_renewed_at->not->toBeNull();

    $certificate->markFailed('boom');
    expect($certificate->fresh())
        ->status->toBe(CertificateStatus::Failed)
        ->last_error->toBe('boom');
});

it('supports soft deletes', function (): void {
    $certificate = Certificate::factory()->create();
    $certificate->delete();

    expect(Certificate::query()->count())->toBe(0)
        ->and(Certificate::withTrashed()->count())->toBe(1);
});

it('associates a polymorphic owner', function (): void {
    $owner = Tenant::query()->create(['name' => 'Acme']);
    $certificate = Certificate::factory()->create();
    $certificate->certifiable()->associate($owner)->save();

    expect($certificate->fresh()->certifiable)->toBeInstanceOf(Tenant::class);
});
