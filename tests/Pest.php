<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A committed ACME account key PEM (`ec` or `rsa`).
 *
 * These two keys are fixed on purpose: every frozen vector in this suite — the
 * JWK thumbprints and the RS256 flattened JWS — was computed from them with the
 * pre-crypto implementation, so drift in the JWS or the thumbprint surfaces as a
 * failing byte comparison here rather than as a rejected certificate order.
 */
function accountKeyPem(string $type): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/keys/acme-account-'.$type.'.pem');
}
