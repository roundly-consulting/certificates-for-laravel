<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Enums\Helpers;

it('adopts the shared enums Helpers trait', function (): void {
    expect(in_array(Helpers::class, class_uses(CertificateStatus::class), true))->toBeTrue();
});

it('resolves the same label for every case as the old hand-rolled labels', function (): void {
    expect(CertificateStatus::Pending->label())->toBe('Pending')
        ->and(CertificateStatus::Requested->label())->toBe('Requested')
        ->and(CertificateStatus::Issued->label())->toBe('Issued')
        ->and(CertificateStatus::Renewing->label())->toBe('Renewing')
        ->and(CertificateStatus::Renewed->label())->toBe('Renewed')
        ->and(CertificateStatus::Failed->label())->toBe('Failed')
        ->and(CertificateStatus::Expired->label())->toBe('Expired')
        ->and(CertificateStatus::Revoked->label())->toBe('Revoked');
});

it('does not resolve labels through the deleted status lang sub-array', function (): void {
    // The trait derives labels from Str::headline(value); the messages.status.*
    // keys were removed. Overriding one must not change the label — a guard against
    // silently reintroducing the hand-rolled, lang-backed label().
    app('translator')->addLines(['messages.status.issued' => 'TAMPERED'], 'en', 'certificates');

    expect(CertificateStatus::Issued->label())->toBe('Issued');
});

it('exposes the enums trait surface', function (): void {
    expect(CertificateStatus::values()->all())->toBe([
        'pending', 'requested', 'issued', 'renewing', 'renewed', 'failed', 'expired', 'revoked',
    ])
        ->and(CertificateStatus::labels()->all())->toBe([
            'Pending', 'Requested', 'Issued', 'Renewing', 'Renewed', 'Failed', 'Expired', 'Revoked',
        ])
        ->and(CertificateStatus::names()->all())->toContain('Pending', 'Revoked')
        ->and(CertificateStatus::toOptions()->get('issued'))->toBe('Issued')
        ->and(CertificateStatus::validationRule())->toBe('in:pending,requested,issued,renewing,renewed,failed,expired,revoked')
        ->and(CertificateStatus::tryFromLabel('Renewed'))->toBe(CertificateStatus::Renewed)
        ->and(CertificateStatus::tryFromName('Failed'))->toBe(CertificateStatus::Failed)
        ->and(CertificateStatus::options())->toHaveCount(8);
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
