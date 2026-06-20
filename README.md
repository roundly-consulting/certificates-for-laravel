<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/certificates-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=certificates-for-laravel">
    <img src="art/hero.png" alt="Certificates for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

# Certificates for Laravel

Request, track, and renew the TLS certificates for your domains — directly from Laravel.

The package gives you a framework-native API to **issue**, **list**, **find**, and **renew** TLS
certificates, plus a local **registry** (an Eloquent model) that records every certificate's
status and expiry so you can answer "which certificates expire in 14 days?" with an ordinary
query.

Pluggable providers cover the common deployment shapes:

- **`acme`** — a native, pure-PHP [ACME v2](https://datatracker.ietf.org/doc/html/rfc8555) client
  that obtains certificates directly from Let's Encrypt (or any ACME CA), with a pluggable
  challenge-solver contract (HTTP-01 included; DNS-01 as a documented extension point). No
  third-party crypto or HTTP SDK — just `ext-openssl` and Laravel's HTTP client.
- **`kubernetes`** — talks to the Kubernetes API directly (no SDK) to read
  [cert-manager](https://cert-manager.io) certificates and patch an Ingress with TLS hosts.
- **`filesystem`** — reads/writes PEM material on a Laravel `Storage` disk and can self-sign for
  local development.
- **`null`** / **`array`** — an inert no-op and an in-memory backend powering `Certificates::fake()`.

On top of issuance it adds **multi-domain / SAN + wildcard** certificates, opt-in **expiry
monitoring & notifications**, cache-aware **status reports**, **multi-tenant** connection targeting,
and **macroable** services. The provider contract is public, so you can plug in your own backend.

## Requirements

- PHP `^8.4` with the `openssl` and `json` extensions
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
| `connection` | string\|null | `null` | `CERTIFICATES_DB_CONNECTION` |
| `model` | class-string | `RoundlyConsulting\Certificates\Models\Certificate` | — |
| `table` | string | `certificates` | — |
| `renewal.threshold_days` | int | `21` | `CERTIFICATES_RENEW_THRESHOLD_DAYS` |
| `renewal.queue` | string\|null | `null` | `CERTIFICATES_RENEW_QUEUE` |
| `name_prefix` | string | `generated-tls-` | `CERTIFICATES_NAME_PREFIX` |
| `lock.name` | string | `certificates:generate` | `CERTIFICATES_LOCK_NAME` |
| `lock.locked_for_seconds` | int | `5` | `CERTIFICATES_LOCK_SECONDS` |
| `status_cache.enabled` | bool | `true` | `CERTIFICATES_STATUS_CACHE` |
| `status_cache.store` | string\|null | `null` | `CERTIFICATES_STATUS_CACHE_STORE` |
| `status_cache.ttl` | int | `300` | `CERTIFICATES_STATUS_CACHE_TTL` |
| `notifications.enabled` | bool | `false` | `CERTIFICATES_NOTIFY` |
| `notifications.channels` | list | `['mail']` | — |
| `notifications.route.mail` | string\|null | `null` | `CERTIFICATES_NOTIFY_MAIL` |
| `notifications.notifiable` | string\|null | `null` | `CERTIFICATES_NOTIFY_NOTIFIABLE` |
| `drivers.acme.directory` | string | Let's Encrypt prod directory | `CERTIFICATES_ACME_DIRECTORY` |
| `drivers.acme.contact` | string\|null | `null` | `CERTIFICATES_ACME_CONTACT` |
| `drivers.acme.account.key_type` | string | `EC` | `CERTIFICATES_ACME_KEY_TYPE` |
| `drivers.acme.account.disk` | string | `local` | `CERTIFICATES_ACME_ACCOUNT_DISK` |
| `drivers.acme.account.key_path` | string | `acme/account.pem` | `CERTIFICATES_ACME_ACCOUNT_KEY` |
| `drivers.acme.account.auto_register` | bool | `true` | `CERTIFICATES_ACME_AUTO_REGISTER` |
| `drivers.acme.challenge_type` | string | `http-01` | `CERTIFICATES_ACME_CHALLENGE` |
| `drivers.acme.solver` | string\|null | `null` | `CERTIFICATES_ACME_SOLVER` |
| `drivers.acme.http.disk` | string | `local` | `CERTIFICATES_ACME_HTTP_DISK` |
| `drivers.acme.http.path` | string | `acme-challenge` | `CERTIFICATES_ACME_HTTP_PATH` |
| `drivers.acme.store.disk` | string | `local` | `CERTIFICATES_ACME_STORE_DISK` |
| `drivers.acme.store.path` | string | `certificates` | `CERTIFICATES_ACME_STORE_PATH` |
| `drivers.acme.poll.attempts` | int | `30` | `CERTIFICATES_ACME_POLL_ATTEMPTS` |
| `drivers.acme.poll.seconds` | int | `2` | `CERTIFICATES_ACME_POLL_SECONDS` |
| `drivers.acme.verify` | bool\|string | `true` | `CERTIFICATES_ACME_VERIFY` |
| `drivers.filesystem.disk` | string | `local` | `CERTIFICATES_FS_DISK` |
| `drivers.filesystem.path` | string | `certificates` | `CERTIFICATES_FS_PATH` |
| `drivers.filesystem.self_signed` | bool | `false` | `CERTIFICATES_FS_SELF_SIGNED` |
| `drivers.filesystem.self_signed_days` | int | `90` | `CERTIFICATES_FS_SELF_SIGNED_DAYS` |
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

The builder also exposes `->issueIfMissing()`, `->exists()`, `->status()`, `->find()`,
`->statusReport()`, and `->fresh()`.

### ACME / Let's Encrypt

The `acme` driver obtains real certificates from any ACME v2 CA in pure PHP. Point it at a CA
directory (Let's Encrypt production by default; use the staging URL while testing), give it a
contact email, and issue:

```php
// config/certificates.php → 'default' => 'acme', or:
Certificates::for('app.example.com')->using('acme')->issue();
```

The default **HTTP-01** solver writes the challenge token to the configured Storage disk; your app
must serve it at `/.well-known/acme-challenge/{token}`. Issued material (leaf, private key, chain)
is stored on the configured store disk. The account key is generated and persisted automatically on
first use (`account.auto_register`).

**DNS-01** is a documented extension point. Extend `ChallengeSolvers\DnsChallengeSolver`, implement
`publishRecord()` / `removeRecord()` against your DNS provider, and register it via
`drivers.acme.solver`:

```php
use RoundlyConsulting\Certificates\ChallengeSolvers\DnsChallengeSolver;

final class Route53Solver extends DnsChallengeSolver
{
    protected function publishRecord(string $name, string $value): void { /* upsert TXT */ }
    protected function removeRecord(string $name, string $value): void { /* delete TXT */ }
}
```

### Filesystem provider

The `filesystem` driver manages PEM material on a Storage disk. It is **not** a CA: it reads
material produced elsewhere (e.g. an external ACME run) and, with `self_signed` enabled, generates
self-signed certificates for local development and tests.

```php
config(['certificates.drivers.filesystem.self_signed' => true]);
Certificates::for('app.test')->using('filesystem')->issue();
```

### Multi-domain (SAN) and wildcard certificates

Issue one certificate covering several domains by passing an array to `for()`, or appending SANs
fluently with `alsoFor()`. Wildcards are supported:

```php
Certificates::for(['app.example.com', '*.app.example.com'])->using('acme')->issue();

Certificates::for('example.com')
    ->alsoFor('www.example.com', 'api.example.com')
    ->using('acme')
    ->issue();
```

The primary domain is stored on the `domain` column; the full SAN list is stored on the additive
`domains` JSON column. Find a certificate that covers a host with the `coveringDomain` scope:

```php
Certificate::query()->coveringDomain('www.example.com')->first();
```

Providers that implement `Contracts\ProvisionsMultipleDomains` (`acme`, `filesystem`, `kubernetes`,
`array`) receive the full domain list; others fall back to the primary domain.

### Live status reports (cached)

`statusReport()` returns a live `CertificateStatusReport` from the provider, cached per the
`status_cache.*` config. Pass `fresh: true` (or call `->fresh()` on the builder) to bypass the cache
and refresh it:

```php
Certificates::statusReport('app.example.com');               // cached
Certificates::statusReport('app.example.com', fresh: true);  // bypass + refresh
Certificates::for('app.example.com')->fresh()->statusReport();
```

### Multi-tenant connections

Target a specific database connection per call with `on()` (returns a connection-bound clone,
leaving the singleton untouched). Set a default connection with the `connection` config key.

```php
Certificates::on('tenant')->for('app.tenant.com')->issue();
Certificates::on('tenant')->find('app.tenant.com');
```

Every command also accepts `--connection=`.

### Macros

`CertificateService` and `CertificateBuilder` are macroable — bolt on your own domain methods (e.g.
from `AppServiceProvider::boot()`):

```php
use RoundlyConsulting\Certificates\CertificateService;

CertificateService::macro('issueForTeam', function (Team $team, string $domain) {
    /** @var CertificateService $this */
    return $this->for($domain)->owner($team)->issue();
});

Certificates::issueForTeam($team, 'app.example.com');
```

### Read methods

```php
Certificates::get();                         // Collection<int, RemoteCertificate> from the active driver
Certificates::exists('app.example.com');     // bool
Certificates::find('app.example.com');       // ?Models\Certificate (registry lookup)
Certificates::status('app.example.com');     // ?CertificateStatus (registry enum)
Certificates::statusReport('app.example.com'); // ?CertificateStatusReport (live, cached)
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
php artisan certificates:list {--driver=} {--status=} {--expiring=} {--connection=}
php artisan certificates:renew {domain?} {--threshold=} {--queue} {--connection=}
php artisan certificates:check {--threshold=} {--notify} {--driver=} {--connection=}
php artisan certificates:prune {--days=30} {--status=} {--connection=}
php artisan certificates:sync {--driver=} {--connection=}
```

`certificates:renew` renews every certificate expiring within the threshold (or a single domain),
dispatching `CertificateExpiring` for each. Pass `--queue` to dispatch `RenewCertificateJob` onto
the queue named by `renewal.queue` instead of renewing inline.

### Expiry monitoring & notifications

`certificates:check` is read-only — it scans the registry for certificates nearing expiry, fires
the `Events\CertificateExpiring` event for each, and (opt-in) sends the
`Notifications\CertificateExpiring` notification. It never renews.

Notifications are off by default. Enable them with `--notify` (or `notifications.enabled`) and
configure a destination — either an on-demand route or a notifiable class:

```php
// On-demand route (anonymous notifiable):
'notifications' => [
    'channels' => ['mail'],
    'route' => ['mail' => 'ops@example.com'],
],

// Or a notifiable resolved from the container:
'notifications' => ['notifiable' => App\Models\OpsTeam::class],
```

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('certificates:check --notify')->daily();
```

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
and `generate(string $name, string $domain): void`. Opt-in capability interfaces:

- `ReportsCertificateStatus::status()` — report live status/expiry as a `CertificateStatusReport`
  (the registry uses it to populate `expires_at`).
- `ProvisionsMultipleDomains::generateMany()` — provision a single SAN certificate covering several
  domains.

To persist issued material, implement `Contracts\CertificateStore` (the package ships
`Stores\FilesystemCertificateStore`) and parse PEM with `Support\X509Parser`.

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
