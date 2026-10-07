<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Certificates\Tests\Fixtures\AcmeTestCa;
use RoundlyConsulting\Certificates\Tests\Fixtures\RecordingSolver;

/**
 * Regression (chat review C-12): every authorization of an order was solved, whatever its
 * status. A CA reuses an authorization it validated recently (RFC 8555 §7.1.4) and then lists
 * only the challenge that validated it — so switching solver type failed with "no dns-01
 * challenge offered", and an already-valid challenge was solved and POSTed again.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

it('skips an authorization the CA already holds as valid', function (string $solverType): void {
    AcmeTestCa::fake(selfSignedCertificate(['app.com'])->leaf()->pem(), [
        'https://ca.test/authz/1' => Http::response(AcmeTestCa::authorization('valid'), 200, ['Replay-Nonce' => 'n']),
    ]);
    $solver = new RecordingSolver($solverType);

    AcmeTestCa::provider($solver)->generate('generated-tls-app-com', 'app.com');

    expect($solver->solved)->toBe([])
        ->and(AcmeTestCa::hits('https://ca.test/chall/http-01'))->toBe(0);

    Storage::disk('local')->assertExists('certificates/generated-tls-app-com/certificate.pem');
})->with([
    'same solver type' => ['http-01'],
    'another solver type' => ['dns-01'],
]);

it('refuses an authorization that can no longer be satisfied', function (string $status): void {
    AcmeTestCa::fake(selfSignedCertificate(['app.com'])->leaf()->pem(), [
        'https://ca.test/authz/1' => Http::response(AcmeTestCa::authorization($status), 200, ['Replay-Nonce' => 'n']),
    ]);
    $solver = new RecordingSolver;

    expect(fn () => AcmeTestCa::provider($solver)->generate('generated-tls-app-com', 'app.com'))
        ->toThrow(AcmeException::class, $status);

    expect($solver->solved)->toBe([]);
})->with(['invalid', 'deactivated', 'expired', 'revoked']);

it('still solves a pending authorization', function (): void {
    AcmeTestCa::fake(selfSignedCertificate(['app.com'])->leaf()->pem());
    $solver = new RecordingSolver;

    AcmeTestCa::provider($solver)->generate('generated-tls-app-com', 'app.com');

    expect($solver->solved)->toHaveCount(1)
        ->and(AcmeTestCa::hits('https://ca.test/chall/http-01'))->toBe(1);
});
