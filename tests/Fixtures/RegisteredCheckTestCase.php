<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use RoundlyConsulting\Certificates\Tests\TestCase;

/**
 * The suite's base case with the expiry check's registration switched on, and its
 * notification channels set, BEFORE the providers boot.
 *
 * Boot order is the shape of the thing under test rather than a convenience:
 * `certificates.alerts.register_check` is read in the provider's `boot()`, and the
 * channels must be resolved at that same moment because that is when the check instance
 * is constructed and handed to alerts. A `config()->set()` in a test body would run
 * after the check was already registered — which is exactly how a key like this stays
 * dead without anything going red.
 *
 * Note the `array_merge(parent::configBeforeBoot(), …)`: dropping it would silently
 * discard the kubernetes driver config the parent wires.
 */
abstract class RegisteredCheckTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'certificates.alerts.register_check' => true,
            'certificates.alerts.channels' => ['slack', 'mail'],
        ]);
    }
}
