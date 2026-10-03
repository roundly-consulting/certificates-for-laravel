<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Tests\Fixtures\PublishSandboxTestCase;

/**
 * Publishing runs against a throwaway config/ ({@see PublishSandboxTestCase}),
 * never the testbench skeleton the parallel suite loads its configuration from.
 */
it('publishes into the sandbox, never the shared skeleton', function (): void {
    expect(config_path('certificates.php'))->toContain('certificates-publish-');
});

it('publishes the config file', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'certificates-config'])->assertExitCode(0);

    expect(file_exists(config_path('certificates.php')))->toBeTrue()
        ->and((string) file_get_contents(config_path('certificates.php')))
        ->toBe((string) file_get_contents(__DIR__.'/../../config/certificates.php'));
});
