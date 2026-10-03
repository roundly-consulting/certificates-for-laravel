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
use RoundlyConsulting\Certificates\Support\Settings;
use RoundlyConsulting\Certificates\Support\TlsVerification;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

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
        return Settings::string('certificates.default', $this->config->get('certificates.default'), 'kubernetes');
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
        $kubernetes = $this->section('certificates.drivers.kubernetes');

        return new KubernetesProvider(
            // Unset base URL / ingress name stay '' so the API call fails loudly when the
            // driver is used; a value of the wrong type throws here.
            baseUrl: Settings::optionalString('certificates.drivers.kubernetes.base_url', $kubernetes['base_url'] ?? null) ?? '',
            token: $this->resolveToken($kubernetes),
            namespace: Settings::string('certificates.drivers.kubernetes.namespace', $kubernetes['namespace'] ?? null, 'default'),
            ingressName: Settings::optionalString('certificates.drivers.kubernetes.ingress.name', $kubernetes['ingress']['name'] ?? null) ?? '',
            serviceName: Settings::optionalString('certificates.drivers.kubernetes.service.name', $kubernetes['service']['name'] ?? null),
            servicePort: Settings::integer('certificates.drivers.kubernetes.service.port', $kubernetes['service']['port'] ?? null, 80, min: 1, max: 65535),
            issuer: Settings::string('certificates.drivers.kubernetes.issuer', $kubernetes['issuer'] ?? null, 'letsencrypt'),
            issuerKind: Settings::string('certificates.drivers.kubernetes.issuer_kind', $kubernetes['issuer_kind'] ?? null, 'ClusterIssuer'),
            ingressClass: Settings::string('certificates.drivers.kubernetes.ingress.class', $kubernetes['ingress']['class'] ?? null, 'nginx'),
            verify: TlsVerification::from($kubernetes['ca_path'] ?? null, 'certificates.drivers.kubernetes.ca_path'),
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
        $filesystem = $this->section('certificates.drivers.filesystem');

        $store = new FilesystemCertificateStore(
            disk: Settings::string('certificates.drivers.filesystem.disk', $filesystem['disk'] ?? null, 'local'),
            path: Settings::string('certificates.drivers.filesystem.path', $filesystem['path'] ?? null, 'certificates'),
        );

        $selfSigned = Config::boolean('certificates.drivers.filesystem.self_signed');

        return new LocalFilesystemProvider(
            store: $store,
            parser: new CertificateMapper,
            csr: $selfSigned ? new Csr : null,
            selfSignedDays: Settings::integer('certificates.drivers.filesystem.self_signed_days', $filesystem['self_signed_days'] ?? null, 90, min: 1),
        );
    }

    public function createAcmeDriver(): CertificateProvider
    {
        /** @var array<string, mixed> $acme */
        $acme = $this->section('certificates.drivers.acme');

        /** @var array<string, mixed> $accountConfig */
        $accountConfig = $acme['account'] ?? [];
        /** @var array<string, mixed> $storeConfig */
        $storeConfig = $acme['store'] ?? [];
        /** @var array<string, mixed> $pollConfig */
        $pollConfig = $acme['poll'] ?? [];

        $jws = new Jws;

        $account = new AcmeAccount(
            disk: Settings::string('certificates.drivers.acme.account.disk', $accountConfig['disk'] ?? null, 'local'),
            keyPath: Settings::string('certificates.drivers.acme.account.key_path', $accountConfig['key_path'] ?? null, 'acme/account.pem'),
            keyType: Config::for(['certificates.drivers.acme.account.key_type' => $accountConfig['key_type'] ?? null])
                ->oneOf('certificates.drivers.acme.account.key_type', ['EC', 'RSA'], 'EC'),
            autoRegister: Config::boolean('certificates.drivers.acme.account.auto_register', true),
        );

        $client = new AcmeClient(
            jws: $jws,
            account: $account,
            directoryUrl: Settings::string('certificates.drivers.acme.directory', $acme['directory'] ?? null, 'https://acme-v02.api.letsencrypt.org/directory'),
            contact: Settings::optionalString('certificates.drivers.acme.contact', $acme['contact'] ?? null),
            verify: TlsVerification::from($acme['verify'] ?? null, 'certificates.drivers.acme.verify'),
        );

        $store = new FilesystemCertificateStore(
            disk: Settings::string('certificates.drivers.acme.store.disk', $storeConfig['disk'] ?? null, 'local'),
            path: Settings::string('certificates.drivers.acme.store.path', $storeConfig['path'] ?? null, 'certificates'),
        );

        return new AcmeProvider(
            client: $client,
            csr: new Csr,
            store: $store,
            solver: $this->resolveSolver($acme),
            parser: new CertificateMapper,
            pollAttempts: Settings::integer('certificates.drivers.acme.poll.attempts', $pollConfig['attempts'] ?? null, 30, min: 1),
            pollSeconds: Settings::integer('certificates.drivers.acme.poll.seconds', $pollConfig['seconds'] ?? null, 2, min: 1),
        );
    }

    /**
     * @param  array<string, mixed>  $acme
     */
    private function resolveSolver(array $acme): AcmeChallengeSolver
    {
        $solver = Settings::optionalString('certificates.drivers.acme.solver', $acme['solver'] ?? null);

        if ($solver !== null) {
            $instance = $this->container->make($solver);

            if (! $instance instanceof AcmeChallengeSolver) {
                throw InvalidConfigurationException::notAnImplementation('certificates.drivers.acme.solver', AcmeChallengeSolver::class, $solver);
            }

            return $instance;
        }

        /** @var array<string, mixed> $http */
        $http = $acme['http'] ?? [];

        return new HttpChallengeSolver(
            disk: Settings::string('certificates.drivers.acme.http.disk', $http['disk'] ?? null, 'local'),
            path: Settings::string('certificates.drivers.acme.http.path', $http['path'] ?? null, 'acme-challenge'),
        );
    }

    /**
     * A driver's config section; `[]` when absent, and a throw when it is not an array.
     *
     * @return array<array-key, mixed>
     */
    private function section(string $key): array
    {
        $section = $this->config->get($key) ?? [];

        if (! is_array($section)) {
            throw new InvalidConfigurationException("Configuration value [{$key}] must be an array, [".get_debug_type($section).'] given.');
        }

        return $section;
    }

    /**
     * Read the bearer token, preferring an inline value and falling back to a
     * mounted service-account token file when only a path is configured.
     *
     * @param  array<string, mixed>  $kubernetes
     */
    private function resolveToken(array $kubernetes): string
    {
        $token = Settings::optionalString('certificates.drivers.kubernetes.token', $kubernetes['token'] ?? null);

        if ($token !== null) {
            return $token;
        }

        $path = Settings::optionalString('certificates.drivers.kubernetes.token_path', $kubernetes['token_path'] ?? null);

        if ($path !== null && is_readable($path)) {
            return trim((string) file_get_contents($path));
        }

        return '';
    }
}
