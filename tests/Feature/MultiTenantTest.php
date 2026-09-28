<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');

    config()->set('database.connections.tenant', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    // Build the certificates schema on the tenant connection.
    $schema = Schema::connection('tenant');
    $schema->create('certificates', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->index();
        $table->string('domain')->index();
        $table->json('domains')->nullable();
        $table->string('driver')->default('array')->index();
        $table->string('status')->default(CertificateStatus::Pending->value)->index();
        $table->string('issuer')->nullable();
        $table->string('serial')->nullable();
        $table->string('fingerprint')->nullable();
        $table->nullableMorphs('certifiable');
        $table->timestamp('issued_at')->nullable();
        $table->timestamp('expires_at')->nullable()->index();
        $table->timestamp('last_renewed_at')->nullable();
        $table->text('last_error')->nullable();
        $table->json('meta')->nullable();
        $table->timestamps();
        $table->softDeletes();
        $table->unique(['driver', 'name']);
    });
});

it('writes to the tenant connection and isolates it from the default', function (): void {
    Certificates::on('tenant')->issue(
        IssueCertificateData::make('tenant.com'),
    );

    expect(Certificate::on('tenant')->where('domain', 'tenant.com')->exists())->toBeTrue()
        ->and(Certificate::query()->where('domain', 'tenant.com')->exists())->toBeFalse();
});

it('finds records on the bound connection only', function (): void {
    Certificates::on('tenant')->issue(
        IssueCertificateData::make('tenant.com'),
    );

    expect(Certificates::find('tenant.com'))->toBeNull()
        ->and(Certificates::on('tenant')->find('tenant.com'))->not->toBeNull();
});

it('returns a distinct clone leaving the original unchanged', function (): void {
    $service = app(CertificatesManager::class);
    $bound = $service->on('tenant');

    expect($bound)->not->toBe($service);

    // The original still targets the default connection.
    Certificates::on('tenant')->issue(
        IssueCertificateData::make('tenant.com'),
    );

    expect($service->find('tenant.com'))->toBeNull();
});

it('resets to the default connection with on(null)', function (): void {
    Certificate::factory()->create(['domain' => 'default.com', 'driver' => 'array']);

    $found = Certificates::on('tenant')->on(null)->find('default.com');

    expect($found)->not->toBeNull();
});

it('operates on tenant rows from the list command', function (): void {
    Certificates::on('tenant')->issue(
        IssueCertificateData::make('tenant.com'),
    );

    $this->artisan('certificates:list --connection=tenant')
        ->expectsOutputToContain('tenant.com')
        ->assertSuccessful();
});

it('syncs, renews, revokes and prunes on the bound connection only', function (): void {
    $provider = new ArrayProvider;
    $provider->generate('generated-tls-synced-com', 'synced.com');
    Certificates::extend('array', fn (): CertificateProvider => $provider);

    $this->artisan('certificates:sync --connection=tenant')->assertSuccessful();

    expect(Certificate::on('tenant')->where('domain', 'synced.com')->exists())->toBeTrue()
        ->and(Certificate::query()->where('domain', 'synced.com')->exists())->toBeFalse()
        ->and(Certificates::on('tenant')->renew('synced.com')->status)->toBe(CertificateStatus::Renewed)
        ->and(Certificates::on('tenant')->revoke('synced.com')->getConnectionName())->toBe('tenant');

    $this->travel(31)->days();

    $this->artisan('certificates:prune --connection=tenant')
        ->expectsOutputToContain('Pruned 1 certificate(s).')
        ->assertSuccessful();

    expect(Certificate::on('tenant')->count())->toBe(0);
});

it('scans the bound connection from the check command', function (): void {
    Certificate::factory()->connection('tenant')->expiring(3)->create(['domain' => 'soon.tenant.com', 'driver' => 'array']);

    $this->artisan('certificates:check --connection=tenant')
        ->expectsOutputToContain('soon.tenant.com')
        ->assertSuccessful();

    expect(Certificates::on('tenant')->expiring(7)->pluck('domain')->all())->toBe(['soon.tenant.com'])
        ->and(Certificates::expiring(7))->toBeEmpty();
});

/**
 * Regression: the README promised "Every command also accepts --connection=", but
 * certificates:issue had no such option — it failed with "The --connection option does
 * not exist" — and called the action directly, bypassing the manager's connection.
 */
it('issues from the command line onto the chosen connection', function (): void {
    $this->artisan('certificates:issue', ['domain' => 'cli.tenant.com', '--connection' => 'tenant'])
        ->assertExitCode(0);

    expect(Certificate::on('tenant')->where('domain', 'cli.tenant.com')->exists())->toBeTrue()
        ->and(Certificate::query()->where('domain', 'cli.tenant.com')->exists())->toBeFalse();
});
