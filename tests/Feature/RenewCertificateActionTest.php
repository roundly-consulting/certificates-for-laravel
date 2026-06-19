<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\Actions\RenewCertificateAction;
use RoundlyConsulting\Certificates\CertificateManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateRenewed;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Models\Certificate;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('renews an active certificate and fires the renewed event', function (): void {
    Event::fake([CertificateRenewed::class]);

    $certificate = Certificate::factory()->issued()->create(['driver' => 'array']);

    app(RenewCertificateAction::class)->execute($certificate);

    expect($certificate->fresh())
        ->status->toBe(CertificateStatus::Renewed)
        ->last_renewed_at->not->toBeNull();

    Event::assertDispatched(CertificateRenewed::class);
});

it('rejects renewing a terminal certificate', function (): void {
    $certificate = Certificate::factory()->failed()->create(['driver' => 'array']);

    app(RenewCertificateAction::class)->execute($certificate);
})->throws(CertificateException::class);

it('falls back to a default expiry for non-reporting providers', function (): void {
    app(CertificateManager::class)
        ->extend('plain', fn () => new class implements CertificateProvider
        {
            public function get(): Collection
            {
                return collect();
            }

            public function exists(string $name, string $domain): bool
            {
                return true;
            }

            public function generate(string $name, string $domain): void {}
        });

    $certificate = Certificate::factory()->issued()->create(['driver' => 'plain']);

    app(RenewCertificateAction::class)->execute($certificate);

    expect($certificate->fresh())
        ->status->toBe(CertificateStatus::Renewed)
        ->expires_at->not->toBeNull();
});
