<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\Certificates\CertificatesServiceProvider;
use RoundlyConsulting\Certificates\Facades\Certificates;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
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
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');

        $app['config']->set('certificates.providers.kubernetes.base_url', 'https://k8s.test');
        $app['config']->set('certificates.providers.kubernetes.token', 'test-token');
        $app['config']->set('certificates.providers.kubernetes.token_path', null);
        $app['config']->set('certificates.providers.kubernetes.ca_path', null);
        $app['config']->set('certificates.providers.kubernetes.namespace', 'apps');
        $app['config']->set('certificates.providers.kubernetes.ingress.name', 'app-ingress');
        $app['config']->set('certificates.providers.kubernetes.service.name', 'app-service');
        $app['config']->set('certificates.providers.kubernetes.service.port', 8080);
    }
}
