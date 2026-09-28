<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\Actions\RenewCertificateAction;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
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
    app(CertificateProviderManager::class)
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

/**
 * Regression: a provider error mid-renewal left the row in Renewing — a status whose only
 * exits are Renewed and Failed, and which the expiring() scope ignores — so it was never
 * retried and no alert fired. It now fails loudly: Failed, CertificateFailed, rethrown.
 */
it('marks the certificate failed and fires the failed event when the provider throws', function (): void {
    Event::fake([CertificateFailed::class, CertificateRenewed::class]);

    app(CertificateProviderManager::class)->extend('broken', fn () => new class implements CertificateProvider
    {
        public function get(): Collection
        {
            return collect();
        }

        public function exists(string $name, string $domain): bool
        {
            return false;
        }

        public function generate(string $name, string $domain): void
        {
            throw new RuntimeException('ACME order rejected');
        }
    });

    $certificate = Certificate::factory()->issued()->create(['driver' => 'broken']);

    expect(fn () => app(RenewCertificateAction::class)->execute($certificate))
        ->toThrow(RuntimeException::class, 'ACME order rejected');

    expect($certificate->fresh())
        ->status->toBe(CertificateStatus::Failed)
        ->last_error->toBe('ACME order rejected');

    Event::assertDispatched(CertificateFailed::class, fn (CertificateFailed $e): bool => $e->reason === 'ACME order rejected');
    Event::assertNotDispatched(CertificateRenewed::class);
});
