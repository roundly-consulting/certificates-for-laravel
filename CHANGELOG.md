# Changelog

All notable changes to `certificates-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

## 1.1.0 - 2026-10-07

### Changed

- `certificates.lock.locked_for_seconds` (`CERTIFICATES_LOCK_SECONDS`) now defaults to `600` (was `5`). Hosts that set it explicitly keep their value; keep it above your slowest issuance.
- `Certificates::sync()` / `certificates:sync` now completes the lifecycle of what was still in flight: a `Requested` row the provider reports issued becomes `Issued` with `issued_at` set and fires `CertificateIssued`; a `Renewing` row whose provider holds a new certificate becomes `Renewed` with `last_renewed_at` and fires `CertificateRenewed`; an existing row the provider reports failed fires `CertificateFailed` (so lifecycle alerts fire). Sync used to fire no events. Rows sync discovers or revives still fire nothing.
- `RenewCertificateJob` now declares a `$timeout` equal to `certificates.lock.locked_for_seconds` (600 seconds by default), so a worker no longer kills an ACME renewal after its own default timeout. Keep your queue connection's `retry_after` above it.
- `Certificates::expiring()`, the `expiring()` scope, `HasCertificates::expiringCertificates()`, `renewDue()` and `certificates:check` now also include Issued, Renewed and Failed certificates that are already past expiry (they used to stop at `now`). `CertificateExpiring` can therefore carry a negative `daysUntilExpiry`.
- Documentation: the README usage example now runs on the default `kubernetes` driver — `issue()` returns `Requested`, the scheduled `certificates:sync` records the outcome, and the registry is then read and revoked by domain. The old example claimed `Issued` and revoked a `Requested` certificate, which throws.
- Documentation: the README hero image uses an absolute URL, so it renders on Packagist and other sites.

### Fixed

- The provisioning lock no longer expires in the middle of an ACME issuance: with the old 5-second default a concurrent `issue()` of the same domain started a second ACME order while the first was still polling.
- `Certificates::renew()` now holds the certificate's provisioning lock and moves the row to `Renewing` only while it still has the status the caller read: concurrent renewals, a renewal racing `issue()` or a renewal of a stale model throw `ProvisioningInProgressException` instead of renewing twice. A `RenewCertificateJob` queued by `renewDue(queue: true)` now does nothing once the certificate is no longer due, so overlapping sweeps no longer re-renew a certificate that was just renewed (`renewLater()` jobs still always renew).
- A certificate whose renewal was interrupted (a queue timeout during ACME polling, a deploy) no longer stays in `Renewing` forever: once the row has been untouched for longer than `certificates.lock.locked_for_seconds`, `expiring()`, `renewDue()`, `renew()` and `renewLater()` treat it as renewable again, and `Certificates::fake()` does the same.
- A certificate whose renewal kept failing is now still renewed after it has expired: `renewDue()` used to drop it from the due set exactly at expiry, so the retries stopped when they mattered most. `Certificates::fake()` matches.
- The registry-wide `CertificateExpiryCheck` now fails for a certificate still in flight (`Pending`, `Requested`, `Renewing`) that is past its expiry; such a row used to read as healthy while its per-certificate check failed.
- On the `kubernetes` driver (and any provider that reports no fingerprint), `renew()` no longer records a renewal when the reported expiry did not move later: cert-manager keeps reporting `Ready` while its own renewal fails, and the row was marked `Renewed` with the old `notAfter` and `CertificateRenewed` fired every day. Such a renewal now throws `CertificateException`, marks the row `Failed` and fires `CertificateFailed`.
- Re-issuing a certificate on the `kubernetes` driver with a changed set of domains (`alsoFor()`) now updates the Ingress: the TLS entry's hosts are rewritten to the new set and a rule is added for each new host. It used to write nothing while the registry recorded the new domains as issued. Rules are only added, never removed.
- Concurrent issuance of different domains on the `kubernetes` driver no longer fails one of them: an Ingress write that loses the race (`409 Conflict`, including two processes creating the Ingress at once) is re-read and retried up to five times, keeping both TLS entries. The losing certificate used to be marked `Failed` with no expiry and was never retried.
- `Certificates::sync()` / `certificates:sync` no longer un-revokes a certificate: a `Revoked` row stays `Revoked` whatever the provider reports, since revocation is recorded in the registry only. A fresh `issue()` still revives it.
- On the default `kubernetes` driver, where issuance completes asynchronously, `CertificateIssued` and `CertificateFailed` now fire and `issued_at` is set once `certificates:sync` sees the outcome; before, no issuance event ever fired on that driver and `issued_at` stayed empty.
- Expiry alerts for a certificate on another database connection now evaluate that certificate: `certificates:check --connection=…`, the lifecycle alert listener and `monitorExpiry()` schedules used to load the same id from the default connection (another certificate, or "skipped"). `CertificateExpiryCheck` takes a `connection` argument (or `connection` meta on a scheduled check), and `monitorExpiry()` records it.
- The `acme` driver no longer re-solves an authorization the CA already holds as valid (a CA reuses recently validated authorizations): issuance used to fail with "no dns-01 challenge offered" after switching solver type, and re-POSTed the valid challenge otherwise. An authorization that is `invalid`, `deactivated`, `expired` or `revoked` now fails at once instead of being solved. New `AcmeClient::pendingChallenge()`.
- The `acme` driver now fails at once with the CA's error detail when an authorization or order poll is answered with an HTTP error, instead of reading the problem document as "pending" and ending in a misleading timeout; and it falls back to the order URL from `newOrder` when the finalize response carries no `Location` header (`AcmeClient::finalize()` takes it as an optional third argument).
- Hostnames longer than 64 characters can now be issued on the `acme` driver and self-signed on the `filesystem` driver: the CSR's common name is the first domain that fits a common name (64 characters at most), or none, instead of always the first domain, which made OpenSSL refuse the CSR. Every domain is still in the subjectAltName extension.
- `Certificates::for($domain)->using($driver)->status()` and `->exists()` now read the chosen driver, like `find()` and the lifecycle verbs already did; `status()` reported the domain's newest row on any driver and `exists()` asked the default driver. `Certificates::status()` and `Certificates::exists()` take an optional `$driver` argument, and `Certificates::fake()` honours it.
- `Certificates::fake()` now records a certificate issued without an explicit driver on the configured default driver (it used `array`) and keeps its `meta`, as a real `issue()` does.
- `Certificates::issueIfMissing()` (and the builder's), `Certificates::status()` (and the builder's) and `HasCertificates::hasCertificateFor()` now also find a SAN certificate covering the host, so `issueIfMissing('www.shop.example.com')` no longer issues a duplicate next to a certificate for `shop.example.com` + `www.shop.example.com`. `find()`, `certificateFor()`, `renew()`, `revoke()` and `expire()` still match the main domain exactly. `Certificates::fake()` matches.
- `certificates:sync` on the `acme` and `filesystem` drivers now records a certificate that has no common name under its first subjectAltName domain; it used to record an empty domain.
- The certificate store (`acme` and `filesystem` drivers) now writes a certificate's files to temporary names and moves them into place only once all are written, so a crash or failed write no longer leaves a new certificate next to the old private key. A write the disk reports as failed now throws `CertificateException` instead of being ignored.

### Security

- Issuing a certificate whose name collides with another domain's (`a.b.com` and `a-b.com`, or `*.example.com` and `wildcard.example.com`, fold into the same name) now throws a `CertificateException` instead of taking over the other domain's registry row, owner and secret / stored material. The name stays with the domain that registered it first, pruned or not; `Certificates::fake()` refuses the same way.
- The `acme` driver now refuses a challenge token that is not base64url (RFC 8555 §8.3). The HTTP-01 solver writes the token as a file name, so a malicious CA — or a man in the middle with TLS verification off — could send `../…` and have it overwrite and then delete files outside the challenge path on that disk, such as another certificate's private key.

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
