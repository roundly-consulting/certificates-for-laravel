# Certificates for Laravel

Request, track, and renew the TLS certificates for your domains — directly from Laravel.

The package gives you a framework-native API to **issue**, **list**, **find**, and **renew** TLS
certificates, plus a local **registry** (an Eloquent model) that records every certificate's
status and expiry so you can answer "which certificates expire in 14 days?" with an ordinary
query. It ships with a Kubernetes provider that talks to the Kubernetes API directly (no
third-party SDK) to read [cert-manager](https://cert-manager.io) certificates and patch an Ingress
with the TLS hosts that trigger issuance, plus `null` and `array` drivers and a `Certificates::fake()`
test double. The provider contract is public, so you can plug in your own backend.

## Requirements

- PHP `^8.4`
- Laravel `^12.0` or `^13.0`

## Installation

```bash
composer require roundly-consulting/certificates-for-laravel
```

The service provider and the `Certificates` facade alias are auto-discovered.

Publish and run the migration to enable the local registry (recommended — without it the package
still issues certificates, but records nothing and cannot track expiry):

```bash
php artisan vendor:publish --tag="certificates-migrations"
php artisan migrate
```

Optionally publish the config and translations:

```bash
php artisan vendor:publish --tag="certificates-config"
php artisan vendor:publish --tag="certificates-translations"
```

## Configuration

The published config lives at `config/certificates.php`.

### Keys

| Key | Type | Default | Env |
|---|---|---|---|
| `default` | string | `kubernetes` | `CERTIFICATES_DRIVER` |
| `model` | class-string | `RoundlyConsulting\Certificates\Models\Certificate` | — |
| `table` | string | `certificates` | — |
| `renewal.threshold_days` | int | `21` | `CERTIFICATES_RENEW_THRESHOLD_DAYS` |
| `renewal.queue` | string\|null | `null` | `CERTIFICATES_RENEW_QUEUE` |
| `name_prefix` | string | `generated-tls-` | `CERTIFICATES_NAME_PREFIX` |
| `lock.name` | string | `certificates:generate` | `CERTIFICATES_LOCK_NAME` |
| `lock.locked_for_seconds` | int | `5` | `CERTIFICATES_LOCK_SECONDS` |
| `drivers.kubernetes.base_url` | string | `https://kubernetes.default.svc` | `CERTIFICATES_K8S_BASE_URL` |
| `drivers.kubernetes.token` | string\|null | `null` | `CERTIFICATES_K8S_TOKEN` |
| `drivers.kubernetes.token_path` | string\|null | in-cluster SA token path | `CERTIFICATES_K8S_TOKEN_PATH` |
| `drivers.kubernetes.ca_path` | string\|null | in-cluster SA CA path | `CERTIFICATES_K8S_CA_PATH` |
| `drivers.kubernetes.namespace` | string | `default` | `CERTIFICATES_K8S_NAMESPACE` |
| `drivers.kubernetes.issuer` | string | `letsencrypt` | `CERTIFICATES_K8S_ISSUER` |
| `drivers.kubernetes.issuer_kind` | string | `ClusterIssuer` | `CERTIFICATES_K8S_ISSUER_KIND` |
| `drivers.kubernetes.ingress.name` | string\|null | `null` | `CERTIFICATES_K8S_INGRESS_NAME` |
| `drivers.kubernetes.ingress.class` | string | `nginx` | `CERTIFICATES_K8S_INGRESS_CLASS` |
| `drivers.kubernetes.service.name` | string\|null | `null` | `CERTIFICATES_K8S_SERVICE_NAME` |
| `drivers.kubernetes.service.port` | int | `80` | `CERTIFICATES_K8S_SERVICE_PORT` |
| `drivers.null` | array | `[]` | — |
| `drivers.array` | array | `[]` | — |

`default` selects which driver is used when none is named. The `null` driver is an inert no-op for
local/dev; the `array` driver is an in-memory backend used by the test fake. The Kubernetes driver
authenticates with the standard in-cluster service-account token and CA bundle by default — set
`token` directly (or point `token_path` at a mounted file). Set `ca_path` to `null` to disable TLS
verification (not recommended).

## Usage

### Issue and track a certificate

```php
use RoundlyConsulting\Certificates\Facades\Certificates;

$certificate = Certificates::issueIfMissing('app.example.com');

$certificate->status;            // RoundlyConsulting\Certificates\Enums\CertificateStatus::Issued
$certificate->expires_at;        // CarbonImmutable|null
$certificate->expiresWithin(14); // bool
$certificate->daysUntilExpiry(); // int|null
```

### Fluent builder

```php
$certificate = Certificates::for('shop.tenant.com')
    ->using('kubernetes')
    ->issuer('letsencrypt-prod')
    ->namespace('tenants')
    ->validForDays(90)
    ->meta(['tenant' => '7'])
    ->owner($tenant)        // attaches via the HasCertificates morph
    ->issue();
```

The builder also exposes `->issueIfMissing()`, `->exists()`, `->status()`, and `->find()`.

### Read methods

```php
Certificates::get();                         // Collection<int, RemoteCertificate> from the active driver
Certificates::exists('app.example.com');     // bool
Certificates::find('app.example.com');       // ?Models\Certificate (registry lookup)
Certificates::status('app.example.com');     // ?CertificateStatus
Certificates::driver('null');                // resolve a specific provider instance
Certificates::certificateName('app.example.com'); // "generated-tls-app-example-com"
```

### Querying the registry

```php
use RoundlyConsulting\Certificates\Models\Certificate;

Certificate::query()->active()->get();
Certificate::query()->expiring(14)->get();   // expiring within 14 days (default: config threshold)
Certificate::query()->expired()->get();
Certificate::query()->forDomain('app.example.com')->forDriver('kubernetes')->get();
```

### Attach certificates to your own models

```php
use RoundlyConsulting\Certificates\Concerns\HasCertificates;

class Tenant extends Model
{
    use HasCertificates;
}

$tenant->requestCertificate('shop.tenant.com');
$tenant->certificateFor('shop.tenant.com');
$tenant->hasCertificateFor('shop.tenant.com');
$tenant->activeCertificates();
$tenant->expiringCertificates(days: 14);
```

### Validate domain input

```php
use RoundlyConsulting\Certificates\Rules\ValidDomain;

$request->validate(['domain' => ['required', new ValidDomain]]);
```

### Events

Listen to any of these (all carry the Eloquent `Models\Certificate`):

- `CertificateRequested`
- `CertificateIssued`
- `CertificateFailed` (also a `string $reason`)
- `CertificateRenewed`
- `CertificateExpiring` (also an `int $daysUntilExpiry`)

### Artisan commands

```bash
php artisan certificates:issue {domain} {--driver=} {--issuer=} {--namespace=}
php artisan certificates:list {--driver=} {--status=} {--expiring=}
php artisan certificates:renew {domain?} {--threshold=} {--queue}
php artisan certificates:prune {--days=30} {--status=}
php artisan certificates:sync {--driver=}
```

`certificates:renew` renews every certificate expiring within the threshold (or a single domain),
dispatching `CertificateExpiring` for each. Pass `--queue` to dispatch `RenewCertificateJob` onto
the queue named by `renewal.queue` instead of renewing inline.

#### Scheduling renewals

The package does not register a schedule — keep that in your app. A daily renewal one-liner:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('certificates:renew')->daily();
```

### Custom providers

Implement `CertificateProvider` and register it on the manager (e.g. from a service provider's
`boot()`):

```php
use RoundlyConsulting\Certificates\CertificateManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;

app(CertificateManager::class)->extend('acme', fn (): CertificateProvider => new MyAcmeProvider());
```

`CertificateProvider` exposes `get(): Collection`, `exists(string $name, string $domain): bool`,
and `generate(string $name, string $domain): void`. Providers that can report live status and
expiry may additionally implement `ReportsCertificateStatus::status()`, returning a
`CertificateStatusReport` — the registry uses it to populate `expires_at`.

### Testing without a backend

```php
Certificates::fake();

$this->post('/sites', ['domain' => 'app.example.com']);

Certificates::assertIssued('app.example.com');
```

The fake records issuances in memory and never touches a real provider. Assertion helpers:
`assertIssued`, `assertNotIssued`, `assertIssuedCount`, `assertRequested`, `assertFailed`.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE](LICENSE.md).
