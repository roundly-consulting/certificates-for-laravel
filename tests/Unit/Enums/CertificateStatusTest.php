<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Enums\CertificateStatus;

it('resolves a translatable label for every case', function (): void {
    expect(CertificateStatus::Issued->label())->toBe('Issued')
        ->and(CertificateStatus::Pending->label())->toBe('Pending')
        ->and(CertificateStatus::Failed->label())->toBe('Failed');
});

it('exposes a colour for every case', function (CertificateStatus $status): void {
    expect($status->color())->toBeIn(['green', 'amber', 'red']);
})->with(CertificateStatus::cases());

it('reports active and terminal states', function (): void {
    expect(CertificateStatus::Issued->isActive())->toBeTrue()
        ->and(CertificateStatus::Renewed->isActive())->toBeTrue()
        ->and(CertificateStatus::Pending->isActive())->toBeFalse();

    expect(CertificateStatus::Failed->isTerminal())->toBeTrue()
        ->and(CertificateStatus::Revoked->isTerminal())->toBeTrue()
        ->and(CertificateStatus::Expired->isTerminal())->toBeTrue()
        ->and(CertificateStatus::Issued->isTerminal())->toBeFalse();
});

it('allows the documented transitions', function (CertificateStatus $from, CertificateStatus $to): void {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    [CertificateStatus::Pending, CertificateStatus::Requested],
    [CertificateStatus::Requested, CertificateStatus::Issued],
    [CertificateStatus::Requested, CertificateStatus::Failed],
    [CertificateStatus::Issued, CertificateStatus::Renewing],
    [CertificateStatus::Issued, CertificateStatus::Expired],
    [CertificateStatus::Issued, CertificateStatus::Revoked],
    [CertificateStatus::Renewing, CertificateStatus::Renewed],
    [CertificateStatus::Renewing, CertificateStatus::Failed],
    [CertificateStatus::Renewed, CertificateStatus::Renewing],
    [CertificateStatus::Renewed, CertificateStatus::Expired],
    [CertificateStatus::Renewed, CertificateStatus::Revoked],
]);

it('rejects every disallowed transition pair', function (): void {
    foreach (CertificateStatus::cases() as $from) {
        $allowed = $from->allowedTransitions();

        foreach (CertificateStatus::cases() as $to) {
            $expected = in_array($to, $allowed, true);

            expect($from->canTransitionTo($to))->toBe($expected);
        }
    }
});

it('treats terminal statuses as having no transitions', function (): void {
    expect(CertificateStatus::Failed->allowedTransitions())->toBe([])
        ->and(CertificateStatus::Expired->allowedTransitions())->toBe([])
        ->and(CertificateStatus::Revoked->allowedTransitions())->toBe([]);
});
