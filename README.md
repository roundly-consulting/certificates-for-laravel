# Certificates for Laravel

Manage and provision TLS certificates for your domains from Laravel via pluggable providers.

The package gives you a small, framework-native API to **list**, **check**, and **provision**
TLS certificates for domains. It ships with a Kubernetes provider that talks to the Kubernetes
API directly (no third-party SDK) to read [cert-manager](https://cert-manager.io) certificates
and to patch an Ingress with the TLS hosts that trigger certificate issuance. The provider
interface is public, so you can plug in your own backend.

## Requirements

- PHP `^8.4`
- Laravel `^12.0` or `^13.0`

## Installation

```bash
composer require roundly-consulting/certificates-for-laravel
```

The service provider and the `Certificates` facade alias are auto-discovered.

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="certificates-config"
```

This package ships no migrations or views — only configuration.

## Configuration

The published config lives at `config/certificates.php`:

```php
return [
    // Prefix for the derived, DNS-safe secret name (example.com => generated-tls-example-com).
    'name_prefix' => env('CERTIFICATES_NAME_PREFIX', 'generated-tls-'),

    // Cache lock guarding concurrent provisioning of the same domain.
    'lock' => [
        'name' => env('CERTIFICATES_LOCK_NAME', 'certificates:generate'),
        'locked_for_seconds' => (int) env('CERTIFICATES_LOCK_SECONDS', 5),
    ],

    'providers' => [
        'kubernetes' => [
            'base_url'   => env('CERTIFICATES_K8S_BASE_URL', 'https://kubernetes.default.svc'),
            'token'      => env('CERTIFICATES_K8S_TOKEN'),
            'token_path' => env('CERTIFICATES_K8S_TOKEN_PATH', '/var/run/secrets/kubernetes.io/serviceaccount/token'),
            'ca_path'    => env('CERTIFICATES_K8S_CA_PATH', '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt'),
            'namespace'  => env('CERTIFICATES_K8S_NAMESPACE', 'default'),
            'issuer'      => env('CERTIFICATES_K8S_ISSUER', 'letsencrypt'),
            'issuer_kind' => env('CERTIFICATES_K8S_ISSUER_KIND', 'ClusterIssuer'),
            'ingress' => [
                'name'  => env('CERTIFICATES_K8S_INGRESS_NAME'),
                'class' => env('CERTIFICATES_K8S_INGRESS_CLASS', 'nginx'),
            ],
            'service' => [
                'name' => env('CERTIFICATES_K8S_SERVICE_NAME'),
                'port' => (int) env('CERTIFICATES_K8S_SERVICE_PORT', 80),
            ],
        ],
    ],
];
```

### Keys

| Key | Type | Default | Env |
|---|---|---|---|
| `name_prefix` | string | `generated-tls-` | `CERTIFICATES_NAME_PREFIX` |
| `lock.name` | string | `certificates:generate` | `CERTIFICATES_LOCK_NAME` |
| `lock.locked_for_seconds` | int | `5` | `CERTIFICATES_LOCK_SECONDS` |
| `providers.kubernetes.base_url` | string | `https://kubernetes.default.svc` | `CERTIFICATES_K8S_BASE_URL` |
| `providers.kubernetes.token` | string\|null | `null` | `CERTIFICATES_K8S_TOKEN` |
| `providers.kubernetes.token_path` | string\|null | in-cluster SA token path | `CERTIFICATES_K8S_TOKEN_PATH` |
| `providers.kubernetes.ca_path` | string\|null | in-cluster SA CA path | `CERTIFICATES_K8S_CA_PATH` |
| `providers.kubernetes.namespace` | string | `default` | `CERTIFICATES_K8S_NAMESPACE` |
| `providers.kubernetes.issuer` | string | `letsencrypt` | `CERTIFICATES_K8S_ISSUER` |
| `providers.kubernetes.issuer_kind` | string | `ClusterIssuer` | `CERTIFICATES_K8S_ISSUER_KIND` |
| `providers.kubernetes.ingress.name` | string\|null | `null` | `CERTIFICATES_K8S_INGRESS_NAME` |
| `providers.kubernetes.ingress.class` | string | `nginx` | `CERTIFICATES_K8S_INGRESS_CLASS` |
| `providers.kubernetes.service.name` | string\|null | `null` | `CERTIFICATES_K8S_SERVICE_NAME` |
| `providers.kubernetes.service.port` | int | `80` | `CERTIFICATES_K8S_SERVICE_PORT` |

Authentication uses the standard in-cluster service-account token and CA bundle by default.
Set `token` directly (or point `token_path` at a mounted file) and `ca_path` at a CA bundle.
Set `ca_path` to `null` to disable TLS verification (not recommended).

## Usage

Use the `Certificates` facade (or resolve `CertificateService` from the container).

```php
use RoundlyConsulting\Certificates\Facades\Certificates;

// List every certificate the active provider manages.
$certificates = Certificates::get(); // Collection<int, RoundlyConsulting\Certificates\Certificate>

foreach ($certificates as $certificate) {
    echo $certificate->name.' => '.$certificate->domain;
}

// Check whether a certificate already exists for a domain.
if (! Certificates::exists('app.example.com')) {
    // Provision one. Returns false if a concurrent run holds the lock.
    Certificates::generate('app.example.com');
}

// Inspect the derived, DNS-safe secret name for a domain.
Certificates::certificateName('app.example.com'); // "generated-tls-app-example-com"
```

Without the facade:

```php
use RoundlyConsulting\Certificates\CertificateService;

$service = app(CertificateService::class);
$service->generate('app.example.com');
```

### Custom providers

Implement the contract and bind it in a service provider to swap the backend:

```php
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;

$this->app->singleton(CertificateProvider::class, MyProvider::class);
```

`CertificateProvider` exposes `get(): Collection`, `exists(string $name, string $domain): bool`,
and `generate(string $name, string $domain): void`.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE](LICENSE.md).
