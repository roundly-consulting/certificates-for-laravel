<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Certificates\Tests\Fixtures\AcmeTestCa;

/**
 * Regression (chat review C-13): finalize() took the order URL from the response's Location
 * header only (RFC 8555 §7.4 does not require one there; the URL is known from newOrder), and
 * both poll loops read an HTTP error's problem document as "still pending" — so a refused
 * validation or a missing Location ended in a misleading "timed out" after every attempt.
 */
beforeEach(function (): void {
    Storage::fake('local');
    Http::preventStrayRequests();
});

$nonce = ['Replay-Nonce' => 'n'];
$problem = fn (int $status, string $detail): array => [
    'type' => 'urn:ietf:params:acme:error:unauthorized',
    'detail' => $detail,
    'status' => $status,
];

it('fails at once with the CA\'s detail when the authorization poll is refused', function () use ($nonce, $problem): void {
    AcmeTestCa::fake(selfSignedCertificate(['app.com'])->leaf()->pem(), [
        'https://ca.test/authz/1' => Http::sequence([
            Http::response(AcmeTestCa::authorization('pending'), 200, $nonce),
            Http::response($problem(403, 'Invalid response from http://app.com/.well-known/acme-challenge/tok123'), 403, $nonce),
        ]),
    ]);

    expect(fn () => AcmeTestCa::provider()->generate('generated-tls-app-com', 'app.com'))
        ->toThrow(AcmeException::class, 'Invalid response from http://app.com');

    expect(AcmeTestCa::hits('https://ca.test/authz/1'))->toBe(2);
});

it('fails at once with the CA\'s detail when the order poll is refused', function () use ($nonce, $problem): void {
    AcmeTestCa::fake(selfSignedCertificate(['app.com'])->leaf()->pem(), [
        'https://ca.test/order/1' => Http::response($problem(404, 'No such order'), 404, $nonce),
    ]);

    expect(fn () => AcmeTestCa::provider()->generate('generated-tls-app-com', 'app.com'))
        ->toThrow(AcmeException::class, 'No such order');

    expect(AcmeTestCa::hits('https://ca.test/order/1'))->toBe(1);
});

it('polls the order it created when finalize answers without a Location header', function () use ($nonce): void {
    AcmeTestCa::fake(selfSignedCertificate(['app.com'])->leaf()->pem(), [
        'https://ca.test/finalize/1' => Http::response([
            'status' => 'processing',
            'identifiers' => [['type' => 'dns', 'value' => 'app.com']],
            'authorizations' => ['https://ca.test/authz/1'],
            'finalize' => 'https://ca.test/finalize/1',
        ], 200, $nonce),
    ]);

    AcmeTestCa::provider()->generate('generated-tls-app-com', 'app.com');

    expect(AcmeTestCa::hits('https://ca.test/order/1'))->toBe(1);
    Storage::disk('local')->assertExists('certificates/generated-tls-app-com/certificate.pem');
});
