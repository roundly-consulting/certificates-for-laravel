<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use RoundlyConsulting\Certificates\Tests\TestCase;

/**
 * The suite's base case with `config/` pointed at a throwaway directory per test, set
 * before the providers boot — their publish destinations are fixed then.
 *
 * Publishing into the shared testbench skeleton raced the parallel suite: another
 * process booting while the published `certificates.php` was being deleted listed the
 * file, found no real path for it and failed in `LoadConfiguration` with "Path must not
 * be empty" — in whichever test happened to be booting.
 */
abstract class PublishSandboxTestCase extends TestCase
{
    private string $sandbox = '';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->sandbox = sys_get_temp_dir().'/certificates-publish-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->sandbox.'/config');

        $app->useConfigPath($this->sandbox.'/config');
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            File::deleteDirectory($this->sandbox);
        }

        parent::tearDown();
    }
}
