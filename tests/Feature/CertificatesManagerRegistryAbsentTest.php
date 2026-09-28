<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Certificates\CertificatesManager;

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
    Schema::dropIfExists('certificates');
});

it('still generates without a registry table', function (): void {
    expect(app(CertificatesManager::class)->generate('legacy.example.com'))->toBeTrue();
});

it('returns false from generate when the lock is contended and no registry', function (): void {
    $service = app(CertificatesManager::class);
    $lock = Cache::lock('certificates:generate', 5, 'someone-else');
    $lock->get();

    expect($service->generate('legacy.example.com'))->toBeFalse();

    $lock->forceRelease();
});

it('returns null from find and status without a registry', function (): void {
    $service = app(CertificatesManager::class);

    expect($service->find('legacy.example.com'))->toBeNull()
        ->and($service->status('legacy.example.com'))->toBeNull();
});
