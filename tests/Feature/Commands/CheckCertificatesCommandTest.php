<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Certificates\Events\CertificateExpiring as CertificateExpiringEvent;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\AlertTeam;

beforeEach(function (): void {
    Certificate::factory()->expiring(5)->create(['domain' => 'soon.com', 'driver' => 'array']);
    Certificate::factory()->issued()->create(['domain' => 'healthy.com', 'driver' => 'array']);
    Certificate::factory()->expired()->create(['domain' => 'gone.com', 'driver' => 'array']);
});

it('fires events for expiring certificates without alerting by default', function (): void {
    Event::fake();
    $fake = Health::fake();

    $this->artisan('certificates:check')->assertSuccessful();

    Event::assertDispatched(CertificateExpiringEvent::class, 1);
    $fake->assertNothingAlerted();
});

it('routes an alert through the engine per expiring cert when --alert is given', function (): void {
    $team = AlertTeam::query()->create(['name' => 'ops', 'email' => 'ops@example.com']);
    config()->set('certificates.alerts.notifiable', get_class($team));
    $this->app->instance(get_class($team), $team);

    $this->artisan('certificates:check --alert')->assertSuccessful();

    expect(Alert::query()->where('health_check_id', '!=', null)->count())->toBe(1)
        ->and($team->alerts()->count())->toBe(1);
});

it('resolves the certifiable owner as the alert notifiable', function (): void {
    $team = AlertTeam::query()->create(['name' => 'team']);
    $cert = Certificate::factory()->expiring(3)->create(['domain' => 'owned.com', 'driver' => 'array']);
    $cert->certifiable()->associate($team)->save();

    config()->set('certificates.alerts.enabled', true);

    $this->artisan('certificates:check --threshold=4')->assertSuccessful();

    expect($team->alerts()->count())->toBe(1);
});

it('warns and skips a cert with no resolvable notifiable', function (): void {
    config()->set('certificates.alerts.notifiable', null);

    $this->artisan('certificates:check --alert')
        ->expectsOutputToContain('No alert notifiable resolved')
        ->assertSuccessful();

    expect(Alert::query()->count())->toBe(0);
});

it('does not re-open a second alert on a re-run within the throttle window', function (): void {
    $team = AlertTeam::query()->create(['name' => 'ops']);
    config()->set('certificates.alerts.notifiable', get_class($team));
    $this->app->instance(get_class($team), $team);

    $this->artisan('certificates:check --alert')->assertSuccessful();
    $this->artisan('certificates:check --alert')->assertSuccessful();

    expect($team->alerts()->whereNull('recovered_at')->count())->toBe(1);
});

it('respects the threshold option', function (): void {
    Event::fake();

    $this->artisan('certificates:check --threshold=10')->assertSuccessful();

    Event::assertDispatched(CertificateExpiringEvent::class, 1);
});

it('filters by driver', function (): void {
    Certificate::factory()->expiring(3)->create(['domain' => 'k8s.com', 'driver' => 'kubernetes']);
    Event::fake();

    $this->artisan('certificates:check --driver=kubernetes')->assertSuccessful();

    Event::assertDispatched(CertificateExpiringEvent::class, 1);
});

it('reports when nothing is expiring', function (): void {
    Certificate::query()->delete();

    $this->artisan('certificates:check')
        ->expectsOutputToContain('No certificates are expiring')
        ->assertSuccessful();
});
