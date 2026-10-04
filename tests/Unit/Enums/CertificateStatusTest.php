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

it('labels every status in the current locale', function (): void {
    app()->setLocale('sk');

    expect(CertificateStatus::labels()->all())->toBe([
        'Čakajúci', 'Vyžiadaný', 'Vydaný', 'Obnovuje sa', 'Obnovený', 'Neúspešný', 'Po platnosti', 'Zrušený',
    ])
        ->and(CertificateStatus::Issued->label())->toBe('Vydaný')
        ->and(CertificateStatus::toOptions()->get('revoked'))->toBe('Zrušený')
        ->and(CertificateStatus::tryFromLabel('Obnovený'))->toBe(CertificateStatus::Renewed);
});

it('reads every label surface from the one statuses line a host can override', function (): void {
    // A host overrides lang/vendor/certificates/<locale>/messages.php; label(), labels(),
    // toOptions() and tryFromLabel() must all follow it, never drift apart.
    app('translator')->addLines(['messages.statuses.issued' => 'Live'], 'en', 'certificates');

    expect(CertificateStatus::Issued->label())->toBe('Live')
        ->and(CertificateStatus::labels()->all())->toContain('Live')
        ->and(CertificateStatus::toOptions()->get('issued'))->toBe('Live')
        ->and(CertificateStatus::tryFromLabel('Live'))->toBe(CertificateStatus::Issued);
});

it('keeps the headline label and host JSON translations for a locale the package does not ship', function (): void {
    app()->setLocale('de');
    app('translator')->addLines(['*.Issued' => 'Ausgestellt'], 'de', '*');

    expect(CertificateStatus::Issued->label())->toBe('Ausgestellt')
        ->and(CertificateStatus::Pending->label())->toBe('Pending');
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

    expect(CertificateStatus::Failed->isTerminal())->toBeFalse()
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
    [CertificateStatus::Failed, CertificateStatus::Renewing],
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
    expect(CertificateStatus::Failed->allowedTransitions())->toBe([CertificateStatus::Renewing])
        ->and(CertificateStatus::Expired->allowedTransitions())->toBe([])
        ->and(CertificateStatus::Revoked->allowedTransitions())->toBe([]);
});
