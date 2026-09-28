# Changelog

All notable changes to `certificates-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- Issue, list, find and renew TLS certificates through the `Certificates` facade, with a fluent
  builder (`Certificates::for($domain)->using('acme')->issue()`).
- Native ACME v2 client for Let's Encrypt or any ACME CA, with HTTP-01 challenges and a pluggable
  challenge-solver contract.
- `kubernetes` provider for cert-manager certificates and Ingress TLS hosts, a `filesystem`
  provider with self-signing for local development, and `null` / `array` providers.
- Multi-domain (SAN) and wildcard certificates (`alsoFor()`, `coveringDomain()`).
- `Certificate` registry model recording status and expiry, with `active()`, `expiring()`,
  `expired()` and `forDomain()` scopes.
- `HasCertificates` trait to attach certificates to your own models (`requestCertificate()`,
  `expiringCertificates()`).
- Cached live status reports, multi-tenant connections (`Certificates::on()`) and macros.
- `ValidDomain` validation rule.
- Events for requested, issued, failed, renewed, expiring, revoked and expired certificates.
- Artisan commands `certificates:issue`, `certificates:list`, `certificates:renew` (inline or
  queued), `certificates:check`, `certificates:prune` and `certificates:sync`.
- Expiry monitoring with warning and critical windows, built on alerts-for-laravel.
- Custom providers via `CertificateProvider` (`Certificates::extend()`), and `Certificates::fake()`
  for testing without a backend.
- Lifecycle verbs on the facade, each backed by an action: `Certificates::renew()`, `renewLater()`,
  `renewDue()`, `revoke()`, `expire()`, `sync()`, `prune()` and `expiring()`, plus
  `Certificates::for($domain)->renew()`, `->renewLater()`, `->revoke()` and `->expire()`.
- `CertificatesFake` records renewals, queued renewals, renew-due runs, revocations, expirations,
  syncs and prunes (`assertRenewed()`, `assertRenewedLater()`, `assertRenewedDue()`,
  `assertRevoked()`, `assertExpired()`, `assertSynced()`, `assertPruned()` and their
  `assertNothing*()` / `assertNot*()` forms), and `seed()`s certificates it did not issue.

### Changed

- The facade root is `CertificatesManager` (was `CertificateService`) and the driver manager is
  `CertificateProviderManager` (was `CertificateManager`). The `certificates()` helper returns the
  facade root.
- `certificates:renew`, `certificates:sync` and `certificates:prune` call the facade. Given a
  domain, `certificates:renew` renews its most recent registry row and no longer dispatches
  `CertificateExpiring` for it.
- Expiry monitoring schedules through alerts' `Health::for($notifiable)->monitor()`.

### Fixed

- `Certificates::fake()` built its fake without the parent constructor, so inherited methods such as
  `driver()` threw.
- A renewal the provider rejects now moves the certificate to `Failed` and fires
  `CertificateFailed`, instead of leaving it stuck in `Renewing`.
- `RenewCertificateJob` re-reads the certificate through the `certificates.model` seam, on the
  database connection it was queued from.
