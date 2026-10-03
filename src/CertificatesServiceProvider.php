<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\ChallengeSolvers\DnsChallengeSolver;
use RoundlyConsulting\Certificates\ChallengeSolvers\HttpChallengeSolver;
use RoundlyConsulting\Certificates\Commands\CheckCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\IssueCertificateCommand;
use RoundlyConsulting\Certificates\Commands\ListCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\PruneCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\RenewCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\SyncCertificatesCommand;
use RoundlyConsulting\Certificates\Contracts\AcmeChallengeSolver;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Contracts\CertificateStore;
use RoundlyConsulting\Certificates\Events\CertificateExpired;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateRevoked;
use RoundlyConsulting\Certificates\Listeners\AlertOnCertificateLifecycleFailure;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;
use RoundlyConsulting\Certificates\Support\CachedStatusResolver;
use RoundlyConsulting\Certificates\Support\CertificateMapper;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use RoundlyConsulting\Certificates\Support\Settings;
use RoundlyConsulting\Certificates\Support\TlsVerification;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class CertificatesServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('certificates')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasCommands([
                IssueCertificateCommand::class,
                ListCertificatesCommand::class,
                RenewCertificatesCommand::class,
                PruneCertificatesCommand::class,
                SyncCertificatesCommand::class,
                CheckCertificatesCommand::class,
            ])
            // This package's config holds ACME account key material, CA
            // directory URLs, Kubernetes bearer tokens and the paths they are
            // mounted at. The section therefore reports *presence* and shape —
            // never a secret, a path to one, a destination, or a host topology
            // name (namespace, ingress, queue, cache store, connection).
            ->contributesToAbout(static fn (): array => [
                'Driver' => self::defaultDriver(),
                'Model' => class_basename(CertificateModel::class()),
                'Table' => self::table(),
                'Connection' => self::presence('certificates.connection', 'DEFAULT'),
                'Renewal' => self::renewal(),
                'Renewal queue' => self::presence('certificates.renewal.queue', 'DEFAULT'),
                'Status cache' => self::statusCache(),
                'Alerts' => self::alerts(),
                'Expiry check' => Config::boolean('certificates.alerts.register_check') ? 'REGISTERED' : 'OFF',
                'Alert notifiable' => self::presence('certificates.alerts.notifiable', 'OWNER'),
                'Kubernetes API' => self::presence('certificates.drivers.kubernetes.base_url', 'MISSING'),
                'Kubernetes token' => self::kubernetesToken(),
                'Kubernetes CA' => self::kubernetesCa(),
                'ACME directory' => self::presence('certificates.drivers.acme.directory', 'MISSING'),
                'ACME contact' => self::presence('certificates.drivers.acme.contact', 'MISSING'),
                'ACME account key' => self::acmeAccountKey(),
                'ACME challenge' => self::acmeChallenge(),
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(
            CertificateProviderManager::class,
            fn (Application $app): CertificateProviderManager => new CertificateProviderManager($app),
        );

        // Default provider (the active driver) for code that type-hints the contract.
        $this->app->singleton(
            CertificateProvider::class,
            fn (Application $app): CertificateProvider => $app->make(CertificateProviderManager::class)->provider(),
        );

        // Default certificate store + challenge solver, overridable by host apps.
        $this->app->singleton(CertificateStore::class, function (): CertificateStore {
            /** @var array<string, mixed> $store */
            $store = config('certificates.drivers.acme.store') ?? [];

            return new FilesystemCertificateStore(
                disk: Settings::string('certificates.drivers.acme.store.disk', $store['disk'] ?? null, 'local'),
                path: Settings::string('certificates.drivers.acme.store.path', $store['path'] ?? null, 'certificates'),
            );
        });

        $this->app->singleton(AcmeChallengeSolver::class, function (): AcmeChallengeSolver {
            /** @var array<string, mixed> $http */
            $http = config('certificates.drivers.acme.http') ?? [];

            return new HttpChallengeSolver(
                disk: Settings::string('certificates.drivers.acme.http.disk', $http['disk'] ?? null, 'local'),
                path: Settings::string('certificates.drivers.acme.http.path', $http['path'] ?? null, 'acme-challenge'),
            );
        });

        $this->app->bind(CertificateMapper::class, fn (): CertificateMapper => new CertificateMapper);

        $this->app->singleton(
            CachedStatusResolver::class,
            fn (Application $app): CachedStatusResolver => new CachedStatusResolver(
                $app->make(CertificateProviderManager::class),
            ),
        );

        $this->app->singleton(
            CertificatesManager::class,
            fn (Application $app): CertificatesManager => new CertificatesManager(
                $app,
                $app->make(CertificateProviderManager::class),
                $app->make(CachedStatusResolver::class),
            ),
        );
    }

    public function boot(): void
    {
        parent::boot();

        // The migration's key-type-aware certifiable morph is a macro, so it must
        // exist before a host runs `php artisan migrate`.
        $this->registerBlueprintMacros();

        Event::listen(
            [CertificateFailed::class, CertificateRevoked::class, CertificateExpired::class],
            AlertOnCertificateLifecycleFailure::class,
        );

        if (Config::boolean('certificates.alerts.register_check')) {
            // `via()` is not optional polish: without it the check carries the alerts
            // Check base's own null default, and `certificates.alerts.channels` — a
            // shipped, documented key — reaches nothing. A host configuring `['slack']`
            // was silently notified wherever alerts happened to default to.
            $channels = Settings::strings('certificates.alerts.channels', config('certificates.alerts.channels'), ['mail']);

            Health::check((new CertificateExpiryCheck)->via($channels));
        }
    }

    /**
     * Whether a config key holds a value (blank is not set) — never the value itself.
     */
    private static function presence(string $key, string $absent): string
    {
        $value = config($key);

        return is_string($value) && ! Settings::notSet($value) ? 'SET' : $absent;
    }

    private static function defaultDriver(): string
    {
        $driver = config('certificates.default');

        return is_string($driver) && ! Settings::notSet($driver) ? $driver : 'kubernetes';
    }

    private static function table(): string
    {
        $table = config('certificates.table');

        return is_string($table) && ! Settings::notSet($table) ? $table : 'certificates';
    }

    private static function renewal(): string
    {
        return self::valid(static fn (): string => Certificate::thresholdDays().' days before expiry');
    }

    private static function statusCache(): string
    {
        if (! Config::boolean('certificates.status_cache.enabled', true)) {
            return 'OFF';
        }

        $store = self::presence('certificates.status_cache.store', 'DEFAULT');

        return sprintf('ON (%s, store %s)', self::valid(static fn (): string => CachedStatusResolver::ttl().'s'), $store);
    }

    private static function alerts(): string
    {
        if (! Config::boolean('certificates.alerts.enabled')) {
            return 'OFF';
        }

        return sprintf(
            'ON (warn %s, critical %s)',
            self::valid(static fn (): string => CertificateExpiryCheck::configuredWarningDays().'d'),
            self::valid(static fn (): string => CertificateExpiryCheck::configuredCriticalDays().'d'),
        );
    }

    /**
     * A setting rendered through its strict reader — or `INVALID` when that reader throws,
     * so `about` reports a misconfiguration instead of dying on it (the real reads still
     * throw).
     *
     * @param  callable(): string  $render
     */
    private static function valid(callable $render): string
    {
        try {
            return $render();
        } catch (InvalidConfigurationException) {
            return 'INVALID';
        }
    }

    /**
     * The bearer token is a live cluster credential and its token_path names the
     * file it is mounted at, so only the sourcing mode is reported.
     */
    private static function kubernetesToken(): string
    {
        if (self::presence('certificates.drivers.kubernetes.token', 'MISSING') === 'SET') {
            return 'INLINE';
        }

        return self::presence('certificates.drivers.kubernetes.token_path', 'MISSING') === 'SET'
            ? 'FILE'
            : 'MISSING';
    }

    /**
     * Mirrors the driver (both read through TlsVerification): a bundle path is SET, only a
     * false-ish value (false, "0", "off", "no") is UNVERIFIED, and null, empty or a
     * true-ish value verifies against the SYSTEM bundle.
     */
    private static function kubernetesCa(): string
    {
        try {
            $verify = TlsVerification::from(config('certificates.drivers.kubernetes.ca_path'), 'certificates.drivers.kubernetes.ca_path');
        } catch (InvalidConfigurationException) {
            return 'INVALID';
        }

        return match ($verify) {
            false => 'UNVERIFIED',
            true => 'SYSTEM',
            default => 'SET',
        };
    }

    /**
     * The account key is the ACME identity itself; only its type and whether the
     * package may register it are reported — never the disk or the key path.
     */
    private static function acmeAccountKey(): string
    {
        $type = config('certificates.drivers.acme.account.key_type');
        $autoRegister = Config::boolean('certificates.drivers.acme.account.auto_register', true);

        return sprintf(
            '%s (auto-register %s)',
            is_string($type) && ! Settings::notSet($type) ? $type : 'EC',
            $autoRegister ? 'ON' : 'OFF',
        );
    }

    /**
     * The challenge answered is the solver's own type(). A custom solver is not
     * instantiated just to render `about` (and its class name is never printed): a
     * subclass of a shipped base reports that base's type, anything else "SOLVER-DEFINED".
     */
    private static function acmeChallenge(): string
    {
        $solver = config('certificates.drivers.acme.solver');

        if (! is_string($solver) || Settings::notSet($solver)) {
            return 'http-01 (solver DEFAULT)';
        }

        $type = match (true) {
            is_a($solver, DnsChallengeSolver::class, true) => 'dns-01',
            is_a($solver, HttpChallengeSolver::class, true) => 'http-01',
            default => 'SOLVER-DEFINED',
        };

        return $type.' (solver CUSTOM)';
    }
}
