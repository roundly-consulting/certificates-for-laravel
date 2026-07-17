<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Tests\Fixtures\CustomCertificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\SwappedCertificateTestCase;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;

/**
 * The model-swap proof (S) for the `certificates.model` seam, driven through the REAL
 * flows.
 *
 * This is the strong form of what `tests/Unit/Support/CertificateModelTest.php` gestured
 * at. Both halves of those tests were too weak to catch the bugs this class of test
 * exists for:
 *
 *  - a runtime `config()->set()` leaves every observer the provider hung at boot on the
 *    packaged Certificate (media #28), and a real host sets the key before boot;
 *  - `instanceof` passes for a row CREATED as the packaged class — which never fires the
 *    host's model events (permissions #31). Only the concrete class, plus a `created`
 *    event counted on the subclass itself, proves the row was made as the host's model.
 *
 * The swap is applied before boot by {@see SwappedCertificateTestCase}, which this
 * directory is bound to — Pest binds a test case per directory, not per file.
 */
it('honours a host certificate model through the registry flows', function (): void {
    expect('certificates.model')->toHonourModelSwap(CustomCertificate::class, function (): array {
        $tenant = Tenant::query()->create(['name' => 'acme']);

        // The request flow a host actually uses, through the trait's public API.
        $requested = $tenant->requestCertificate('acme.test');

        return [
            $requested,
            // The morph relation must hydrate through the seam too, not just the write.
            ...$tenant->certificates()->get()->all(),
        ];
    });
});

/**
 * The seam must hold on the READ paths as well as the write. `certificateFor()` queries
 * the registry rather than creating anything, so the swap is asserted with
 * `expectsCreation: false` — stated explicitly rather than left to be inferred, because
 * a flow that quietly creates nothing would otherwise weaken the proof in silence.
 */
it('reads an existing certificate back through the swapped model', function (): void {
    $tenant = Tenant::query()->create(['name' => 'acme']);
    $tenant->requestCertificate('acme.test');

    expect('certificates.model')->toHonourModelSwap(
        CustomCertificate::class,
        fn (): mixed => $tenant->certificateFor('acme.test'),
        expectsCreation: false,
    );
});

/**
 * The host model must be what the package's own commands query. This is the path the
 * seam is most likely to be bypassed on — a command that reaches for the packaged model
 * directly still "works" against the same table, so nothing fails until the host's
 * accessors or events are needed.
 */
it('lists the host model from the registry command', function (): void {
    $tenant = Tenant::query()->create(['name' => 'acme']);
    $tenant->requestCertificate('acme.test');

    expect(CustomCertificate::query()->count())->toBe(1);

    $this->artisan('certificates:list')
        ->expectsOutputToContain('acme.test')
        ->assertExitCode(0);
});

// The structural half of the seam — Certificate is non-final, and `certificates.model`
// really defaults to the packaged model — is pinned once in tests/ArchTest.php by
// ArchPresets::swappableModelsAreNotFinal(). It deliberately does NOT live here: that
// preset asserts the config *default*, which this directory has swapped away.
