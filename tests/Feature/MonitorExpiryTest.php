<?php

declare(strict_types=1);

use RoundlyConsulting\Alerts\HealthCheck;
use RoundlyConsulting\Alerts\Support\PendingScheduledCheck;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\AlertTeam;

it('returns a pending scheduled check bound to the explicit notifiable', function (): void {
    $cert = Certificate::factory()->issued()->create(['domain' => 'app.com', 'driver' => 'array']);
    $team = AlertTeam::query()->create(['name' => 'ops']);

    $pending = Certificates::monitorExpiry($cert, $team);

    expect($pending)->toBeInstanceOf(PendingScheduledCheck::class);

    $row = $pending->daily()->failAfter(2)->save();

    expect($row)->toBeInstanceOf(HealthCheck::class)
        ->and($row->health_check)->toBe('certificate_expiry')
        ->and($row->notifiable_type)->toBe($team->getMorphClass())
        ->and($row->notifiable_id)->toBe($team->getKey())
        ->and($row->meta['certificate_id'])->toBe($cert->id)
        ->and($row->frequency)->toBe('@daily');
});

it('resolves the configured notifiable FQCN when none is passed', function (): void {
    $team = AlertTeam::query()->create(['name' => 'ops']);
    config()->set('certificates.alerts.notifiable', get_class($team));
    app()->instance(get_class($team), $team);

    $cert = Certificate::factory()->issued()->create(['domain' => 'cfg.com', 'driver' => 'array']);

    $row = Certificates::monitorExpiry($cert)->save();

    expect($row->notifiable_id)->toBe($team->getKey());
});

it('falls back to the certifiable owner', function (): void {
    $team = AlertTeam::query()->create(['name' => 'owner']);
    $cert = Certificate::factory()->issued()->create(['domain' => 'own.com', 'driver' => 'array']);
    $cert->certifiable()->associate($team)->save();

    $row = Certificates::monitorExpiry($cert)->save();

    expect($row->notifiable_type)->toBe($team->getMorphClass())
        ->and($row->notifiable_id)->toBe($team->getKey());
});

it('throws when no notifiable can be resolved', function (): void {
    config()->set('certificates.alerts.notifiable', null);
    $cert = Certificate::factory()->issued()->create(['domain' => 'orphan.com', 'driver' => 'array']);

    Certificates::monitorExpiry($cert);
})->throws(CertificateException::class);
