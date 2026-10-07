<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Facades\Certificates;

/**
 * Regression (chat review C-9): revoke() is registry-only — the material stays in the
 * backend — and sync() overwrote the Revoked row with the provider's Issued, so a revoked
 * (say, compromised) certificate quietly came back as live. Revoked is terminal: only a
 * fresh issue() revives the row.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('certificates.default', 'filesystem');
    config()->set('certificates.drivers.filesystem.self_signed', true);
});

it('keeps a revoked certificate revoked through a sync', function (): void {
    Certificates::for('revoked.example.com')->issue();
    Certificates::revoke('revoked.example.com', 'key compromise');

    expect(Certificates::sync('filesystem'))->toBe(1);

    expect(Certificates::find('revoked.example.com'))
        ->status->toBe(CertificateStatus::Revoked)
        ->last_error->toBe('key compromise');
});

it('still revives a revoked row through a fresh issue()', function (): void {
    Certificates::for('revoked.example.com')->issue();
    Certificates::revoke('revoked.example.com');

    expect(Certificates::for('revoked.example.com')->issue()->status)->toBe(CertificateStatus::Issued);
});
