<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\DataTransferObjects\AcmeChallenge;

function challenge(string $type = 'http-01', string $domain = 'app.com'): AcmeChallenge
{
    return new AcmeChallenge(
        type: $type,
        domain: $domain,
        token: 'tok123',
        keyAuthorization: 'tok123.thumb',
        authorizationUrl: 'https://acme.test/authz/1',
        challengeUrl: 'https://acme.test/chall/1',
    );
}

it('computes the http-01 path', function (): void {
    expect(challenge()->httpPath())->toBe('/.well-known/acme-challenge/tok123');
});

it('computes the dns-01 record name and value', function (): void {
    $challenge = challenge('dns-01', '*.app.com');

    expect($challenge->dnsRecordName())->toBe('_acme-challenge.app.com')
        ->and($challenge->dnsRecordValue())->not->toContain('+', '/', '=');
});

it('strips a leading wildcard from the dns record name', function (): void {
    expect(challenge('dns-01', 'app.com')->dnsRecordName())->toBe('_acme-challenge.app.com');
});
