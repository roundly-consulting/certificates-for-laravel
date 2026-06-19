<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\Models\Certificate;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-01 12:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('scopes active certificates', function (): void {
    Certificate::factory()->issued()->create();
    Certificate::factory()->expired()->create();
    Certificate::factory()->failed()->create();

    expect(Certificate::query()->active()->count())->toBe(1);
});

it('scopes expired certificates', function (): void {
    Certificate::factory()->issued()->create();
    Certificate::factory()->expired()->create();

    expect(Certificate::query()->expired()->count())->toBe(1);
});

it('scopes expiring certificates within the threshold', function (): void {
    Certificate::factory()->expiring(5)->create();
    Certificate::factory()->issued()->create(); // expires in 90 days

    expect(Certificate::query()->expiring(7)->count())->toBe(1)
        ->and(Certificate::query()->expiring(120)->count())->toBe(2);
});

it('defaults the expiring window to the config threshold', function (): void {
    config()->set('certificates.renewal.threshold_days', 10);
    Certificate::factory()->expiring(5)->create();
    Certificate::factory()->expiring(30)->create();

    expect(Certificate::query()->expiring()->count())->toBe(1);
});

it('scopes by domain and driver', function (): void {
    Certificate::factory()->forDomain('a.example.com')->create(['driver' => 'kubernetes']);
    Certificate::factory()->forDomain('b.example.com')->create(['driver' => 'null']);

    expect(Certificate::query()->forDomain('a.example.com')->count())->toBe(1)
        ->and(Certificate::query()->forDriver('null')->count())->toBe(1);
});
