<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\Actions\IssueCertificateAction;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateFailed;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Events\CertificateRequested;
use RoundlyConsulting\Certificates\Exceptions\InvalidDomainException;
use RoundlyConsulting\Certificates\Exceptions\ProvisioningInProgressException;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

function issueAction(): IssueCertificateAction
{
    return app(IssueCertificateAction::class);
}

it('issues a certificate and fires the lifecycle events', function (): void {
    Event::fake([CertificateRequested::class, CertificateIssued::class]);

    $certificate = issueAction()->execute(IssueCertificateData::make('app.example.com'));

    expect($certificate->status)->toBe(CertificateStatus::Issued)
        ->and($certificate->expires_at)->not->toBeNull();

    Event::assertDispatched(CertificateRequested::class);
    Event::assertDispatched(CertificateIssued::class);
});

it('rejects an invalid domain', function (): void {
    issueAction()->execute(IssueCertificateData::make('not a domain'));
})->throws(InvalidDomainException::class);

it('records a failure and rethrows when the provider throws', function (): void {
    Event::fake([CertificateFailed::class]);

    app()->bind('certificates.failing', fn () => new class implements CertificateProvider
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
            throw new RuntimeException('provider down');
        }
    });

    app(CertificateProviderManager::class)
        ->extend('failing', fn () => app('certificates.failing'));

    try {
        issueAction()->execute(new IssueCertificateData(domain: 'fail.example.com', driver: 'failing'));
        $this->fail('Expected the provider exception to propagate.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('provider down');
    }

    expect(Certificate::query()->forDomain('fail.example.com')->first()->status)
        ->toBe(CertificateStatus::Failed);

    Event::assertDispatched(CertificateFailed::class);
});

it('throws instead of returning an unprovisioned record when the lock is contended', function (): void {
    $lock = Cache::lock('certificates:generate:generated-tls-locked-example-com', 5);
    $lock->get();

    expect(fn () => issueAction()->execute(IssueCertificateData::make('locked.example.com')))
        ->toThrow(ProvisioningInProgressException::class);

    $lock->forceRelease();
});

it('attaches an owner when provided', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme']);

    $certificate = issueAction()->execute(new IssueCertificateData(
        domain: 'owned.example.com',
        owner: $tenant,
    ));

    expect($certificate->certifiable_id)->toBe($tenant->id);
});
