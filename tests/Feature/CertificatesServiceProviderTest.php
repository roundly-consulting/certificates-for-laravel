<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Certificates\CertificatesServiceProvider;
use RoundlyConsulting\Certificates\Commands\CheckCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\IssueCertificateCommand;
use RoundlyConsulting\Certificates\Commands\ListCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\PruneCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\RenewCertificatesCommand;
use RoundlyConsulting\Certificates\Commands\SyncCertificatesCommand;
use RoundlyConsulting\Certificates\Models\Certificate;

it('merges the package config', function (): void {
    expect(config('certificates.model'))->toBe(Certificate::class)
        ->and(config('certificates.table'))->toBe('certificates')
        ->and(config('certificates.renewal.threshold_days'))->toBe(21);
});

it('registers every console command', function (string $signature, string $class): void {
    expect(Artisan::all())->toHaveKey($signature)
        ->and(Artisan::all()[$signature])->toBeInstanceOf($class);
})->with([
    ['certificates:issue', IssueCertificateCommand::class],
    ['certificates:list', ListCertificatesCommand::class],
    ['certificates:renew', RenewCertificatesCommand::class],
    ['certificates:prune', PruneCertificatesCommand::class],
    ['certificates:sync', SyncCertificatesCommand::class],
    ['certificates:check', CheckCertificatesCommand::class],
]);

it('registers every publish tag', function (string $tag): void {
    expect(ServiceProvider::pathsToPublish(CertificatesServiceProvider::class, $tag))->not->toBeEmpty();
})->with([
    'certificates-config',
    'certificates-migrations',
    'certificates-translations',
]);

it('publishes the config file and the translations', function (): void {
    $config = ServiceProvider::pathsToPublish(CertificatesServiceProvider::class, 'certificates-config');
    $translations = ServiceProvider::pathsToPublish(CertificatesServiceProvider::class, 'certificates-translations');

    expect($config)->toBe([
        realpath(__DIR__.'/../../config/certificates.php') => config_path('certificates.php'),
    ])->and($translations)->toBe([
        realpath(__DIR__.'/../../resources/lang') => app()->langPath('vendor/certificates'),
    ]);
});

it('never auto-loads its migrations — the host must publish them', function (): void {
    $registered = array_map(
        static fn (string $path): string => realpath($path) ?: $path,
        app('migrator')->paths(),
    );

    expect($registered)->not->toContain(realpath(__DIR__.'/../../database/migrations'));
});

it('publishes both migrations timestamp-injected, create before alter', function (): void {
    $paths = ServiceProvider::pathsToPublish(CertificatesServiceProvider::class, 'certificates-migrations');

    expect($paths)->toHaveCount(2);

    $sources = array_map(basename(...), array_keys($paths));
    $targets = array_map(basename(...), array_values($paths));

    expect($sources)->toBe([
        '0001_01_01_000000_create_certificates_table.php',
        '0001_01_01_000100_add_domains_to_certificates_table.php',
    ]);

    foreach (array_values($paths) as $target) {
        expect(dirname((string) $target))->toBe(database_path('migrations'));
    }

    expect($targets[0])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_certificates_table\.php$/')
        ->and($targets[1])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_add_domains_to_certificates_table\.php$/');

    // The published timestamps step forward one file at a time, so the host's
    // migrator runs the ALTER after the CREATE it depends on.
    $sorted = $targets;
    sort($sorted);

    expect($sorted)->toBe($targets);
});

it('contributes a certificates section to about', function (string $expected): void {
    $this->artisan('about --only=certificates')
        ->expectsOutputToContain($expected)
        ->assertExitCode(0);
})->with([
    'Certificates',
    'Driver',
    'Model',
    'Table',
    'Connection',
    'Renewal',
    'Status cache',
    'Alerts',
    'Expiry check',
    'Kubernetes API',
    'Kubernetes token',
    'ACME directory',
    'ACME contact',
    'ACME account key',
    'ACME challenge',
]);

it('reports renewal, cache and alert state in about', function (): void {
    config()->set('certificates.alerts.enabled', true);

    $this->artisan('about --only=certificates')
        ->expectsOutputToContain('21 days before expiry')
        ->expectsOutputToContain('ON (300s, store DEFAULT)')
        ->expectsOutputToContain('ON (warn 30d, critical 7d)')
        ->assertExitCode(0);
});

it('reports a disabled cache and disabled alerts as off', function (): void {
    config()->set('certificates.status_cache.enabled', false);
    config()->set('certificates.alerts.enabled', false);

    $this->artisan('about --only=certificates')
        ->expectsOutputToContain('OFF')
        ->assertExitCode(0);
});

it('reports the acme account key type and challenge shape without the key path', function (): void {
    config()->set('certificates.drivers.acme.account.key_type', 'RSA');
    config()->set('certificates.drivers.acme.account.auto_register', false);
    config()->set('certificates.drivers.acme.solver', 'App\\Acme\\RouteFiftyThreeSolver');

    $this->artisan('about --only=certificates')
        ->expectsOutputToContain('RSA (auto-register OFF)')
        ->expectsOutputToContain('http-01 (solver CUSTOM)')
        ->assertExitCode(0);
});

it('reports the kubernetes token as sourced from a file when no inline token is set', function (): void {
    config()->set('certificates.drivers.kubernetes.token', null);
    config()->set('certificates.drivers.kubernetes.token_path', '/var/run/secrets/token');

    $this->artisan('about --only=certificates')
        ->expectsOutputToContain('FILE')
        ->assertExitCode(0);
});

it('reports a missing kubernetes token and an unverified api as such', function (): void {
    config()->set('certificates.drivers.kubernetes.token', null);
    config()->set('certificates.drivers.kubernetes.token_path', null);
    config()->set('certificates.drivers.kubernetes.ca_path', null);

    $this->artisan('about --only=certificates')
        ->expectsOutputToContain('MISSING')
        ->expectsOutputToContain('UNVERIFIED')
        ->assertExitCode(0);
});

it('never leaks a secret, a key location or a host destination in about', function (): void {
    config()->set('certificates.connection', 'tenant-eu-west');
    config()->set('certificates.renewal.queue', 'certs-priority');
    config()->set('certificates.status_cache.store', 'redis-certs');
    config()->set('certificates.alerts.notifiable', 'App\\Models\\SecurityTeam');
    config()->set('certificates.drivers.kubernetes.base_url', 'https://k8s.internal.acme.test');
    config()->set('certificates.drivers.kubernetes.token', 'eyJhbGciOiJSUzI1NiJ9.super-secret-bearer');
    config()->set('certificates.drivers.kubernetes.token_path', '/var/run/secrets/kubernetes.io/serviceaccount/token');
    config()->set('certificates.drivers.kubernetes.ca_path', '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt');
    config()->set('certificates.drivers.kubernetes.namespace', 'payments-prod');
    config()->set('certificates.drivers.acme.directory', 'https://acme.internal.acme.test/directory');
    config()->set('certificates.drivers.acme.contact', 'security@acme.test');
    config()->set('certificates.drivers.acme.account.key_path', 'acme/production-account.pem');
    config()->set('certificates.drivers.acme.account.disk', 'vault');
    config()->set('certificates.drivers.acme.store.path', 'secrets/tls');

    $this->artisan('about --only=certificates')
        ->doesntExpectOutputToContain('super-secret-bearer')
        ->doesntExpectOutputToContain('serviceaccount/token')
        ->doesntExpectOutputToContain('serviceaccount/ca.crt')
        ->doesntExpectOutputToContain('k8s.internal.acme.test')
        ->doesntExpectOutputToContain('payments-prod')
        ->doesntExpectOutputToContain('acme.internal.acme.test')
        ->doesntExpectOutputToContain('security@acme.test')
        ->doesntExpectOutputToContain('production-account.pem')
        ->doesntExpectOutputToContain('vault')
        ->doesntExpectOutputToContain('secrets/tls')
        ->doesntExpectOutputToContain('tenant-eu-west')
        ->doesntExpectOutputToContain('certs-priority')
        ->doesntExpectOutputToContain('redis-certs')
        ->doesntExpectOutputToContain('SecurityTeam')
        ->assertExitCode(0);
});
