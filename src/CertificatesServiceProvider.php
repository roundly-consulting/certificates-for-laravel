<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Certificates\Commands\IssueCertificateCommand;
use RoundlyConsulting\Certificates\Commands\ListCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\PruneCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\RenewCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\SyncCertificatesCommand;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;

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

        $this->app->singleton(
            CertificateService::class,
            fn (Application $app): CertificateService => new CertificateService(
                $app->make(CertificateManager::class),
                $app->make(Actions\IssueCertificateAction::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'certificates');

        if ($this->app->runningInConsole()) {
            $this->commands([
                IssueCertificateCommand::class,
                ListCertificatesCommand::class,
                RenewCertificatesCommand::class,
                PruneCertificatesCommand::class,
                SyncCertificatesCommand::class,
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
