<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\KubernetesApiException;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Regression (chat review C-8): every domain's issuance read the shared Ingress, changed it and
 * merge-patched the whole object back. The lock is per certificate, so two domains raced: the
 * stale writer (its body carries the resourceVersion it read) got a 409 Conflict, the row went
 * Failed with no expiry — and expiring() never retried it.
 */
beforeEach(function (): void {
    config()->set('certificates.default', 'kubernetes');
});

/**
 * @param  list<array{0: string, 1: list<array<string, mixed>>}>  $versions  what each Ingress GET returns: [resourceVersion, tls]
 * @param  list<int>  $writeStatuses  the status each PATCH/POST answers with, in order
 */
function contendedCluster(array $versions, array $writeStatuses): void
{
    Http::fake(function (Request $request) use (&$versions, &$writeStatuses) {
        if (str_contains($request->url(), '/ingresses')) {
            if ($request->method() === 'GET') {
                $version = count($versions) > 1 ? array_shift($versions) : $versions[0];

                return $version[0] === ''
                    ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                    : Http::response(['metadata' => ['name' => 'app-ingress', 'resourceVersion' => $version[0]], 'spec' => ['tls' => $version[1], 'rules' => []]]);
            }

            $status = count($writeStatuses) > 1 ? array_shift($writeStatuses) : $writeStatuses[0];

            return Http::response($status === 409 ? ['kind' => 'Status', 'reason' => 'Conflict', 'code' => 409] : ['ok' => true], $status);
        }

        if (str_contains($request->url(), '/certificates/')) {
            return Http::response(['status' => ['notAfter' => '2030-01-01T00:00:00Z', 'conditions' => [['type' => 'Ready', 'status' => 'True']]]]);
        }

        return Http::response('unexpected '.$request->url(), 500);
    });
}

/**
 * @return list<Request>
 */
function ingressWrites(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), '/ingresses') && $request->method() !== 'GET')
        ->values()
        ->all();
}

$other = ['hosts' => ['other.tenant.com'], 'secretName' => 'generated-tls-other-tenant-com'];
$racer = ['hosts' => ['racer.tenant.com'], 'secretName' => 'generated-tls-racer-tenant-com'];

it('re-reads the Ingress and retries when another writer changed it first', function () use ($other, $racer): void {
    contendedCluster(
        versions: [['41', [$other]], ['42', [$other, $racer]]],
        writeStatuses: [409, 200],
    );

    $certificate = Certificates::for('new.tenant.com')->issue();

    $writes = ingressWrites();
    $final = $writes[1]->data();

    expect($certificate->status)->toBe(CertificateStatus::Issued)
        ->and($writes)->toHaveCount(2)
        ->and($final['metadata']['resourceVersion'])->toBe('42')
        ->and(array_column($final['spec']['tls'], 'secretName'))->toBe([
            'generated-tls-other-tenant-com',
            'generated-tls-racer-tenant-com',
            'generated-tls-new-tenant-com',
        ]);
});

it('patches the Ingress another process created while this one was creating it', function () use ($racer): void {
    contendedCluster(
        versions: [['', []], ['1', [$racer]]],
        writeStatuses: [409, 200],
    );

    Certificates::for('new.tenant.com')->issue();

    $writes = ingressWrites();

    expect(array_map(fn (Request $request): string => $request->method(), $writes))->toBe(['POST', 'PATCH'])
        ->and(array_column($writes[1]->data()['spec']['tls'], 'secretName'))->toBe([
            'generated-tls-racer-tenant-com',
            'generated-tls-new-tenant-com',
        ]);
});

it('gives up after repeated conflicts', function () use ($other): void {
    contendedCluster(versions: [['41', [$other]]], writeStatuses: [409]);

    expect(fn () => Certificates::for('new.tenant.com')->issue())->toThrow(KubernetesApiException::class);

    expect(ingressWrites())->toHaveCount(5)
        ->and(Certificate::query()->sole()->status)->toBe(CertificateStatus::Failed);
});
