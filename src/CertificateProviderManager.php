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
use RoundlyConsulting\Certificates\Support\CertificateMapper;

/**
 * The driver manager behind `Certificates::driver()` / `Certificates::extend()`: it
 * builds and caches one CertificateProvider per configured driver. Host code rarely
 * needs it directly — the facade root, `CertificatesManager`, is the public API.
 *
 * @method CertificateProvider driver(?string $driver = null)
 */
final class CertificateProviderManager extends Manager
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
        /** @var array<string, mixed> $kubernetes */
        $kubernetes = $this->config->get('certificates.drivers.kubernetes', []);

        $verify = $kubernetes['ca_path'] ?? true;

        return new KubernetesProvider(
            baseUrl: (string) ($kubernetes['base_url'] ?? ''),
            token: $this->resolveToken($kubernetes),
            namespace: (string) ($kubernetes['namespace'] ?? 'default'),
            ingressName: (string) ($kubernetes['ingress']['name'] ?? ''),
            serviceName: isset($kubernetes['service']['name']) ? (string) $kubernetes['service']['name'] : null,
            servicePort: (int) ($kubernetes['service']['port'] ?? 80),
            issuer: (string) ($kubernetes['issuer'] ?? 'letsencrypt'),
            issuerKind: (string) ($kubernetes['issuer_kind'] ?? 'ClusterIssuer'),
            ingressClass: (string) ($kubernetes['ingress']['class'] ?? 'nginx'),
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
        /** @var array<string, mixed> $filesystem */
        $filesystem = $this->config->get('certificates.drivers.filesystem', []);

        $store = new FilesystemCertificateStore(
            disk: (string) ($filesystem['disk'] ?? 'local'),
            path: (string) ($filesystem['path'] ?? 'certificates'),
        );

        $selfSigned = (bool) ($filesystem['self_signed'] ?? false);

        return new LocalFilesystemProvider(
            store: $store,
            parser: new CertificateMapper,
            csr: $selfSigned ? new Csr : null,
            selfSignedDays: (int) ($filesystem['self_signed_days'] ?? 90),
        );
    }

    public function createAcmeDriver(): CertificateProvider
    {
        /** @var array<string, mixed> $acme */
        $acme = $this->config->get('certificates.drivers.acme', []);

        /** @var array<string, mixed> $accountConfig */
        $accountConfig = $acme['account'] ?? [];
        /** @var array<string, mixed> $storeConfig */
        $storeConfig = $acme['store'] ?? [];
        /** @var array<string, mixed> $pollConfig */
        $pollConfig = $acme['poll'] ?? [];

        $jws = new Jws;

        $account = new AcmeAccount(
            disk: (string) ($accountConfig['disk'] ?? 'local'),
            keyPath: (string) ($accountConfig['key_path'] ?? 'acme/account.pem'),
            keyType: (string) ($accountConfig['key_type'] ?? 'EC'),
            autoRegister: (bool) ($accountConfig['auto_register'] ?? true),
        );

        $verify = $acme['verify'] ?? true;

        $client = new AcmeClient(
            jws: $jws,
            account: $account,
            directoryUrl: (string) ($acme['directory'] ?? 'https://acme-v02.api.letsencrypt.org/directory'),
            contact: isset($acme['contact']) ? (string) $acme['contact'] : null,
            verify: is_string($verify) ? $verify : (bool) $verify,
        );

        $store = new FilesystemCertificateStore(
            disk: (string) ($storeConfig['disk'] ?? 'local'),
            path: (string) ($storeConfig['path'] ?? 'certificates'),
        );

        return new AcmeProvider(
            client: $client,
            csr: new Csr,
            store: $store,
            solver: $this->resolveSolver($acme),
            parser: new CertificateMapper,
            pollAttempts: (int) ($pollConfig['attempts'] ?? 30),
            pollSeconds: (int) ($pollConfig['seconds'] ?? 2),
        );
    }

    /**
     * @param  array<string, mixed>  $acme
     */
    private function resolveSolver(array $acme): AcmeChallengeSolver
    {
        $solver = $acme['solver'] ?? null;

        if (is_string($solver) && $solver !== '') {
            /** @var AcmeChallengeSolver $instance */
            $instance = $this->container->make($solver);

            return $instance;
        }

        /** @var array<string, mixed> $http */
        $http = $acme['http'] ?? [];

        return new HttpChallengeSolver(
            disk: (string) ($http['disk'] ?? 'local'),
            path: (string) ($http['path'] ?? 'acme-challenge'),
        );
    }

    /**
     * Read the bearer token, preferring an inline value and falling back to a
     * mounted service-account token file when only a path is configured.
     *
     * @param  array<string, mixed>  $kubernetes
     */
    private function resolveToken(array $kubernetes): string
    {
        if (! empty($kubernetes['token'])) {
            return (string) $kubernetes['token'];
        }

        $path = $kubernetes['token_path'] ?? null;

        if (is_string($path) && is_readable($path)) {
            return trim((string) file_get_contents($path));
        }

        return '';
    }
}
