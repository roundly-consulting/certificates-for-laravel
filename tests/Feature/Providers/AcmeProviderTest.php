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
use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;
use RoundlyConsulting\Crypto\Signature\Rs;

const DIR = 'https://acme.test/directory';

beforeEach(function (): void {
    Storage::fake('local');
});

function makeProvider(string $keyType = 'EC'): AcmeProvider
{
    $jws = new Jws;
    $account = new AcmeAccount(disk: 'local', keyPath: 'acme/account.pem', keyType: $keyType);

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

/**
 * Every JWS the faked CA received, decoded.
 *
 * @return list<array{header: array<string, mixed>, payload: string, signature: string, signingInput: string}>
 */
function capturedJws(): array
{
    $requests = [];

    foreach (Http::recorded() as [$request]) {
        if ($request->method() !== 'POST') {
            continue;
        }

        /** @var array{protected: string, payload: string, signature: string} $body */
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $header */
        $header = json_decode(Base64Url::decode($body['protected']), true, 512, JSON_THROW_ON_ERROR);

        $requests[] = [
            'header' => $header,
            'payload' => $body['payload'],
            'signature' => Base64Url::decode($body['signature']),
            'signingInput' => $body['protected'].'.'.$body['payload'],
        ];
    }

    return $requests;
}

/**
 * Seed the account disk with an EC key on a specific curve, so the flow runs
 * against a key the provider would not have generated itself.
 */
function seedAccountKey(string $curve): void
{
    Storage::disk('local')->put('acme/account.pem', EcKey::generate($curve)->privatePem());
}

it('sends a well-formed, correctly signed JWS for every ACME request', function (string $keyType, ?string $curve, string $algorithm, int $signatureBytes): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert']);

    if ($curve !== null) {
        seedAccountKey($curve);
    }

    makeProvider($keyType)->generate('generated-tls-app-com', 'app.com');

    $privatePem = (string) Storage::disk('local')->get('acme/account.pem');

    $verifier = $keyType === 'EC'
        ? new Es(EcKey::public(EcKey::private($privatePem)->publicPem()))
        : new Rs(RsaKey::public(RsaKey::private($privatePem)->publicPem()));

    $requests = capturedJws();

    expect($requests)->not->toBeEmpty();

    foreach ($requests as $index => $request) {
        expect($request['header']['alg'])->toBe($algorithm)
            ->and($request['header'])->toHaveKeys(['nonce', 'url'])
            ->and(strlen($request['signature']))->toBe($signatureBytes)
            ->and($verifier->verify($request['signingInput'], $request['signature']))->toBeTrue();

        // newAccount is the only request signed with the embedded JWK; every
        // later request is authenticated with the account kid.
        $index === 0
            ? expect($request['header'])->toHaveKey('jwk')->and($request['header'])->not->toHaveKey('kid')
            : expect($request['header'])->toHaveKey('kid', 'https://acme.test/acct/1')->and($request['header'])->not->toHaveKey('jwk');
    }

    // The certificate was issued: the whole order ran on this key type.
    Storage::disk('local')->assertExists('certificates/generated-tls-app-com/certificate.pem');
})->with([
    // ES256/ES384 must be the raw r||s form (64/96 bytes), never DER — Let's
    // Encrypt rejects a DER signature outright.
    'ec p-256 account key' => ['EC', null, 'ES256', 64],
    'ec p-384 account key' => ['EC', 'P-384', 'ES384', 96],
    'rsa account key' => ['RSA', null, 'RS256', 256],
]);

it('embeds a jwk describing the P-384 account key it actually signs with', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert']);
    seedAccountKey('P-384');

    makeProvider('EC')->generate('generated-tls-app-com', 'app.com');

    /** @var array{crv: string, kty: string, x: string, y: string} $jwk */
    $jwk = capturedJws()[0]['header']['jwk'];

    // The header's curve, its coordinate width and the alg all come from the
    // key. A P-384 key advertised as P-256 thumbprints to something the CA
    // cannot re-derive, and every key authorization silently fails.
    expect($jwk['crv'])->toBe('P-384')
        ->and(strlen(Base64Url::decode($jwk['x'])))->toBe(48)
        ->and(strlen(Base64Url::decode($jwk['y'])))->toBe(48)
        ->and(capturedJws()[0]['header']['alg'])->toBe('ES384');
});

it('signs the challenge response over an empty JSON object, not an empty array', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert']);

    makeProvider()->generate('generated-tls-app-com', 'app.com');

    $challenge = collect(capturedJws())->first(
        fn (array $request): bool => $request['header']['url'] === 'https://acme.test/chall/1',
    );

    // RFC 8555 §7.5.1 — the exact bytes the CA verifies the signature over must
    // be `{}`. `[]` (PHP's default for an empty array) is rejected by Boulder,
    // and `""` would make it a POST-as-GET rather than a response.
    expect(Base64Url::decode($challenge['payload']))->toBe('{}')
        ->and($challenge['payload'])->toBe(Base64Url::encode('{}'))
        ->and($challenge['payload'])->not->toBe('');
});

it('sends an empty payload segment for every post-as-get', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert']);

    makeProvider()->generate('generated-tls-app-com', 'app.com');

    // RFC 8555 §6.3 — reads (authz, order, certificate) post an empty *string*
    // payload, which serializes to an empty segment; never `{}`.
    $reads = collect(capturedJws())->filter(fn (array $request): bool => in_array(
        $request['header']['url'],
        ['https://acme.test/authz/1', 'https://acme.test/order/1', 'https://acme.test/cert/1'],
        strict: true,
    ));

    expect($reads)->not->toBeEmpty();

    $reads->each(fn (array $request) => expect($request['payload'])->toBe(''));
});

it('keeps every write payload a JSON object on the wire', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert']);

    makeProvider()->generate('generated-tls-app-com', 'app.com');

    $writes = collect(capturedJws())
        ->filter(fn (array $request): bool => $request['payload'] !== '')
        ->map(fn (array $request): string => Base64Url::decode($request['payload']));

    // Every ACME payload we send is an object — none may serialize as a JSON array.
    [$newAccount, $newOrder, $challenge, $finalize] = $writes->values()->all();

    expect($writes)->toHaveCount(4)
        ->and($newAccount)->toBe('{"termsOfServiceAgreed":true,"contact":["mailto:ops@app.com"]}')
        ->and($newOrder)->toBe('{"identifiers":[{"type":"dns","value":"app.com"}]}')
        ->and($challenge)->toBe('{}')
        // The CSR DER is not byte-stable, but its envelope is.
        ->and($finalize)->toMatch('/^\{"csr":"[A-Za-z0-9_-]+"\}$/');
});

it('sends a jwk the CA can re-derive our thumbprint from', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert']);

    makeProvider()->generate('generated-tls-app-com', 'app.com');

    $newAccount = capturedJws()[0];

    /** @var array<string, string> $jwk */
    $jwk = $newAccount['header']['jwk'];
    ksort($jwk);

    $thumbprint = Base64Url::encode(
        (new Digest)->raw((string) json_encode($jwk, JSON_UNESCAPED_SLASHES)),
    );

    // This is exactly what the CA does with the JWK we posted; it must equal the
    // thumbprint we put in every key authorization.
    $account = new AcmeAccount(disk: 'local', keyPath: 'acme/account.pem', keyType: 'EC');

    expect($thumbprint)->toBe($account->thumbprint());
});

it('base64url-encodes the CSR DER it finalizes with', function (): void {
    $pem = Pem::selfSigned(['app.com']);
    fakeAcme($pem['cert']);

    makeProvider()->generate('generated-tls-app-com', 'app.com');

    $finalize = collect(capturedJws())->first(
        fn (array $request): bool => $request['header']['url'] === 'https://acme.test/finalize/1',
    );

    /** @var array{csr: string} $payload */
    $payload = json_decode(Base64Url::decode($finalize['payload']), true, 512, JSON_THROW_ON_ERROR);

    // A DER CertificationRequest is an ASN.1 SEQUENCE (0x30).
    expect(bin2hex(Base64Url::decode($payload['csr'])[0]))->toBe('30');
});

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
