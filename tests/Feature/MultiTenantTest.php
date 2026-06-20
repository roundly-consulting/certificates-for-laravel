<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Certificates\CertificateService;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

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
    $service = app(CertificateService::class);
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
