<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Regression (batch 11 follow-up #78): C-9 kept a live Revoked row revoked through a sync,
 * but sync revived every pruned row the provider still lists as a fresh registration — so
 * revoke → prune → sync brought a revoked (say, compromised) certificate back as Issued.
 * A pruned Revoked row stays revoked and pruned; only a fresh issue() revives it.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('certificates.default', 'filesystem');
    config()->set('certificates.drivers.filesystem.self_signed', true);

    $this->revoked = Certificates::for('revoked.example.com')->issue();
    Certificates::revoke('revoked.example.com', 'key compromise');
    Certificate::query()->whereKey($this->revoked->id)->update(['updated_at' => now()->subDays(40)]);

    expect(Certificates::prune(30))->toBe(1);
});

it('leaves a pruned revoked certificate revoked and pruned through a sync', function (): void {
    Event::fake([CertificateIssued::class]);

    $synced = Certificates::sync('filesystem');

    $row = Certificate::withTrashed()->findOrFail($this->revoked->id);

    expect($row->status)->toBe(CertificateStatus::Revoked)
        ->and($row->trashed())->toBeTrue()
        ->and($row->last_error)->toBe('key compromise')
        ->and(Certificates::find('revoked.example.com'))->toBeNull()
        ->and($synced)->toBe(0);

    Event::assertNotDispatched(CertificateIssued::class);
});

it('still revives a pruned revoked certificate through a fresh issue()', function (): void {
    $reissued = Certificates::for('revoked.example.com')->issue();

    expect($reissued->id)->toBe($this->revoked->id)
        ->and($reissued->trashed())->toBeFalse()
        ->and($reissued->status)->toBe(CertificateStatus::Issued);
});
