<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Certificates\CertificatesServiceProvider;

/**
 * The package ships one CREATE and one ALTER. Publishing preserves the source
 * directory's order, so that order has to be runnable end to end: the table must
 * exist before the ALTER adds `domains` to it. This runs the *published* files —
 * under their published names, into a database that starts empty — which is
 * exactly what a host does.
 */
beforeEach(function (): void {
    $this->publishedPath = sys_get_temp_dir().'/certificates-migration-order-'.bin2hex(random_bytes(6));
    $this->publishedDatabase = $this->publishedPath.'/database.sqlite';

    File::makeDirectory($this->publishedPath, recursive: true);
    File::put($this->publishedDatabase, '');

    foreach (ServiceProvider::pathsToPublish(CertificatesServiceProvider::class, 'certificates-migrations') as $source => $target) {
        File::copy($source, $this->publishedPath.'/'.basename((string) $target));
    }

    config()->set('database.connections.published', [
        'driver' => 'sqlite',
        'database' => $this->publishedDatabase,
        'prefix' => '',
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->publishedPath);
});

it('migrates the published files clean from an empty database', function (): void {
    $schema = Schema::connection('published');

    expect($schema->hasTable('certificates'))->toBeFalse();

    $this->artisan('migrate', [
        '--database' => 'published',
        '--path' => $this->publishedPath,
        '--realpath' => true,
    ])->assertExitCode(0);

    expect($schema->hasTable('certificates'))->toBeTrue()
        // The ALTER ran against a table that already existed.
        ->and($schema->hasColumn('certificates', 'domains'))->toBeTrue();
});

it('publishes the alter under a name that sorts after the create it depends on', function (): void {
    $published = array_map(
        static fn (string $target): string => basename($target),
        array_values(ServiceProvider::pathsToPublish(CertificatesServiceProvider::class, 'certificates-migrations')),
    );

    $position = static function (string $needle) use ($published): int {
        foreach ($published as $index => $name) {
            if (str_contains($name, $needle)) {
                return $index;
            }
        }

        return -1;
    };

    expect($position('create_certificates_table'))->toBeLessThan($position('add_domains_to_certificates_table'));
});
