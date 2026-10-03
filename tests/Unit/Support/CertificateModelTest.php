<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use RoundlyConsulting\Certificates\Tests\Fixtures\CustomCertificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

it('resolves the packaged model by default', function (): void {
    expect(CertificateModel::class())->toBe(Certificate::class);
});

it('resolves a host subclass configured at certificates.model', function (): void {
    config()->set('certificates.model', CustomCertificate::class);

    expect(CertificateModel::class())->toBe(CustomCertificate::class);
});

it('refuses a foreign model instead of falling back to the packaged one', function (): void {
    // The toolkit refuses any class that is not the packaged model or a subclass of it.
    config()->set('certificates.model', Tenant::class);

    expect(fn (): string => CertificateModel::class())->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [certificates.model] must be a class-string of ['.Certificate::class.'], ['.Tenant::class.'] given.',
    );
});

it('throws when the configured model is not an eloquent model at all', function (): void {
    config()->set('certificates.model', 'App\\Models\\Nope');

    CertificateModel::class();
})->throws(InvalidConfigurationException::class);

it('issues into the configured host model', function (): void {
    config()->set('certificates.model', CustomCertificate::class);
    config()->set('certificates.default', 'array');

    $tenant = Tenant::query()->create(['name' => 'acme']);
    $certificate = $tenant->requestCertificate('acme.test');

    expect($certificate)->toBeInstanceOf(CustomCertificate::class)
        ->and($tenant->certificates()->first())->toBeInstanceOf(CustomCertificate::class);
});

it('lists the configured host model from the registry', function (): void {
    config()->set('certificates.model', CustomCertificate::class);
    config()->set('certificates.default', 'array');

    $tenant = Tenant::query()->create(['name' => 'acme']);
    $tenant->requestCertificate('acme.test');

    $this->artisan('certificates:list')
        ->expectsOutputToContain('acme.test')
        ->assertExitCode(0);
});
