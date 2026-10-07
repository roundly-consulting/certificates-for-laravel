<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Alerts\Alert;
use RoundlyConsulting\Alerts\Enums\Status;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\AlertTeam;

/**
 * Regression (chat review C-10): the expiry check loaded its certificate by id on the default
 * connection. A check raised for a tenant registry (`certificates:check --connection=tenant`,
 * a lifecycle event from a tenant row, a monitorExpiry() schedule) evaluated whichever row has
 * that id on the default connection — another certificate, or "skipped".
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'array');
    config()->set('database.connections.tenant', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    Schema::connection('tenant')->create('certificates', function (Blueprint $table): void {
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

    // The same id on both connections: a healthy default row, a tenant row about to lapse.
    $this->default = Certificate::factory()->forDomain('default.example.com')->issued()->create(['driver' => 'array']);
    $this->tenant = Certificate::factory()->connection('tenant')->forDomain('tenant.example.com')->expiring(3)->create(['driver' => 'array']);

    expect($this->tenant->id)->toBe($this->default->id);

    $this->team = AlertTeam::query()->create(['name' => 'ops']);
    config()->set('certificates.alerts.notifiable', AlertTeam::class);
    app()->instance(AlertTeam::class, $this->team);
});

it('alerts on the tenant certificate from certificates:check --connection', function (): void {
    $this->artisan('certificates:check --connection=tenant --alert')->assertSuccessful();

    $alert = Alert::query()->sole();

    expect($alert->message)->toContain('tenant.example.com')
        ->and($alert->meta['band'] ?? null)->toBe('critical');
});

it('alerts on the tenant certificate a lifecycle event names', function (): void {
    config()->set('certificates.alerts.enabled', true);

    Certificates::on('tenant')->revoke('tenant.example.com', 'key compromise');

    expect(Alert::query()->sole()->message)->toContain('tenant.example.com');
});

it('checks a monitorExpiry() schedule on the certificate\'s own connection', function (): void {
    $row = Certificates::monitorExpiry($this->tenant, $this->team)->save();

    expect($row->meta['connection'] ?? null)->toBe('tenant');

    $result = (new CertificateExpiryCheck(healthCheck: $row))->check();

    expect($result->status)->toBe(Status::Failed)
        ->and($result->meta['domain'])->toBe('tenant.example.com');
});

it('runs the registry-wide check on a chosen connection', function (): void {
    expect((new CertificateExpiryCheck)->check()->status)->toBe(Status::Ok)
        ->and((new CertificateExpiryCheck(connection: 'tenant'))->check()->status)->toBe(Status::Failed);
});

it('keeps the default connection for a check that names none', function (): void {
    $this->tenant->forceFill(['expires_at' => CarbonImmutable::now()->addDays(90)])->save();

    expect((new CertificateExpiryCheck(certificateId: $this->default->id))->check()->meta['domain'])->toBe('default.example.com');
});
