<?php

declare(strict_types=1);

use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Certificates\Events\CertificateExpiring as CertificateExpiringEvent;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Notifications\CertificateExpiring as CertificateExpiringNotification;

beforeEach(function (): void {
    Certificate::factory()->expiring(5)->create(['domain' => 'soon.com', 'driver' => 'array']);
    Certificate::factory()->issued()->create(['domain' => 'healthy.com', 'driver' => 'array']);
    Certificate::factory()->expired()->create(['domain' => 'gone.com', 'driver' => 'array']);
});

it('fires events for expiring certificates without notifying by default', function (): void {
    Event::fake();
    Notification::fake();

    $this->artisan('certificates:check')->assertSuccessful();

    Event::assertDispatched(CertificateExpiringEvent::class, 1);
    Notification::assertNothingSent();
});

it('notifies an on-demand route when --notify is given', function (): void {
    config()->set('certificates.notifications.route', ['mail' => 'ops@example.com']);
    Notification::fake();

    $this->artisan('certificates:check --notify')->assertSuccessful();

    Notification::assertSentOnDemand(CertificateExpiringNotification::class);
});

it('notifies a configured notifiable instance', function (): void {
    config()->set('certificates.notifications.notifiable', CheckTeam::class);
    Notification::fake();

    $this->artisan('certificates:check --notify')->assertSuccessful();

    Notification::assertSentTo(new CheckTeam, CertificateExpiringNotification::class);
});

it('warns and skips when no route or notifiable is configured', function (): void {
    config()->set('certificates.notifications.route', []);
    config()->set('certificates.notifications.notifiable', null);
    Notification::fake();
    Event::fake();

    $this->artisan('certificates:check --notify')
        ->expectsOutputToContain('No notification route')
        ->assertSuccessful();

    Notification::assertNothingSent();
    Event::assertDispatched(CertificateExpiringEvent::class, 1);
});

it('respects the threshold option', function (): void {
    Event::fake();

    // A 60-day window also catches the healthy (90-day) cert? No — issued()
    // expires in 90 days, beyond 60. Lower window still only finds soon.com.
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

final class CheckTeam
{
    use Notifiable;

    public function getKey(): int
    {
        return 1;
    }

    public function routeNotificationForMail(): string
    {
        return 'team@example.com';
    }
}
