<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Testing\Database\DriverMatrix;

function runCertificatesMigration(): void
{
    $migration = require __DIR__.'/../../database/migrations/0001_01_01_000000_create_certificates_table.php';
    $migration->up();
}

function certificatesCreateTable(string $table): string
{
    /** @var list<object{sql: string|null}> $rows */
    $rows = DB::select('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);

    return (string) ($rows[0]->sql ?? '');
}

/** @return array{type: string, nullable: string} */
function certificatesPgColumn(string $table, string $column): array
{
    /** @var list<object{data_type: string, character_maximum_length: int|null, is_nullable: string}> $rows */
    $rows = DB::select(
        'select data_type, character_maximum_length, is_nullable from information_schema.columns where table_name = ? and column_name = ?',
        [$table, $column],
    );

    $row = $rows[0] ?? null;

    if ($row === null) {
        return ['type' => 'MISSING', 'nullable' => 'MISSING'];
    }

    $type = $row->character_maximum_length === null
        ? $row->data_type
        : $row->data_type.'('.$row->character_maximum_length.')';

    return ['type' => $type, 'nullable' => $row->is_nullable];
}

$sqliteOnly = fn (): bool => DriverMatrix::driver() !== 'sqlite';
$pgsqlOnly = fn (): bool => DriverMatrix::driver() !== 'pgsql';

it('creates the polymorphic certifiable column', function (): void {
    expect(Schema::hasColumns('certificates', ['certifiable_type', 'certifiable_id']))->toBeTrue();
});

/**
 * The database `key_type` is a wholly separate axis from the ACME account key algorithm
 * (drivers.acme.account.key_type = EC/RSA). Setting one must never touch the other.
 */
it('keeps the certifiable morph independent of the acme account key algorithm', function (): void {
    config()->set('certificates.drivers.acme.account.key_type', 'RSA');
    config()->set('certificates.key_type', 'bigint');
    config()->set('certificates.table', 'kt_acme_certificates');

    Schema::dropIfExists('kt_acme_certificates');
    runCertificatesMigration();

    // The RSA account algorithm does not turn the certifiable morph into anything but bigint.
    expect(Schema::hasColumn('kt_acme_certificates', 'certifiable_id'))->toBeTrue()
        ->and(config('certificates.drivers.acme.account.key_type'))->toBe('RSA');

    Schema::dropIfExists('kt_acme_certificates');
});

/**
 * The core P1 safety property: `morphKey($n, BigInt, nullable: true)` IS `nullableMorphs($n)`.
 */
it('emits a bigint certifiable morph byte-identical to raw nullableMorphs()', function (): void {
    config()->set('certificates.key_type', 'bigint');
    config()->set('certificates.table', 'kt_ident_certificates');

    Schema::dropIfExists('kt_ident_certificates');
    runCertificatesMigration();

    Schema::dropIfExists('certifiable_raw_ref');
    Schema::create('certifiable_raw_ref', function (Blueprint $table): void {
        $table->id();
        $table->nullableMorphs('certifiable');
    });

    expect(certificatesCreateTable('kt_ident_certificates'))->toContain('"certifiable_type" varchar, "certifiable_id" integer')
        ->and(certificatesCreateTable('certifiable_raw_ref'))->toContain('"certifiable_type" varchar, "certifiable_id" integer');

    Schema::dropIfExists('kt_ident_certificates');
    Schema::dropIfExists('certifiable_raw_ref');
})->skip($sqliteOnly, 'sqlite_master is the sqlite catalog');

/**
 * The headline of P1: a uuid/ulid host gets uuid/ulid certifiable columns; bigint stays
 * bigint. Postgres tells the three apart; the morph keeps its `nullableMorphs()` nullability.
 */
it('renders each configured key type as a distinct real column type', function (string $keyType, string $expected): void {
    config()->set('certificates.key_type', $keyType);
    config()->set('certificates.table', 'kt_certificates');

    Schema::dropIfExists('kt_certificates');
    runCertificatesMigration();

    expect(certificatesPgColumn('kt_certificates', 'certifiable_id'))->toBe(['type' => $expected, 'nullable' => 'YES'])
        ->and(certificatesPgColumn('kt_certificates', 'certifiable_type')['type'])->toBe('character varying(255)');

    Schema::dropIfExists('kt_certificates');
})->with([
    'bigint' => ['bigint', 'bigint'],
    'uuid' => ['uuid', 'uuid'],
    'ulid' => ['ulid', 'character(26)'],
])->skip($pgsqlOnly, 'needs the postgres catalog to tell the key types apart');

it('refuses to migrate on an unrecognized key type instead of falling back to bigint', function (): void {
    config()->set('certificates.key_type', 'nonsense');
    config()->set('certificates.table', 'fallback_certificates');

    Schema::dropIfExists('fallback_certificates');

    // A typo in a host's config must stop the migration, never silently build bigint
    // columns for a uuid/ulid-keyed host.
    expect(function (): void {
        runCertificatesMigration();
    })->toThrow(InvalidConfigurationException::class, 'Configuration value [certificates.key_type] must be one of [bigint, uuid, ulid] (case-insensitive), [nonsense] given.');

    Schema::dropIfExists('fallback_certificates');
});
