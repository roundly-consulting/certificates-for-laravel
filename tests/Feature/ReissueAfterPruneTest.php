<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

/**
 * Regression: prune() soft-deletes, but unique(driver, name) still covers trashed rows and
 * firstOrNew() ignores them — so the next issue (or sync) of a pruned domain inserted a
 * duplicate and died on a UniqueConstraintViolationException. The domain could never be
 * issued on that driver again.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'array');

    $this->pruned = Certificates::for('pruned.example.com')->owner(Tenant::query()->create(['name' => 'Old']))->issue();
    Certificates::revoke($this->pruned, 'decommissioned');
    Certificate::query()->whereKey($this->pruned->id)->update(['updated_at' => now()->subDays(40)]);

    expect(Certificates::prune(30))->toBe(1);
});

it('restores and re-issues the pruned row instead of crashing', function (): void {
    $reissued = Certificates::issue(IssueCertificateData::make('pruned.example.com'));

    expect($reissued->id)->toBe($this->pruned->id)
        ->and($reissued->trashed())->toBeFalse()
        ->and($reissued->status)->toBe(CertificateStatus::Issued)
        ->and($reissued->last_error)->toBeNull()
        ->and($reissued->certifiable_id)->toBeNull()
        ->and(Certificate::withTrashed()->forDomain('pruned.example.com')->count())->toBe(1)
        ->and(Certificates::find('pruned.example.com')?->is($reissued))->toBeTrue();
});

it('restores a pruned row the provider still reports when syncing', function (): void {
    app(CertificateProviderManager::class)->extend('listing', fn (): CertificateProvider => new class implements CertificateProvider
    {
        public function get(): Collection
        {
            return collect([new RemoteCertificate('generated-tls-pruned-example-com', 'pruned.example.com')]);
        }

        public function exists(string $name, string $domain): bool
        {
            return true;
        }

        public function generate(string $name, string $domain): void {}
    });

    Certificate::withTrashed()->whereKey($this->pruned->id)->update(['driver' => 'listing']);

    expect(Certificates::sync('listing'))->toBe(1)
        ->and(Certificates::find('pruned.example.com'))
        ->id->toBe($this->pruned->id)
        ->status->toBe(CertificateStatus::Issued)
        ->and(Certificate::withTrashed()->forDomain('pruned.example.com')->count())->toBe(1);
});
