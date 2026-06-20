<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;

beforeEach(function (): void {
    Storage::fake('local');
});

function store(): FilesystemCertificateStore
{
    return new FilesystemCertificateStore(disk: 'local', path: 'certificates');
}

it('puts, gets and reports existence', function (): void {
    $store = store();

    expect($store->exists('tls-a'))->toBeFalse()
        ->and($store->get('tls-a'))->toBeNull();

    $store->put('tls-a', new StoredCertificate('CERT', 'KEY', 'CHAIN'));

    expect($store->exists('tls-a'))->toBeTrue();

    $material = $store->get('tls-a');

    expect($material)->not->toBeNull()
        ->and($material->certificatePem)->toBe('CERT')
        ->and($material->privateKeyPem)->toBe('KEY')
        ->and($material->chainPem)->toBe('CHAIN');
});

it('stores material without a chain', function (): void {
    $store = store();
    $store->put('tls-b', new StoredCertificate('CERT', 'KEY'));

    expect($store->get('tls-b')->chainPem)->toBeNull();
});

it('deletes material', function (): void {
    $store = store();
    $store->put('tls-c', new StoredCertificate('CERT', 'KEY'));

    $store->delete('tls-c');

    expect($store->exists('tls-c'))->toBeFalse();
});

it('lists stored names', function (): void {
    $store = store();
    $store->put('tls-a', new StoredCertificate('CERT', 'KEY'));
    $store->put('tls-b', new StoredCertificate('CERT', 'KEY'));

    expect($store->names())->toContain('tls-a', 'tls-b');
});

it('concatenates the full chain', function (): void {
    $material = new StoredCertificate('LEAF', 'KEY', 'INTERMEDIATE');

    expect($material->fullChainPem())->toBe("LEAF\nINTERMEDIATE");
});

it('returns just the leaf when there is no chain', function (): void {
    expect((new StoredCertificate('LEAF', 'KEY'))->fullChainPem())->toBe('LEAF');
});
