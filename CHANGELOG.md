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
- Custom providers via `CertificateProvider`, and `Certificates::fake()` for testing without a
  backend.
