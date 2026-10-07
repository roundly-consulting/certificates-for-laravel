<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Certificates\Tests\Fixtures\AcmeTestCa;

/**
 * Regression (chat review C-15): the challenge token came from the CA unchecked, and the
 * HTTP-01 solver joins it onto its challenge path. A malicious CA (or a MITM with
 * `verify=false`) could hand out `../certificates/<name>/private.key` and have the solver
 * overwrite — then delete — another certificate's private key. RFC 8555 §8.3: a token is
 * base64url only.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

it('refuses a challenge token that is not base64url', function (string $token): void {
    Storage::disk('local')->put('certificates/generated-tls-victim-com/private.key', 'victim key');

    AcmeTestCa::fake(selfSignedCertificate(['app.com'])->leaf()->pem(), [
        'https://ca.test/authz/1' => Http::response(AcmeTestCa::authorization('pending', ['http-01' => $token]), 200, ['Replay-Nonce' => 'n']),
    ]);

    expect(fn () => AcmeTestCa::client()->challengeFor('https://ca.test/authz/1', 'http-01'))
        ->toThrow(AcmeException::class, 'token');

    expect(fn () => AcmeTestCa::provider()->generate('generated-tls-app-com', 'app.com'))
        ->toThrow(AcmeException::class, 'token');

    expect(Storage::disk('local')->get('certificates/generated-tls-victim-com/private.key'))->toBe('victim key')
        ->and(Storage::disk('local')->allFiles('acme-challenge'))->toBe([])
        ->and(AcmeTestCa::hits('https://ca.test/chall/http-01'))->toBe(0);
})->with([
    'traversal into another certificate' => ['../certificates/generated-tls-victim-com/private.key'],
    'parent directory' => ['../x'],
    'empty' => [''],
    'padding' => ['abc='],
]);

it('accepts a base64url token', function (): void {
    AcmeTestCa::fake(selfSignedCertificate(['app.com'])->leaf()->pem(), [
        'https://ca.test/authz/1' => Http::response(AcmeTestCa::authorization('pending', ['http-01' => 'evaGxfADs6pSRb2LAv9IZf17Dt3juxGJ-PCt92wr-oA']), 200, ['Replay-Nonce' => 'n']),
    ]);

    expect(AcmeTestCa::client()->challengeFor('https://ca.test/authz/1', 'http-01')->token)
        ->toBe('evaGxfADs6pSRb2LAv9IZf17Dt3juxGJ-PCt92wr-oA');
});
