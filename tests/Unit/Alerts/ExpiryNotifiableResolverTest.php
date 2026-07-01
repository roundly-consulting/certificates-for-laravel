<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Alerts\ExpiryNotifiableResolver;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\AlertTeam;

beforeEach(function (): void {
    $this->resolver = new ExpiryNotifiableResolver;
});

it('prefers the explicit notifiable', function (): void {
    $explicit = AlertTeam::query()->create(['name' => 'explicit']);
    $owner = AlertTeam::query()->create(['name' => 'owner']);
    $cert = Certificate::factory()->issued()->create();
    $cert->certifiable()->associate($owner)->save();
    config()->set('certificates.alerts.notifiable', get_class($owner));

    expect($this->resolver->resolve($cert, $explicit)->is($explicit))->toBeTrue();
});

it('uses the configured FQCN over the certifiable owner', function (): void {
    $configured = AlertTeam::query()->create(['name' => 'configured']);
    $owner = AlertTeam::query()->create(['name' => 'owner']);
    config()->set('certificates.alerts.notifiable', get_class($configured));
    app()->instance(get_class($configured), $configured);

    $cert = Certificate::factory()->issued()->create();
    $cert->certifiable()->associate($owner)->save();

    expect($this->resolver->resolve($cert)->is($configured))->toBeTrue();
});

it('falls back to the certifiable owner', function (): void {
    config()->set('certificates.alerts.notifiable', null);
    $owner = AlertTeam::query()->create(['name' => 'owner']);
    $cert = Certificate::factory()->issued()->create();
    $cert->certifiable()->associate($owner)->save();

    expect($this->resolver->resolve($cert)->is($owner))->toBeTrue();
});

it('returns null when nothing resolves', function (): void {
    config()->set('certificates.alerts.notifiable', null);
    $cert = Certificate::factory()->issued()->create();

    expect($this->resolver->resolve($cert))->toBeNull();
});
