<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Exceptions\UnknownProviderException;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;
use RoundlyConsulting\Certificates\Providers\NullProvider;

/**
 * @method CertificateProvider driver(?string $driver = null)
 */
final class CertificateManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('certificates.default', 'kubernetes');
    }

    /**
     * Typed accessor over driver() that throws a package exception for
     * unknown drivers instead of the framework's InvalidArgumentException.
     */
    public function provider(?string $name = null): CertificateProvider
    {
        try {
            return $this->driver($name);
        } catch (InvalidArgumentException) {
            throw UnknownProviderException::driver($name ?? $this->getDefaultDriver());
        }
    }

    public function createKubernetesDriver(): CertificateProvider
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get('certificates.drivers.kubernetes')
            ?? $this->config->get('certificates.providers.kubernetes', []);

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

    public function createNullDriver(): CertificateProvider
    {
        return new NullProvider;
    }

    public function createArrayDriver(): CertificateProvider
    {
        return new ArrayProvider;
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
