<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\Events\CertificateExpired;
use RoundlyConsulting\Certificates\Events\CertificateRevoked;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\AlertTeam;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

it('dispatches CertificateRevoked when a certificate is marked revoked', function (): void {
    Event::fake([CertificateRevoked::class]);
    $cert = Certificate::factory()->issued()->create(['domain' => 'rev.com', 'driver' => 'array']);

    $cert->markRevoked('key compromise');

    expect($cert->fresh()->status->value)->toBe('revoked');
    Event::assertDispatched(CertificateRevoked::class, fn (CertificateRevoked $e): bool => $e->certificate->is($cert) && $e->reason === 'key compromise');
});

it('dispatches CertificateExpired when a certificate is marked expired', function (): void {
    Event::fake([CertificateExpired::class]);
    $cert = Certificate::factory()->issued()->create(['domain' => 'exp.com', 'driver' => 'array']);

    $cert->markExpired();

    expect($cert->fresh()->status->value)->toBe('expired');
    Event::assertDispatched(CertificateExpired::class);
});

it('opens an alert on revoke when alerts are enabled', function (): void {
    $team = AlertTeam::query()->create(['name' => 'ops']);
    config()->set('certificates.alerts.enabled', true);
    config()->set('certificates.alerts.notifiable', get_class($team));
    app()->instance(get_class($team), $team);

    Certificate::factory()->issued()->create(['domain' => 'rev.com', 'driver' => 'array']);
    Certificates::revoke('rev.com', 'key compromise');

    expect($team->alerts()->whereNull('recovered_at')->count())->toBe(1);
});

it('opens an alert on a failure event via the certifiable owner', function (): void {
    $team = AlertTeam::query()->create(['name' => 'owner']);
    config()->set('certificates.alerts.enabled', true);

    $cert = Certificate::factory()->issued()->create(['domain' => 'fail.com', 'driver' => 'array']);
    $cert->certifiable()->associate($team)->save();

    Certificates::expire($cert);

    expect($team->alerts()->count())->toBe(1);
});

it('quietly skips a lifecycle alert when no notifiable resolves', function (): void {
    config()->set('certificates.alerts.enabled', true);
    config()->set('certificates.alerts.notifiable', null);

    $cert = Certificate::factory()->issued()->create(['domain' => 'orphan.com', 'driver' => 'array']);

    $cert->markRevoked();

    expect($cert->fresh()->status->value)->toBe('revoked');
});

it('does not alert on lifecycle events when alerts are disabled', function (): void {
    $team = AlertTeam::query()->create(['name' => 'ops']);
    config()->set('certificates.alerts.enabled', false);
    config()->set('certificates.alerts.notifiable', get_class($team));
    app()->instance(get_class($team), $team);

    $cert = Certificate::factory()->issued()->create(['domain' => 'rev.com', 'driver' => 'array']);
    $cert->markRevoked();

    expect($team->alerts()->count())->toBe(0);
});

it('names the setting when the configured notifiable class is not bound to a stored model', function (): void {
    config()->set('certificates.alerts.enabled', true);
    config()->set('certificates.alerts.notifiable', AlertTeam::class);
    Certificate::factory()->issued()->create(['domain' => 'rev.com', 'driver' => 'array']);

    // Before: a NOT NULL violation on health_checks.notifiable_id from inside the listener.
    expect(fn () => Certificates::revoke('rev.com', 'key compromise'))
        ->toThrow(InvalidConfigurationException::class, 'certificates.alerts.notifiable');
});
