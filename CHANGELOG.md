# Changelog

All notable changes to `certificates-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Security

- Issuing a certificate whose name collides with another domain's (`a.b.com` and `a-b.com`, or `*.example.com` and `wildcard.example.com`, fold into the same name) now throws a `CertificateException` instead of taking over the other domain's registry row, owner and secret / stored material. The name stays with the domain that registered it first, pruned or not; `Certificates::fake()` refuses the same way.

## 1.0.2 - 2026-10-04

### Fixed

- The `CertificateException` thrown by `Certificates::monitorExpiry()` when no alert notifiable can be resolved is now translated into the current locale (English and Slovak).
- `CertificateStatus` labels (`label()`, `labels()`, `options()`, `toOptions()`) are now translated through the package's `statuses` lines, so `certificates:issue`, `certificates:list` and the provider-reported status error show the status in the current locale; the provider-reported error names the status label ("Failed") instead of its raw value ("failed"). Locales the package ships no lines for keep the previous headline labels.
- Count messages now use proper plural forms instead of "certificate(s)" / "day(s)": the `certificates:prune` and `certificates:sync` output and the expiry alert messages (`ok`, `warning`, `critical`, registry-wide failure) read "1 certificate" / "3 certificates" in English and use the one / few / many forms in Slovak. Published language overrides without plural forms keep working.

## 1.0.1 - 2026-10-04

### Changed

- Maintenance: `composer.json` `homepage` and `support.docs` now point to the documentation site.

### Fixed

- Slovak (`sk`) translations now ship alongside English for every language file.

## 1.0.0 - 2026-10-03

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
- `Certificates::renewDue()` returns a `RenewalReport` (renewed / queued / failed, each failure a
  `RenewalFailure` with its exception). The fake's `failRenewalOf()` simulates failures, checked
  with `assertRenewalFailed()` / `assertNoRenewalFailures()`.

### Changed

- The facade root is `CertificatesManager` (was `CertificateService`) and the driver manager is
  `CertificateProviderManager` (was `CertificateManager`). The `certificates()` helper returns the
  facade root.
- `certificates:renew`, `certificates:sync` and `certificates:prune` call the facade. Given a
  domain, `certificates:renew` renews its most recent registry row and no longer dispatches
  `CertificateExpiring` for it. For the due set it prints each certificate's outcome and exits
  non-zero when any failed.
- Expiry monitoring schedules through alerts' `Health::for($notifiable)->monitor()`.
- The builder's `issuer()` / `namespace()`, `IssueCertificateData::$issuer` / `$namespace` and
  `certificates:issue --issuer` / `--namespace` are gone: no provider could honour them. The issuer
  and namespace are driver configuration (`drivers.kubernetes.*`).
- `drivers.acme.challenge_type` (`CERTIFICATES_ACME_CHALLENGE`) is gone: the configured solver's
  `type()` picks the challenge, so a `DnsChallengeSolver` in `drivers.acme.solver` answers dns-01.
- `certificates:issue` runs through `CertificatesManager` (so `Certificates::fake()` records it)
  and accepts `--connection`.
- `Failed` is no longer terminal: a failed row can be renewed again, and `expiring()` /
  `renewDue()` include it while its live certificate runs out.
- Certificate names are lowercase DNS-1123 names (`*.` becomes `wildcard-`, at most 253
  characters), and domains are matched case-insensitively.
- The provisioning lock is per certificate. While it is held, `issue()` throws
  `ProvisioningInProgressException` and `generate()` returns `false`.

### Fixed

- `Certificates::fake()` built its fake without the parent constructor, so inherited methods such as
  `driver()` threw.
- A renewal the provider rejects now moves the certificate to `Failed` and fires
  `CertificateFailed`, instead of leaving it stuck in `Renewing`.
- `RenewCertificateJob` re-reads the certificate through the `certificates.model` seam, on the
  database connection it was queued from.
- `Certificates::renewDue()` and `certificates:renew` stopped at the first certificate that failed
  to renew (or to queue), silently skipping every later due certificate in that run. Each due
  certificate is now attempted independently.
- A first issuance on `kubernetes` crashed on the not-yet-created cert-manager Certificate (HTTP
  404) and left the row `Requested` with no event. It now stays `Requested` until
  `certificates:sync` records the outcome, and a real failure is `Failed` + `CertificateFailed`.
- `issue()` marked a row `Issued` with an invented expiry whatever the provider reported. It now
  records the reported status, expiry, issuer, serial and fingerprint.
- One global lock silently dropped the issuance of every other domain while any issuance ran.
- A renewal re-provisioned only the primary domain, dropping a certificate's SANs.
- Re-issuing a domain after `prune()` hit the unique index; the pruned row is now restored.
- The registry-wide expiry check reported OK while certificates were expired or failed.
- `certificates:sync` marked certificates cert-manager was still issuing as `Failed`.
- The `filesystem` driver reported issuance without material and renewals that changed nothing.
- `statusReport()` served the report cached before an issue or renewal.
- `renewLater()` queued a renewal for a certificate that cannot renew, and the fake accepted invalid
  domains.
- An empty `ca_path` / `verify` reached the HTTP client as an empty CA path. Only `false` disables
  TLS verification; `null` or empty uses the system bundle.
- Boolean switches set from `.env` as `1`/`0`/`on`/`off`/`yes`/`no` were misread: `1` left alerts
  off and `off` left the status cache on. They are now parsed as booleans, and a boolean word in
  `ca_path` / `verify` is read as the switch, not a CA bundle path.
