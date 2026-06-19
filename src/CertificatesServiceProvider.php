<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;

final class CertificatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/certificates.php', 'certificates');

        $this->app->singleton(CertificateProvider::class, $this->resolveProvider(...));

        $this->app->singleton(
            CertificateService::class,
            fn (Application $app): CertificateService => new CertificateService(
                $app->make(CertificateProvider::class),
            ),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/certificates.php' => config_path('certificates.php'),
            ], 'certificates-config');
        }
    }

    private function resolveProvider(Application $app): CertificateProvider
    {
        $repository = $app->make('config');

        /** @var array<string, mixed> $config */
        $config = $repository->get('certificates.providers.kubernetes', []);

        $verify = $config['ca_path'] ?? true;

        return new KubernetesProvider(
            baseUrl: (string) ($config['base_url'] ?? ''),
            token: $this->resolveToken($config),
            namespace: (string) ($config['namespace'] ?? 'default'),
            ingressName: (string) ($config['ingress']['name'] ?? ''),
            serviceName: isset($config['service']['name']) ? (string) $config['service']['name'] : null,
            servicePort: (int) ($config['service']['port'] ?? 80),
            issuer: (string) ($config['issuer'] ?? 'letsencrypt'),
            issuerKind: (string) ($config['issuer_kind'] ?? 'ClusterIssuer'),
            ingressClass: (string) ($config['ingress']['class'] ?? 'nginx'),
            verify: is_string($verify) ? $verify : (bool) $verify,
        );
    }

    /**
     * Read the bearer token, preferring an inline value and falling back to a
     * mounted service-account token file when only a path is configured.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveToken(array $config): string
    {
        if (! empty($config['token'])) {
            return (string) $config['token'];
        }

        $path = $config['token_path'] ?? null;

        if (is_string($path) && is_readable($path)) {
            return trim((string) file_get_contents($path));
        }

        return '';
    }
}
