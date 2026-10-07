<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\ProvisioningInProgressException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Support\ProvisioningLock;

/**
 * Regression (chat review C-4): the provisioning lock defaulted to 5 seconds while an ACME
 * order polls for up to attempts × seconds (30 × 2 s per loop by default). The lock expired
 * mid-issuance and a concurrent issue() of the same domain started a second order.
 */
it('keeps the provisioning lock for the whole of a slow issuance by default', function (): void {
    $nested = null;
    $orders = 0;

    $onGenerate = function () use (&$nested, &$orders): void {
        if (++$orders > 1) {
            return; // the second order — the one the lock should have prevented
        }

        // An ACME order that is still polling a few seconds in…
        Carbon::setTestNow(Carbon::now()->addSeconds(6));

        // …while another process issues the same domain.
        try {
            Certificates::for('slow.example.com')->using('slow')->issue();
        } catch (Throwable $e) {
            $nested = $e;
        }
    };

    Certificates::extend('slow', fn () => new class($onGenerate) implements CertificateProvider
    {
        public function __construct(private readonly Closure $onGenerate) {}

        public function get(): Collection
        {
            return new Collection;
        }

        public function exists(string $name, string $domain): bool
        {
            return false;
        }

        public function generate(string $name, string $domain): void
        {
            ($this->onGenerate)();
        }
    });

    $certificate = Certificates::for('slow.example.com')->using('slow')->issue();

    expect($nested)->toBeInstanceOf(ProvisioningInProgressException::class)
        ->and($orders)->toBe(1)
        ->and($certificate->status)->toBe(CertificateStatus::Issued);
});

it('defaults the lock to ten minutes and still honours a configured value', function (): void {
    expect(config('certificates.lock.locked_for_seconds'))->toBe(600)
        ->and(ProvisioningLock::seconds())->toBe(600);

    config()->set('certificates.lock.locked_for_seconds', 42);

    expect(ProvisioningLock::seconds())->toBe(42);

    config()->set('certificates.lock.locked_for_seconds', null);

    expect(ProvisioningLock::seconds())->toBe(600);
});
