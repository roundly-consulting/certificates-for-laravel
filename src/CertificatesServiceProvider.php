<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
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
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;
use RoundlyConsulting\Certificates\Support\CachedStatusResolver;
use RoundlyConsulting\Certificates\Support\X509Parser;

final class CertificatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/certificates.php', 'certificates');

        $this->app->singleton(
            CertificateManager::class,
            fn (Application $app): CertificateManager => new CertificateManager($app),
        );

        // Default provider (the active driver) for code that type-hints the contract.
        $this->app->singleton(
            CertificateProvider::class,
            fn (Application $app): CertificateProvider => $app->make(CertificateManager::class)->provider(),
        );

        // Default certificate store + challenge solver, overridable by host apps.
        $this->app->singleton(CertificateStore::class, function (): CertificateStore {
            /** @var array<string, mixed> $config */
            $config = config('certificates.drivers.acme.store', []);

            return new FilesystemCertificateStore(
                disk: (string) ($config['disk'] ?? 'local'),
                path: (string) ($config['path'] ?? 'certificates'),
            );
        });

        $this->app->singleton(AcmeChallengeSolver::class, function (): AcmeChallengeSolver {
            /** @var array<string, mixed> $config */
            $config = config('certificates.drivers.acme.http', []);

            return new HttpChallengeSolver(
                disk: (string) ($config['disk'] ?? 'local'),
                path: (string) ($config['path'] ?? 'acme-challenge'),
            );
        });

        $this->app->bind(X509Parser::class, fn (): X509Parser => new X509Parser);

        $this->app->singleton(
            CachedStatusResolver::class,
            fn (Application $app): CachedStatusResolver => new CachedStatusResolver(
                $app->make(CertificateManager::class),
            ),
        );

        $this->app->singleton(
            CertificateService::class,
            fn (Application $app): CertificateService => new CertificateService(
                $app->make(CertificateManager::class),
                $app->make(Actions\IssueCertificateAction::class),
                $app->make(CachedStatusResolver::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'certificates');

        Event::listen(
            [CertificateFailed::class, CertificateRevoked::class, CertificateExpired::class],
            AlertOnCertificateLifecycleFailure::class,
        );

        if (config('certificates.alerts.register_check', false) === true) {
            Health::check(new CertificateExpiryCheck);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                IssueCertificateCommand::class,
                ListCertificatesCommand::class,
                RenewCertificatesCommand::class,
                PruneCertificatesCommand::class,
                SyncCertificatesCommand::class,
                CheckCertificatesCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/certificates.php' => config_path('certificates.php'),
            ], 'certificates-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'certificates-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/certificates'),
            ], 'certificates-translations');
        }
    }
}
