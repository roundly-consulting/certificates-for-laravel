<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/certificates-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=certificates-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/certificates-for-laravel/main/art/hero.png" alt="Certificates for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/certificates-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/certificates-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/certificates-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/certificates-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/certificates-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/certificates-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=certificates-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Certificates for Laravel

Request, track and renew the TLS certificates for your domains directly from Laravel. Issue
through Let's Encrypt (a native ACME client), cert-manager on Kubernetes or a filesystem, and
keep every certificate's status and expiry in an Eloquent registry you can query, renew and
monitor.

## Installation

Requires PHP 8.4 (`ext-openssl`, `ext-json`) and Laravel 12 or 13.

```bash
composer require roundly-consulting/certificates-for-laravel
php artisan vendor:publish --tag="certificates-migrations"
php artisan migrate
```

If the models you attach certificates to have UUID/ULID keys, set `CERTIFICATES_KEY_TYPE`
**before** migrating. Pick the provider with `CERTIFICATES_DRIVER`: `kubernetes` (the default),
`acme` (your app must serve `/.well-known/acme-challenge/{token}` from the challenge disk) or
`filesystem`.

## Usage

Request a certificate (one SAN certificate for both hosts) and attach it to its owner. On the
default `kubernetes` driver cert-manager issues it in the background:

```php
use RoundlyConsulting\Certificates\Facades\Certificates;

$certificate = Certificates::for('shop.example.com')
    ->alsoFor('www.shop.example.com')
    ->owner($tenant)                  // a model using the HasCertificates trait
    ->issue();

$certificate->status;                 // CertificateStatus::Requested (acme, filesystem: Issued)
```

Schedule the sync that records the outcome and the renewal sweep, then read and manage the registry:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('certificates:sync')->everyFiveMinutes();  // Requested → Issued, fires CertificateIssued
Schedule::command('certificates:renew')->daily();            // everything inside renewal.threshold_days

Certificates::status('shop.example.com');                    // CertificateStatus::Issued once synced
Certificates::find('shop.example.com')?->daysUntilExpiry();  // 90
Certificates::expiring(14);                                  // Collection<int, Certificate>, soonest first
Certificates::revoke('shop.example.com', 'key compromise');
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/certificates-for-laravel](https://roundly-consulting.com/open-source/docs/certificates-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=certificates-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=certificates-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=certificates-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE](LICENSE.md).
