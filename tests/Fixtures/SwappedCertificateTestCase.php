<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use RoundlyConsulting\Certificates\Tests\TestCase;

/**
 * The suite's base case with `certificates.model` already pointed at
 * {@see CustomCertificate} BEFORE the providers boot.
 *
 * Boot order is the whole point. The providers hang observers and relationship wiring on
 * whatever `certificates.model` names at boot, and the registry's migrations run before
 * the test body — so a `config()->set()` inside a test reads back correctly while every
 * listener stays on the packaged Certificate (media #28). Every swap test this package
 * had did exactly that: a body-time `config()->set()` plus an `instanceof`. Both halves
 * were too weak to catch the bugs the class of test exists for, and a real host sets the
 * key in `config/certificates.php` — i.e. before boot.
 *
 * Note the `array_merge(parent::configBeforeBoot(), …)`: dropping it would silently
 * discard the whole kubernetes driver config the parent wires, with no error and no red.
 */
abstract class SwappedCertificateTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'certificates.model' => CustomCertificate::class,
            'certificates.default' => 'array',
        ]);
    }
}
