<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\Acme\AcmeAccount;
use RoundlyConsulting\Certificates\Acme\AcmeClient;
use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\Acme\Jws;
use RoundlyConsulting\Certificates\ChallengeSolvers\HttpChallengeSolver;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Certificates\Providers\AcmeProvider;
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;
use RoundlyConsulting\Certificates\Support\X509Parser;
use RoundlyConsulting\Certificates\Tests\Helpers\Pem;

const DIR = 'https://acme.test/directory';

beforeEach(function (): void {
    Storage::fake('local');
});

function makeProvider(): AcmeProvider
{
    $jws = new Jws;
    $account = new AcmeAccount($jws, disk: 'local', keyPath: 'acme/account.pem', keyType: 'EC');

    $client = new AcmeClient(
        jws: $jws,
        account: $account,
        directoryUrl: DIR,
        contact: 'ops@app.com',
        verify: true,
        challengeType: 'http-01',
    );

    return new AcmeProvider(
        client: $client,
        csr: new Csr,
        store: new FilesystemCertificateStore(disk: 'local', path: 'certificates'),
        solver: new HttpChallengeSolver(disk: 'local', path: 'acme-challenge'),
        parser: new X509Parser,
        pollAttempts: 3,
        pollSeconds: 0,
    );
}

/**
 * @param  array<int, mixed>  $authStatuses
 */
function fakeAcme(string $certPem, array $authStatuses = ['valid'], string $orderStatus = 'valid'): void
{
    $headers = ['Replay-Nonce' => 'nonce-'.uniqid()];

    // The first authz read serves the challenge; the rest drive the poll loop.
    $authResponses = [Http::response([
        'status' => 'pending',
        'identifier' => ['type' => 'dns', 'value' => 'app.com'],
        'challenges' => [['type' => 'http-01', 'token' => 'tok123', 'url' => 'https://acme.test/chall/1']],
    ], 200, $headers)];

    foreach ($authStatuses as $status) {
        $authResponses[] = Http::response([
            'status' => $status,
            'identifier' => ['type' => 'dns', 'value' => 'app.com'],
            'challenges' => [['type' => 'http-01', 'token' => 'tok123', 'url' => 'https://acme.test/chall/1']],
        ], 200, $headers);
    }

    Http::fake([
        DIR => Http::response([
            'newNonce' => 'https://acme.test/new-nonce',
            'newAccount' => 'https://acme.test/new-acct',
            'newOrder' => 'https://acme.test/new-order',
        ], 200, $headers),
        'https://acme.test/new-nonce' => Http::response('', 200, $headers),
        'https://acme.test/new-acct' => Http::response(['status' => 'valid'], 201, $headers + ['Location' => 'https://acme.test/acct/1']),
        'https://acme.test/new-order' => Http::response([
            'status' => 'pending',
            'identifiers' => [['type' => 'dns', 'value' => 'app.com']],
            'authorizations' => ['https://acme.test/authz/1'],
            'finalize' => 'https://acme.test/finalize/1',
        ], 201, $headers + ['Location' => 'https://acme.test/order/1']),
        'https://acme.test/authz/1' => Http::sequence($authResponses),
        'https://acme.test/chall/1' => Http::response(['status' => 'pending'], 200, $headers),
        'https://acme.test/finalize/1' => Http::response([
            'status' => $orderStatus,
            'identifiers' => [['type' => 'dns', 'value' => 'app.com']],
            'authorizations' => ['https://acme.test/authz/1'],
            'finalize' => 'https://acme.test/finalize/1',
            'certificate' => 'https://acme.test/cert/1',
        ], 200, $headers + ['Location' => 'https://acme.test/order/1']),
        'https://acme.test/order/1' => Http::response([
            'status' => $orderStatus,
            'identifiers' => [['type' => 'dns', 'value' => 'app.com']],
            'authorizations' => ['https://acme.test/authz/1'],
            'finalize' => 'https://acme.test/finalize/1',
            'certificate' => 'https://acme.test/cert/1',
        ], 200, $headers),
        'https://acme.test/cert/1' => Http::response($certPem, 200, $headers),
    ]);
}

it('issues a certificate through the full ACME flow', function (): void {
    $pem = Pem::selfSigned(['app.com'], days: 60);
    fakeAcme($pem['cert']);

    $provider = makeProvider();
    $provider->generate('generated-tls-app-com', 'app.com');

    Storage::disk('local')->assertExists('certificates/generated-tls-app-com/certificate.pem');
    // The challenge file is cleaned up after validation.
    Storage::disk('local')->assertMissing('acme-challenge/tok123');

    $report = $provider->status('generated-tls-app-com', 'app.com');

    expect($report->status)->toBe(CertificateStatus::Issued)
        ->and($report->expiresAt)->not->toBeNull()
        ->and($provider->exists('generated-tls-app-com', 'app.com'))->toBeTrue();
});

it('lists issued certificates', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert']);

    $provider = makeProvider();
    $provider->generate('generated-tls-app-com', 'app.com');

    expect($provider->get())->toHaveCount(1)
        ->and($provider->get()->first()->domain)->toBe('app.com');
});

it('persists the account kid for reuse', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert']);

    makeProvider()->generate('generated-tls-app-com', 'app.com');

    // The account is registered and its kid persisted for subsequent runs.
    expect(Storage::disk('local')->exists('acme/account.pem.kid'))->toBeTrue();
});

it('reports pending when nothing is stored', function (): void {
    $report = makeProvider()->status('missing', 'app.com');

    expect($report->status)->toBe(CertificateStatus::Pending);
});

it('reports not-existing when nothing is stored', function (): void {
    expect(makeProvider()->exists('missing', 'app.com'))->toBeFalse();
});

it('throws when a challenge becomes invalid', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert'], authStatuses: ['invalid']);

    makeProvider()->generate('generated-tls-app-com', 'app.com');
})->throws(AcmeException::class);

it('polls until the authorization is valid', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert'], authStatuses: ['pending', 'valid']);

    $provider = makeProvider();
    $provider->generate('generated-tls-app-com', 'app.com');

    expect($provider->exists('generated-tls-app-com', 'app.com'))->toBeTrue();
});

it('throws when the order becomes invalid at finalization', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert'], orderStatus: 'invalid');

    makeProvider()->generate('generated-tls-app-com', 'app.com');
})->throws(AcmeException::class);

it('throws when the directory is unavailable', function (): void {
    Http::fake([DIR => Http::response('', 500)]);

    makeProvider()->generate('generated-tls-app-com', 'app.com');
})->throws(AcmeException::class);

it('retries once on a badNonce error', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    $headers = ['Replay-Nonce' => 'nonce-1'];

    Http::fake([
        DIR => Http::response([
            'newNonce' => 'https://acme.test/new-nonce',
            'newAccount' => 'https://acme.test/new-acct',
            'newOrder' => 'https://acme.test/new-order',
        ], 200, $headers),
        'https://acme.test/new-nonce' => Http::response('', 200, $headers),
        // First account POST fails with badNonce, the retry succeeds.
        'https://acme.test/new-acct' => Http::sequence()
            ->push(['type' => 'urn:ietf:params:acme:error:badNonce'], 400, $headers)
            ->push(['status' => 'valid'], 201, $headers + ['Location' => 'https://acme.test/acct/1']),
        'https://acme.test/new-order' => Http::response([
            'status' => 'pending',
            'identifiers' => [['type' => 'dns', 'value' => 'app.com']],
            'authorizations' => ['https://acme.test/authz/1'],
            'finalize' => 'https://acme.test/finalize/1',
        ], 201, $headers + ['Location' => 'https://acme.test/order/1']),
        'https://acme.test/authz/1' => Http::sequence()
            ->push(['status' => 'pending', 'identifier' => ['value' => 'app.com'], 'challenges' => [['type' => 'http-01', 'token' => 'tok123', 'url' => 'https://acme.test/chall/1']]], 200, $headers)
            ->push(['status' => 'valid', 'identifier' => ['value' => 'app.com'], 'challenges' => []], 200, $headers),
        'https://acme.test/chall/1' => Http::response(['status' => 'pending'], 200, $headers),
        'https://acme.test/finalize/1' => Http::response([
            'status' => 'valid',
            'identifiers' => [['type' => 'dns', 'value' => 'app.com']],
            'finalize' => 'https://acme.test/finalize/1',
            'certificate' => 'https://acme.test/cert/1',
        ], 200, $headers + ['Location' => 'https://acme.test/order/1']),
        'https://acme.test/order/1' => Http::response([
            'status' => 'valid',
            'identifiers' => [['type' => 'dns', 'value' => 'app.com']],
            'finalize' => 'https://acme.test/finalize/1',
            'certificate' => 'https://acme.test/cert/1',
        ], 200, $headers),
        'https://acme.test/cert/1' => Http::response($pem['cert'], 200, $headers),
    ]);

    $provider = makeProvider();
    $provider->generate('generated-tls-app-com', 'app.com');

    expect($provider->exists('generated-tls-app-com', 'app.com'))->toBeTrue();
});
