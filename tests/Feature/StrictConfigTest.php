<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\CertificatesServiceProvider;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CachedStatusResolver;
use RoundlyConsulting\Certificates\Support\CertificateName;
use RoundlyConsulting\Certificates\Support\ProvisioningLock;
use RoundlyConsulting\Certificates\Support\Settings;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 * Every non-boolean setting is read strictly: a key that is not set (absent, null or
 * blank — a host's `KEY=`) takes its default, and a present value of the wrong shape
 * throws naming the key — `'five'` never becomes a 0-day renewal threshold, a mistyped
 * string never reads as the default.
 */

it('hands raw env integers to the strict readers (strict config)', function (string $env, string $path): void {
    $_SERVER[$env] = 'five';

    try {
        /** @var array<string, mixed> $config */
        $config = require __DIR__.'/../../config/certificates.php';
    } finally {
        unset($_SERVER[$env]);
    }

    expect(data_get($config, $path))->toBe('five');
})->with([
    'threshold days' => ['CERTIFICATES_RENEW_THRESHOLD_DAYS', 'renewal.threshold_days'],
    'lock seconds' => ['CERTIFICATES_LOCK_SECONDS', 'lock.locked_for_seconds'],
    'status cache ttl' => ['CERTIFICATES_STATUS_CACHE_TTL', 'status_cache.ttl'],
    'warning days' => ['CERTIFICATES_ALERTS_WARNING_DAYS', 'alerts.thresholds.warning_days'],
    'critical days' => ['CERTIFICATES_ALERTS_CRITICAL_DAYS', 'alerts.thresholds.critical_days'],
    'service port' => ['CERTIFICATES_K8S_SERVICE_PORT', 'drivers.kubernetes.service.port'],
    'poll attempts' => ['CERTIFICATES_ACME_POLL_ATTEMPTS', 'drivers.acme.poll.attempts'],
    'poll seconds' => ['CERTIFICATES_ACME_POLL_SECONDS', 'drivers.acme.poll.seconds'],
    'self-signed days' => ['CERTIFICATES_FS_SELF_SIGNED_DAYS', 'drivers.filesystem.self_signed_days'],
]);

/**
 * The read path behind each strictly-read setting, by name (datasets hold names, not
 * closures, so nothing is read before the test sets the config).
 */
function strictCertificatesRead(string $read): mixed
{
    return match ($read) {
        'threshold' => Certificate::thresholdDays(),
        'expiring' => Certificate::query()->expiring()->get(),
        'status ttl' => CachedStatusResolver::ttl(),
        'status store' => app(CachedStatusResolver::class)->forget('array', 'x'),
        'lock' => ProvisioningLock::for('x'),
        'warning' => CertificateExpiryCheck::configuredWarningDays(),
        'critical' => CertificateExpiryCheck::configuredCriticalDays(),
        'table' => (new Certificate)->getTable(),
        'connection' => (new Certificate)->getConnectionName(),
        'manager' => new CertificatesManager(app(), app(CertificateProviderManager::class)),
        'name' => CertificateName::for('example.com'),
        'job' => new RenewCertificateJob(1),
    };
}

/**
 * One `php artisan about` row for this package, as JSON.
 */
function strictCertificatesAboutRow(string $row): string
{
    Artisan::call('about', ['--only' => 'certificates', '--json' => true]);

    /** @var array{certificates: array<string, string>} $about */
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return $about['certificates'][$row];
}

it('refuses a junk or out-of-range integer instead of reading it as 0 (strict config)', function (string $key, mixed $junk, string $read): void {
    config()->set($key, $junk);

    expect(fn (): mixed => strictCertificatesRead($read))->toThrow(InvalidConfigurationException::class, "[{$key}]");
})->with([
    'threshold word' => ['certificates.renewal.threshold_days', 'five', 'threshold'],
    'threshold zero' => ['certificates.renewal.threshold_days', 0, 'expiring'],
    'status ttl float string' => ['certificates.status_cache.ttl', '1.5', 'status ttl'],
    'lock seconds zero' => ['certificates.lock.locked_for_seconds', '0', 'lock'],
    'warning days junk' => ['certificates.alerts.thresholds.warning_days', 'month', 'warning'],
    'critical days negative' => ['certificates.alerts.thresholds.critical_days', -1, 'critical'],
]);

it('reads canonical integer strings and absent defaults (strict config)', function (): void {
    config()->set('certificates.renewal.threshold_days', '14');
    config()->set('certificates.status_cache.ttl', null);
    config()->set('certificates.alerts.thresholds.warning_days', ' 45 ');
    config()->set('certificates.alerts.thresholds.critical_days', '0');

    expect(Certificate::thresholdDays())->toBe(14)
        ->and(CachedStatusResolver::ttl())->toBe(300)
        ->and(CertificateExpiryCheck::configuredWarningDays())->toBe(45)
        ->and(CertificateExpiryCheck::configuredCriticalDays())->toBe(0);
});

it('refuses junk driver settings when the driver is built (strict config)', function (string $driver, string $key, mixed $junk): void {
    Storage::fake('local');
    config()->set($key, $junk);
    app()->forgetInstance(CertificateProviderManager::class);

    expect(fn () => app(CertificateProviderManager::class)->provider($driver))
        ->toThrow(InvalidConfigurationException::class, "[{$key}]");
})->with([
    'service port out of range' => ['kubernetes', 'certificates.drivers.kubernetes.service.port', 70000],
    'service port junk' => ['kubernetes', 'certificates.drivers.kubernetes.service.port', 'http'],
    'namespace not a string' => ['kubernetes', 'certificates.drivers.kubernetes.namespace', ['default']],
    'token not a string' => ['kubernetes', 'certificates.drivers.kubernetes.token', ['t']],
    'ca path not a path or switch' => ['kubernetes', 'certificates.drivers.kubernetes.ca_path', ['x']],
    'poll attempts zero' => ['acme', 'certificates.drivers.acme.poll.attempts', 0],
    'poll seconds junk' => ['acme', 'certificates.drivers.acme.poll.seconds', '2s'],
    'acme key type typo' => ['acme', 'certificates.drivers.acme.account.key_type', 'ECDSA'],
    'acme directory not a string' => ['acme', 'certificates.drivers.acme.directory', 42],
    'acme contact not a string' => ['acme', 'certificates.drivers.acme.contact', ['ops@example.com']],
    'acme solver not a solver' => ['acme', 'certificates.drivers.acme.solver', stdClass::class],
    'filesystem days junk' => ['filesystem', 'certificates.drivers.filesystem.self_signed_days', 'ninety'],
    'filesystem disk not a string' => ['filesystem', 'certificates.drivers.filesystem.disk', 7],
    'driver section not an array' => ['filesystem', 'certificates.drivers.filesystem', 'local'],
]);

it('builds a driver whose blank settings take their defaults (strict config)', function (string $driver, string $key): void {
    Storage::fake('local');
    config()->set($key, '');
    app()->forgetInstance(CertificateProviderManager::class);

    expect(app(CertificateProviderManager::class)->provider($driver))->not->toBeNull();
})->with([
    'namespace' => ['kubernetes', 'certificates.drivers.kubernetes.namespace'],
    'service port' => ['kubernetes', 'certificates.drivers.kubernetes.service.port'],
    'poll attempts' => ['acme', 'certificates.drivers.acme.poll.attempts'],
    'acme key type' => ['acme', 'certificates.drivers.acme.account.key_type'],
    'acme directory' => ['acme', 'certificates.drivers.acme.directory'],
    'acme solver' => ['acme', 'certificates.drivers.acme.solver'],
    'filesystem disk' => ['filesystem', 'certificates.drivers.filesystem.disk'],
    'filesystem days' => ['filesystem', 'certificates.drivers.filesystem.self_signed_days'],
]);

it('reads a blank default driver as kubernetes (strict config)', function (): void {
    config()->set('certificates.default', ' ');

    expect(app(CertificateProviderManager::class)->getDefaultDriver())->toBe('kubernetes')
        ->and(strictCertificatesAboutRow('driver'))->toBe('kubernetes');
});

it('refuses a non-string default driver (strict config)', function (): void {
    config()->set('certificates.default', ['kubernetes']);

    expect(fn () => app(CertificateProviderManager::class)->getDefaultDriver())
        ->toThrow(InvalidConfigurationException::class, '[certificates.default]');
});

it('refuses mistyped string settings instead of reading the default (strict config)', function (string $key, mixed $junk, string $read): void {
    config()->set($key, $junk);

    expect(fn (): mixed => strictCertificatesRead($read))->toThrow(InvalidConfigurationException::class, "[{$key}]");
})->with([
    'table not a string' => ['certificates.table', ['certs'], 'table'],
    'connection not a string' => ['certificates.connection', 1, 'connection'],
    'manager connection not a string' => ['certificates.connection', ['db'], 'manager'],
    'lock name not a string' => ['certificates.lock.name', 5, 'lock'],
    'name prefix not a string' => ['certificates.name_prefix', false, 'name'],
    'renewal queue not a string' => ['certificates.renewal.queue', ['renewals'], 'job'],
    'status store not a string' => ['certificates.status_cache.store', 5, 'status store'],
]);

it('reads blank string and integer settings as not set, so the defaults apply (strict config)', function (string $blank): void {
    config()->set('certificates.table', $blank);
    config()->set('certificates.connection', $blank);
    config()->set('certificates.lock.name', $blank);
    config()->set('certificates.lock.locked_for_seconds', $blank);
    config()->set('certificates.renewal.threshold_days', $blank);
    config()->set('certificates.status_cache.ttl', $blank);
    config()->set('certificates.alerts.thresholds.warning_days', $blank);
    config()->set('certificates.alerts.thresholds.critical_days', $blank);

    expect((new Certificate)->getTable())->toBe('certificates')
        ->and((new Certificate)->getConnectionName())->toBeNull()
        ->and(Certificate::thresholdDays())->toBe(21)
        ->and(CachedStatusResolver::ttl())->toBe(300)
        ->and(CertificateExpiryCheck::configuredWarningDays())->toBe(30)
        ->and(CertificateExpiryCheck::configuredCriticalDays())->toBe(7)
        ->and(ProvisioningLock::for('x')->get())->toBeTrue()
        ->and(Cache::lock('certificates:generate:x')->get())->toBeFalse();
})->with(['empty' => [''], 'whitespace' => ['  ']]);

it('accepts an empty name prefix (strict config)', function (): void {
    config()->set('certificates.name_prefix', '');

    expect(CertificateName::for('example.com'))->toBe('example-com');
});

it('refuses a mistyped alert channel list when the check registers (strict config)', function (mixed $channels): void {
    config()->set('certificates.alerts.register_check', true);
    config()->set('certificates.alerts.channels', $channels);

    $provider = new CertificatesServiceProvider($this->app);
    $provider->register();

    expect(fn () => $provider->boot())->toThrow(InvalidConfigurationException::class, '[certificates.alerts.channels]');
})->with([
    'a string' => ['mail'],
    'a blank entry' => [['mail', '']],
    'a non-string entry' => [[1]],
]);

it('reads a blank alert channel list as not set, so mail applies (strict config)', function (): void {
    config()->set('certificates.alerts.register_check', true);
    config()->set('certificates.alerts.channels', '');

    $provider = new CertificatesServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    expect(Settings::strings('certificates.alerts.channels', '', ['mail']))->toBe(['mail'])
        ->and(Settings::strings('certificates.alerts.channels', null, ['mail']))->toBe(['mail']);
});

it('renders junk integers as INVALID in about rather than throwing (strict config)', function (string $key, string $row): void {
    config()->set('certificates.alerts.enabled', true);
    config()->set($key, 'five');

    expect(strictCertificatesAboutRow($row))->toContain('INVALID');
})->with([
    'renewal' => ['certificates.renewal.threshold_days', 'renewal'],
    'status cache' => ['certificates.status_cache.ttl', 'status_cache'],
    'warning days' => ['certificates.alerts.thresholds.warning_days', 'alerts'],
    'critical days' => ['certificates.alerts.thresholds.critical_days', 'alerts'],
]);

it('renders a non-path, non-switch kubernetes ca as INVALID in about (strict config)', function (): void {
    config()->set('certificates.drivers.kubernetes.ca_path', ['x']);

    expect(strictCertificatesAboutRow('kubernetes_ca'))->toBe('INVALID');
});
