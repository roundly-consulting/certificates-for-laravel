<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Acme\AcmeAccount;
use RoundlyConsulting\Certificates\Acme\AcmeClient;
use RoundlyConsulting\Certificates\Acme\Csr;
use RoundlyConsulting\Certificates\Acme\Jws;
use RoundlyConsulting\Certificates\ChallengeSolvers\HttpChallengeSolver;
use RoundlyConsulting\Certificates\Contracts\AcmeChallengeSolver;
use RoundlyConsulting\Certificates\Providers\AcmeProvider;
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;
use RoundlyConsulting\Certificates\Support\CertificateMapper;

/**
 * A faked ACME CA at https://ca.test for one order (authz/1, chall/1, order/1, cert/1), whose
 * routes a test overrides one by one, plus an AcmeProvider wired to it on the `local` disk.
 * Run it under `Storage::fake('local')`.
 */
final class AcmeTestCa
{
    public const DIRECTORY = 'https://ca.test/directory';

    public static function client(): AcmeClient
    {
        return new AcmeClient(
            jws: new Jws,
            account: new AcmeAccount(disk: 'local', keyPath: 'acme/account.pem', keyType: 'EC'),
            directoryUrl: self::DIRECTORY,
        );
    }

    public static function provider(?AcmeChallengeSolver $solver = null): AcmeProvider
    {
        return new AcmeProvider(
            client: self::client(),
            csr: new Csr,
            store: new FilesystemCertificateStore(disk: 'local', path: 'certificates'),
            solver: $solver ?? new HttpChallengeSolver(disk: 'local', path: 'acme-challenge'),
            parser: new CertificateMapper,
            pollAttempts: 3,
            pollSeconds: 0,
        );
    }

    /**
     * @param  array<string, mixed>  $routes  URL => response (or sequence) replacing the defaults
     */
    public static function fake(string $certificatePem, array $routes = []): void
    {
        $nonce = ['Replay-Nonce' => 'nonce'];
        $order = [
            'status' => 'valid',
            'identifiers' => [['type' => 'dns', 'value' => 'app.com']],
            'authorizations' => ['https://ca.test/authz/1'],
            'finalize' => 'https://ca.test/finalize/1',
            'certificate' => 'https://ca.test/cert/1',
        ];

        Http::fake(array_replace([
            self::DIRECTORY => Http::response([
                'newNonce' => 'https://ca.test/new-nonce',
                'newAccount' => 'https://ca.test/new-acct',
                'newOrder' => 'https://ca.test/new-order',
            ], 200, $nonce),
            'https://ca.test/new-nonce' => Http::response('', 200, $nonce),
            'https://ca.test/new-acct' => Http::response(['status' => 'valid'], 201, $nonce + ['Location' => 'https://ca.test/acct/1']),
            'https://ca.test/new-order' => Http::response(['status' => 'pending'] + $order, 201, $nonce + ['Location' => 'https://ca.test/order/1']),
            'https://ca.test/authz/1' => Http::sequence([
                Http::response(self::authorization('pending'), 200, $nonce),
                Http::response(self::authorization('valid'), 200, $nonce),
            ]),
            'https://ca.test/chall/*' => Http::response(['status' => 'pending'], 200, $nonce),
            'https://ca.test/finalize/1' => Http::response($order, 200, $nonce + ['Location' => 'https://ca.test/order/1']),
            'https://ca.test/order/1' => Http::response($order, 200, $nonce),
            'https://ca.test/cert/1' => Http::response($certificatePem, 200, $nonce),
        ], $routes));
    }

    /**
     * An authorization for app.com in `$status`, offering `$challenges` (type => token).
     *
     * @param  array<string, string>  $challenges
     * @return array<string, mixed>
     */
    public static function authorization(string $status, array $challenges = ['http-01' => 'tok123']): array
    {
        $offered = [];

        foreach ($challenges as $type => $token) {
            $offered[] = ['type' => $type, 'token' => $token, 'url' => 'https://ca.test/chall/'.$type, 'status' => $status === 'valid' ? 'valid' : 'pending'];
        }

        return [
            'status' => $status,
            'identifier' => ['type' => 'dns', 'value' => 'app.com'],
            'challenges' => $offered,
        ];
    }

    /**
     * How many requests the faked CA received at `$url`.
     */
    public static function hits(string $url): int
    {
        return Http::recorded()->filter(fn (array $pair): bool => $pair[0]->url() === $url)->count();
    }
}
