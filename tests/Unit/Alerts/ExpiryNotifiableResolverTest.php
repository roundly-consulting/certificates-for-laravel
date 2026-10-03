<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Alerts\ExpiryNotifiableResolver;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\AlertTeam;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

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

it('refuses a configured notifiable that resolves to no model (strict config)', function (mixed $configured): void {
    config()->set('certificates.alerts.notifiable', $configured);
    app()->instance('not-a-model', new stdClass);
    $cert = Certificate::factory()->issued()->create();

    expect(fn () => $this->resolver->resolve($cert))
        ->toThrow(InvalidConfigurationException::class, 'certificates.alerts.notifiable');
})->with([
    'not a model' => ['not-a-model'],
    'not a string' => [['App\\Models\\Team']],
]);
