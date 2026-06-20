<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\Tenant;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('exposes a certificates morph relation', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme']);
    $tenant->certificates()->save(Certificate::factory()->make());

    expect($tenant->certificates()->count())->toBe(1);
});

it('requests a certificate attached to the owner', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme']);

    $certificate = $tenant->requestCertificate('shop.example.com');

    expect($certificate->certifiable_id)->toBe($tenant->id)
        ->and($tenant->hasCertificateFor('shop.example.com'))->toBeTrue()
        ->and($tenant->certificateFor('shop.example.com'))->not->toBeNull()
        ->and($tenant->certificateFor('other.example.com'))->toBeNull();
});

it('lists active and expiring certificates for the owner', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Acme']);

    $tenant->certificates()->save(Certificate::factory()->issued()->forDomain('active.example.com')->make());
    $tenant->certificates()->save(Certificate::factory()->expiring(5)->forDomain('soon.example.com')->make());
    $tenant->certificates()->save(Certificate::factory()->expired()->forDomain('gone.example.com')->make());

    expect($tenant->activeCertificates())->toHaveCount(2)
        ->and($tenant->expiringCertificates(7))->toHaveCount(1);
});
