<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;

/**
 * Regression (chat review C-1): the certificate name folds `.` and `*.` into `-` and
 * `wildcard-`, so `a.b.com` and `a-b.com` (or `*.example.com` and `wildcard.example.com`)
 * share one name. Issuing the second overwrote the first row's domain, moved its owner to
 * the second tenant and reused the same secret / store material.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('refuses a domain whose certificate name is already taken by another domain', function (string $first, string $second): void {
    $victim = Tenant::query()->create(['name' => 'victim']);
    $attacker = Tenant::query()->create(['name' => 'attacker']);

    $original = Certificates::for($first)->owner($victim)->issue();

    expect(fn () => Certificates::for($second)->owner($attacker)->issue())
        ->toThrow(CertificateException::class, $second);

    $row = Certificate::query()->sole();

    expect($row->id)->toBe($original->id)
        ->and($row->domain)->toBe($first)
        ->and($row->certifiable_id)->toBe($victim->id);
})->with([
    'dot vs dash' => ['a.b.com', 'a-b.com'],
    'wildcard vs label' => ['*.example.com', 'wildcard.example.com'],
]);

it('refuses a colliding domain even when the earlier row was pruned', function (): void {
    $original = Certificates::for('a.b.com')->issue();
    $original->delete();

    expect(fn () => Certificates::for('a-b.com')->issue())->toThrow(CertificateException::class);

    expect(Certificate::withTrashed()->sole()->domain)->toBe('a.b.com');
});

it('still re-issues the same domain under its own name', function (): void {
    $first = Certificates::for('a.b.com')->issue();

    expect(Certificates::for('A.B.com')->issue()->id)->toBe($first->id);
});

it('refuses the collision under the fake as well', function (): void {
    Certificates::fake();

    Certificates::for('a.b.com')->issue();

    expect(fn () => Certificates::for('a-b.com')->issue())->toThrow(CertificateException::class, 'a-b.com');

    Certificates::assertIssuedCount(1);
});
