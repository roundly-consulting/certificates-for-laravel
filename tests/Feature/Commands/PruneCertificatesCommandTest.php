<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('soft-deletes stale expired and failed records', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 12:00:00');
    $old = Certificate::factory()->expired()->create();
    $old->forceFill(['updated_at' => CarbonImmutable::now()->subDays(60)])->save();

    Certificate::factory()->issued()->create();

    CarbonImmutable::setTestNow('2026-03-01 12:00:00');

    $this->artisan('certificates:prune', ['--days' => '30'])
        ->assertExitCode(0);

    expect(Certificate::query()->count())->toBe(1)
        ->and(Certificate::withTrashed()->count())->toBe(2);
});

it('prunes by explicit status', function (): void {
    CarbonImmutable::setTestNow('2026-01-01 12:00:00');
    $cert = Certificate::factory()->issued()->create();
    $cert->forceFill(['updated_at' => CarbonImmutable::now()->subDays(60)])->save();

    CarbonImmutable::setTestNow('2026-03-01 12:00:00');

    $this->artisan('certificates:prune', ['--days' => '30', '--status' => 'issued'])
        ->assertExitCode(0);

    expect(Certificate::query()->count())->toBe(0);
});

it('runs prune through the facade', function (): void {
    $fake = Certificates::fake();

    $this->artisan('certificates:prune', ['--days' => '14'])
        ->expectsOutputToContain('Pruned 0 certificate(s).')
        ->assertExitCode(0);

    $fake->assertPruned(14);
});
