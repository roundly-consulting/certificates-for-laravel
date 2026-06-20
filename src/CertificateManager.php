<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use RoundlyConsulting\Certificates\Acme\AcmeAccount;
use RoundlyConsulting\Certificates\Acme\AcmeClient;
use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\Acme\Jws;
use RoundlyConsulting\Certificates\ChallengeSolvers\HttpChallengeSolver;
use RoundlyConsulting\Certificates\Contracts\AcmeChallengeSolver;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Exceptions\UnknownProviderException;
use RoundlyConsulting\Certificates\Providers\AcmeProvider;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;
use RoundlyConsulting\Certificates\Providers\LocalFilesystemProvider;
use RoundlyConsulting\Certificates\Providers\NullProvider;
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;
use RoundlyConsulting\Certificates\Support\X509Parser;

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

    public function createFilesystemDriver(): CertificateProvider
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get('certificates.drivers.filesystem', []);

        $store = new FilesystemCertificateStore(
            disk: (string) ($config['disk'] ?? 'local'),
            path: (string) ($config['path'] ?? 'certificates'),
        );

        $selfSigned = (bool) ($config['self_signed'] ?? false);

        return new LocalFilesystemProvider(
            store: $store,
            parser: new X509Parser,
            csr: $selfSigned ? new Csr : null,
            selfSignedDays: (int) ($config['self_signed_days'] ?? 90),
        );
    }

    public function createAcmeDriver(): CertificateProvider
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get('certificates.drivers.acme', []);

        /** @var array<string, mixed> $accountConfig */
        $accountConfig = $config['account'] ?? [];
        /** @var array<string, mixed> $storeConfig */
        $storeConfig = $config['store'] ?? [];
        /** @var array<string, mixed> $pollConfig */
        $pollConfig = $config['poll'] ?? [];

        $jws = new Jws;

        $account = new AcmeAccount(
            jws: $jws,
            disk: (string) ($accountConfig['disk'] ?? 'local'),
            keyPath: (string) ($accountConfig['key_path'] ?? 'acme/account.pem'),
            keyType: (string) ($accountConfig['key_type'] ?? 'EC'),
            autoRegister: (bool) ($accountConfig['auto_register'] ?? true),
        );

        $verify = $config['verify'] ?? true;

        $client = new AcmeClient(
            jws: $jws,
            account: $account,
            directoryUrl: (string) ($config['directory'] ?? 'https://acme-v02.api.letsencrypt.org/directory'),
            contact: isset($config['contact']) ? (string) $config['contact'] : null,
            verify: is_string($verify) ? $verify : (bool) $verify,
            challengeType: (string) ($config['challenge_type'] ?? 'http-01'),
        );

        $store = new FilesystemCertificateStore(
            disk: (string) ($storeConfig['disk'] ?? 'local'),
            path: (string) ($storeConfig['path'] ?? 'certificates'),
        );

        return new AcmeProvider(
            client: $client,
            csr: new Csr,
            store: $store,
            solver: $this->resolveSolver($config),
            parser: new X509Parser,
            pollAttempts: (int) ($pollConfig['attempts'] ?? 30),
            pollSeconds: (int) ($pollConfig['seconds'] ?? 2),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveSolver(array $config): AcmeChallengeSolver
    {
        $solver = $config['solver'] ?? null;

        if (is_string($solver) && $solver !== '') {
            /** @var AcmeChallengeSolver $instance */
            $instance = $this->container->make($solver);

            return $instance;
        }

        /** @var array<string, mixed> $http */
        $http = $config['http'] ?? [];

        return new HttpChallengeSolver(
            disk: (string) ($http['disk'] ?? 'local'),
            path: (string) ($http['path'] ?? 'acme-challenge'),
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
