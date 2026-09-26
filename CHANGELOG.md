# Changelog

All notable changes to `certificates-for-laravel` will be documented in this file.

## Unreleased

### Fixed

- The ACME account URL (kid) was stored once per key, regardless of the configured
  `directory`: switching from Let's Encrypt staging to production replayed the staging account
  at production and every order failed (`accountDoesNotExist` / `unauthorized`). It is now
  recorded per directory (`{key_path}.{sha256(directory)}.kid`) and bound to the account key's
  thumbprint, so a new CA — or a replaced key — registers its own account. The old unkeyed
  `{key_path}.kid` is ignored; newAccount for a known key returns the existing account.
  `AcmeAccount::kid()` / `setKid()` now take the directory URL.

