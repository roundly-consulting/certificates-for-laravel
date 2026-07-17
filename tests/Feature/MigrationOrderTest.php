<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Certificates\CertificatesServiceProvider;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Certificates ships two migrations and zero foreign keys — the registry's owner is a
 * polymorphic `nullableMorphs('certifiable')`, deliberately unconstrained because a
 * host's certifiable entity can live in any table.
 *
 * That shape decides what is worth pinning here, and it is worth being explicit:
 *
 *  - **M IS adopted, with `foreignKeys: 0`** — and the row's spec said to skip it. The
 *    legend's "packages with FK edges" is not the whole criterion:
 *    `MigrationGraph::assertRunnable()` checks two INDEPENDENT things, and only one is
 *    FK-related. The other pins that a `Schema::table()` ALTER sorts at or after the
 *    CREATE it alters — which is exactly this package's second migration
 *    (`add_domains_to_certificates_table`), and exactly the bug that half was built for
 *    (approvals #2). `foreignKeys: 0` is a live pin, not a formality: it forces a
 *    deliberate update the day a foreign key arrives.
 *  - **The R negative control is NOT adoptable.** `toRejectBrokenOrderOnConnection`
 *    asserts the engine *refuses* a reordered set. With no foreign keys Postgres has
 *    nothing to refuse — though note the ALTER would in fact fail reversed, which is
 *    precisely why the structural pin above is the right tool for it and the engine
 *    probe is not: the probe would pass for a reason unrelated to what it claims to
 *    test. Rows with migrations but no FK edges adopt `toApplyOnConnection` only.
 */
$migrations = __DIR__.'/../../database/migrations';

/**
 * M — the structural order pin. SQLite happily creates a table pointing at a missing
 * parent and only complains at insert time, which is how five packages shipped
 * uninstallable migration orders under green suites.
 */
it('has a runnable migration order', function () use ($migrations): void {
    expect($migrations)->toHaveRunnableMigrationOrder(foreignKeys: 0, tableResolvers: [
        // Both migrations name their table through `config('certificates.table')` rather
        // than a literal, so the parser cannot know the CREATE and the ALTER touch the
        // same table — and it says so and FAILS rather than silently dropping the edge
        // and pretending the order is proven. Mapping the expression is what lets the
        // ALTER-sorts-after-its-CREATE half actually run.
        "(string) config('certificates.table', 'certificates')" => 'certificates',

        // The ALTER hoists the same expression into a local first and passes the
        // variable, so the raw argument the parser sees is `$table`.
        '$table' => 'certificates',
    ]);
});

/**
 * P — the publish-only guards. The fleet publishes migrations timestamped rather than
 * auto-loading them; doing both runs both copies and dies on a duplicate table (bug #5,
 * on three packages). `count: 2` pins the file count so neither check can pass over an
 * empty or relocated directory.
 */
it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(CertificatesServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migrations timestamp-injected into the host', function (): void {
    expect(CertificatesServiceProvider::class)->toPublishMigrationsTimestamped('certificates-migrations', 2);
});

/**
 * R — the real-engine proof. This DDL had never met a real engine: the suite ran on
 * SQLite for the package's whole life. `migrations: 2` pins the count, and the
 * expectation additionally fails a set that "applies cleanly" while creating no tables —
 * an empty `up()` otherwise passes and proves nothing.
 *
 * Gated on a reachable engine so a run with no Postgres skips VISIBLY rather than
 * passing vacuously. On the pgsql leg its skip count must be zero.
 */
it('applies its migrations on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 2);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * The columns the drivers genuinely render differently: `jsonb` (vs sqlite text) for
 * `meta` and `domains`, and the `nullableMorphs` pair. Pinning a round-trip on whatever
 * engine the leg configured proves the column types are usable rather than merely
 * creatable.
 *
 * Asserted key-by-key rather than against a whole literal array: Postgres `jsonb` sorts
 * object keys by (length, bytes) and canonicalises whitespace, so `toBe(['issuer' => …,
 * 'renewals' => …])` would compare an insertion order the engine never promised to keep.
 * The value TYPES still matter — `renewals` must come back the int 3, not "3" — so each
 * key keeps a strict assertion rather than being loosened to `toEqual`.
 */
it('round-trips the registry columns on the configured engine', function (): void {
    $certificate = CertificateModel::class()::query()->create([
        'name' => 'primary',
        'domain' => 'example.test',
        'driver' => 'acme',
        'meta' => ['issuer' => 'test-ca', 'renewals' => 3],
        'domains' => ['example.test', 'www.example.test'],
    ]);

    $fresh = $certificate->fresh();

    expect($fresh->meta['issuer'] ?? null)->toBe('test-ca')
        ->and($fresh->meta['renewals'] ?? null)->toBe(3)
        ->and($fresh->domains)->toBe(['example.test', 'www.example.test'])
        ->and($fresh->name)->toBe('primary')
        // The driver actually under test. This is strictly stronger than reading a skip
        // count by hand: it compares the env-DECLARED driver against what the connection
        // itself answers, so a leg that quietly stayed on sqlite fails here rather than
        // passing as a "postgres" run.
        ->and(DatabaseDriver::current())->toBe(DatabaseDriver::from(DriverMatrix::driver()))
        ->and(DB::connection()->getDriverName())->toBe(DriverMatrix::driver());
});
