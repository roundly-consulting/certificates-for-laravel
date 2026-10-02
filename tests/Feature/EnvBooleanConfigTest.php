<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Certificates\Acme\AcmeAccount;
use RoundlyConsulting\Certificates\Acme\AcmeClient;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\CertificatesServiceProvider;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Providers\AcmeProvider;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;
use RoundlyConsulting\Certificates\Support\CachedStatusResolver;
use RoundlyConsulting\Certificates\Tests\Fixtures\AlertTeam;
use RoundlyConsulting\Certificates\Tests\Fixtures\CountingStatusProvider;

/**
 * Regression: every switch was read with `(bool) env(...)` in the config file and a strict
 * `=== true` in code. env() only converts "true"/"false", so `CERTIFICATES_ALERTS=1` reached
 * the code as the STRING "1" (=== true is false: alerts silently off) and
 * `CERTIFICATES_STATUS_CACHE=off` was cast `(bool) 'off'` = true (cache silently on). Every
 * switch — behaviour and its `about` row — now reads the value as a boolean the same way.
 */
function certificatesAboutRow(string $row): string
{
    Artisan::call('about', ['--only' => 'certificates', '--json' => true]);

    /** @var array{certificates: array<string, string>} $about */
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return $about['certificates'][$row];
}

dataset('env switches', [
    '"1"' => ['1', true],
    '"on"' => ['on', true],
    '"yes"' => ['yes', true],
    '"true"' => ['true', true],
    '"0"' => ['0', false],
    '"off"' => ['off', false],
    '"no"' => ['no', false],
    '"false"' => ['false', false],
]);

it('reads the status cache switch from an env string', function (string $value, bool $on): void {
    config()->set('certificates.status_cache.enabled', $value);
    $provider = new CountingStatusProvider;
    app(CertificateProviderManager::class)->extend('array', fn (): CertificateProvider => $provider);

    $resolver = new CachedStatusResolver(app(CertificateProviderManager::class));
    $resolver->resolve('array', 'tls-app', 'app.com');
    $resolver->resolve('array', 'tls-app', 'app.com');

    expect($provider->calls)->toBe($on ? 1 : 2)
        ->and(certificatesAboutRow('status_cache'))->toStartWith($on ? 'ON' : 'OFF');
})->with('env switches');

it('reads the alerts switch from an env string', function (string $value, bool $on): void {
    $team = AlertTeam::query()->create(['name' => 'ops']);
    config()->set('certificates.alerts.enabled', $value);
    config()->set('certificates.alerts.notifiable', get_class($team));
    app()->instance(get_class($team), $team);

    Certificate::factory()->issued()->create(['domain' => 'rev.com', 'driver' => 'array']);
    Certificates::revoke('rev.com', 'key compromise');

    expect($team->alerts()->count())->toBe($on ? 1 : 0)
        ->and(certificatesAboutRow('alerts'))->toStartWith($on ? 'ON' : 'OFF');
})->with('env switches');

it('alerts from certificates:check on an env-string alerts switch', function (string $value, bool $on): void {
    $team = AlertTeam::query()->create(['name' => 'ops']);
    config()->set('certificates.alerts.enabled', $value);
    config()->set('certificates.alerts.notifiable', get_class($team));
    app()->instance(get_class($team), $team);
    Certificate::factory()->expiring(5)->create(['domain' => 'soon.com', 'driver' => 'array']);

    $this->artisan('certificates:check')->assertSuccessful();

    expect($team->alerts()->count())->toBe($on ? 1 : 0);
})->with('env switches');

it('reads the register_check switch from an env string', function (string $value, bool $on): void {
    config()->set('certificates.alerts.register_check', $value);

    $provider = new CertificatesServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    expect(Health::find('certificate_expiry') !== null)->toBe($on)
        ->and(certificatesAboutRow('expiry_check'))->toBe($on ? 'REGISTERED' : 'OFF');
})->with('env switches');

it('reads the acme auto_register switch from an env string', function (string $value, bool $on): void {
    config()->set('certificates.drivers.acme.account.auto_register', $value);
    app()->forgetInstance(CertificateProviderManager::class);

    $client = (new ReflectionProperty(AcmeProvider::class, 'client'))
        ->getValue(app(CertificateProviderManager::class)->provider('acme'));
    $account = (new ReflectionProperty(AcmeClient::class, 'account'))->getValue($client);

    expect((new ReflectionProperty(AcmeAccount::class, 'autoRegister'))->getValue($account))->toBe($on)
        ->and(certificatesAboutRow('acme_account_key'))->toBe('EC (auto-register '.($on ? 'ON' : 'OFF').')');
})->with('env switches');

it('reads the filesystem self_signed switch from an env string', function (string $value, bool $on): void {
    Storage::fake('local');
    config()->set('certificates.drivers.filesystem.self_signed', $value);

    $issue = fn () => Certificates::for('fs.example.com')->using('filesystem')->issue();

    if ($on) {
        expect($issue()->expires_at)->not->toBeNull();
    } else {
        expect($issue)->toThrow(Exception::class, 'not a CA');
    }
})->with('env switches');

it('reads boolean words in the kubernetes ca_path as a verification switch', function (string $value, bool $verify): void {
    config()->set('certificates.drivers.kubernetes.ca_path', $value);
    app()->forgetInstance(CertificateProviderManager::class);

    $provider = app(CertificateProviderManager::class)->provider('kubernetes');

    expect((new ReflectionProperty(KubernetesProvider::class, 'verify'))->getValue($provider))->toBe($verify)
        ->and(certificatesAboutRow('kubernetes_ca'))->toBe($verify ? 'SYSTEM' : 'UNVERIFIED');
})->with('env switches');

it('reads boolean words in the acme verify setting as a verification switch', function (string $value, bool $verify): void {
    config()->set('certificates.drivers.acme.verify', $value);
    app()->forgetInstance(CertificateProviderManager::class);

    $client = (new ReflectionProperty(AcmeProvider::class, 'client'))
        ->getValue(app(CertificateProviderManager::class)->provider('acme'));

    expect((new ReflectionProperty(AcmeClient::class, 'verify'))->getValue($client))->toBe($verify);
})->with('env switches');

/**
 * End to end through the shipped config file: a `(bool) env(...)` cast there would turn
 * "off" into true before any code could read it.
 */
it('honours env-string switches through the shipped config file', function (string $value, bool $on): void {
    $keys = [
        'CERTIFICATES_STATUS_CACHE',
        'CERTIFICATES_ALERTS',
        'CERTIFICATES_ALERTS_REGISTER_CHECK',
        'CERTIFICATES_ACME_AUTO_REGISTER',
        'CERTIFICATES_FS_SELF_SIGNED',
    ];

    foreach ($keys as $key) {
        $_SERVER[$key] = $value;
    }

    try {
        config()->set('certificates', require __DIR__.'/../../config/certificates.php');
    } finally {
        foreach ($keys as $key) {
            unset($_SERVER[$key]);
        }
    }

    $state = $on ? 'ON' : 'OFF';

    expect(certificatesAboutRow('status_cache'))->toStartWith($state)
        ->and(certificatesAboutRow('alerts'))->toStartWith($state)
        ->and(certificatesAboutRow('expiry_check'))->toBe($on ? 'REGISTERED' : 'OFF')
        ->and(certificatesAboutRow('acme_account_key'))->toBe("EC (auto-register {$state})");
})->with('env switches');
