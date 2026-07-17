<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Alerts\AlertsServiceProvider;
use RoundlyConsulting\Alerts\Facades\Health;
use RoundlyConsulting\Certificates\CertificatesServiceProvider;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every provider certificates hard-requires, in registration order — the roundly
     * providers a host auto-discovers first, then certificates itself.
     *
     * CryptoServiceProvider was missing before this row and is not decoration: the ACME
     * client, its JWS signer, the account key handling and the certificate mapper all
     * resolve crypto out of the container. A suite that never registered it was testing
     * an environment no host runs.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            AlertsServiceProvider::class,
            CertificatesServiceProvider::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Certificates' => Certificates::class,
            'Health' => Health::class,
        ];
    }

    /**
     * Named by PROVIDER CLASS, never by filename. Alerts' migrations were previously
     * loaded from a hard-coded `vendor/roundly-consulting/alerts-for-laravel/database/
     * migrations` path — a string that silently loads nothing the moment the dependency
     * is installed anywhere but that exact location (a path repo, a different vendor
     * layout). The `LoadsProviderMigrations` concern resolves each provider to its own
     * migrations directory by reflection instead.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            AlertsServiceProvider::class,
            CertificatesServiceProvider::class,
            __DIR__.'/database/migrations',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'cache.default' => 'array',
            'mail.default' => 'array',

            'certificates.drivers.kubernetes.base_url' => 'https://k8s.test',
            'certificates.drivers.kubernetes.token' => 'test-token',
            'certificates.drivers.kubernetes.token_path' => null,
            'certificates.drivers.kubernetes.ca_path' => null,
            'certificates.drivers.kubernetes.namespace' => 'apps',
            'certificates.drivers.kubernetes.ingress.name' => 'app-ingress',
            'certificates.drivers.kubernetes.service.name' => 'app-service',
            'certificates.drivers.kubernetes.service.port' => 8080,
        ]);
    }
}
