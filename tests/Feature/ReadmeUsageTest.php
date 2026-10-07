<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateIssued;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;

/**
 * Regression (chat review C-11): the README's usage example claimed Issued / 90 days and
 * revoked the issued model — but on the default kubernetes driver issue() returns Requested
 * (cert-manager issues asynchronously), with no expiry, and revoking a Requested certificate
 * throws. This runs a copy of the README's Usage section on the default driver — issue, then
 * the scheduled sync settles it — and checks every claim its comments make; keep it identical
 * to the README.
 */
it('runs the README usage example on the default driver', function (): void {
    expect(config('certificates.default'))->toBe('kubernetes');
    Event::fake([CertificateIssued::class]);

    $tenant = Tenant::query()->create(['name' => 'shop']);
    $clusterReady = false;

    Http::fake(function (Request $request) use (&$clusterReady) {
        if (str_contains($request->url(), '/ingresses')) {
            return $request->method() === 'GET' ? Http::response('not found', 404) : Http::response(['ok' => true], 201);
        }

        $certificate = [
            'metadata' => ['name' => 'generated-tls-shop-example-com'],
            'spec' => ['dnsNames' => ['shop.example.com', 'www.shop.example.com'], 'issuerRef' => ['name' => 'letsencrypt']],
            'status' => ['notAfter' => CarbonImmutable::now()->addDays(90)->toIso8601String(), 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
        ];

        if (str_ends_with($request->url(), '/certificates')) {
            return Http::response(['items' => $clusterReady ? [$certificate] : []]);
        }

        return $clusterReady ? Http::response($certificate) : Http::response(['kind' => 'Status', 'code' => 404], 404);
    });

    // --- README: Usage ---------------------------------------------------------------
    $certificate = Certificates::for('shop.example.com')
        ->alsoFor('www.shop.example.com')
        ->owner($tenant)                  // a model using the HasCertificates trait
        ->issue();

    expect($certificate->status)->toBe(CertificateStatus::Requested); // CertificateStatus::Requested

    Schedule::command('certificates:sync')->everyFiveMinutes();  // Requested → Issued, fires CertificateIssued
    Schedule::command('certificates:renew')->daily();            // everything inside renewal.threshold_days

    // --- cert-manager finishes, and the scheduler runs both commands ------------------
    $clusterReady = true;
    $this->artisan('certificates:sync')->assertSuccessful();
    $this->artisan('certificates:renew')->assertSuccessful();

    Event::assertDispatched(CertificateIssued::class);

    // --- README: Usage, continued ------------------------------------------------------
    expect(Certificates::status('shop.example.com'))->toBe(CertificateStatus::Issued);        // CertificateStatus::Issued once synced
    expect(Certificates::find('shop.example.com')?->daysUntilExpiry())->toBe(90);             // 90
    expect(Certificates::expiring(14))->toBeInstanceOf(EloquentCollection::class);            // Collection<int, Certificate>, soonest first
    expect(Certificates::revoke('shop.example.com', 'key compromise')->status)->toBe(CertificateStatus::Revoked);
});
