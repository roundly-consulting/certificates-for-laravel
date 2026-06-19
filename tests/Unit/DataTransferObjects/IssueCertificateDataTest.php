<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;

it('builds from a domain with sensible defaults', function (): void {
    $data = IssueCertificateData::make('app.example.com');

    expect($data->domain)->toBe('app.example.com')
        ->and($data->issuer)->toBeNull()
        ->and($data->namespace)->toBeNull()
        ->and($data->driver)->toBeNull()
        ->and($data->validForDays)->toBeNull()
        ->and($data->meta)->toBe([])
        ->and($data->owner)->toBeNull();
});

it('accepts all options', function (): void {
    $data = new IssueCertificateData(
        domain: 'shop.example.com',
        issuer: 'letsencrypt-prod',
        namespace: 'tenants',
        driver: 'kubernetes',
        validForDays: 30,
        meta: ['tenant' => '7'],
    );

    expect($data->issuer)->toBe('letsencrypt-prod')
        ->and($data->namespace)->toBe('tenants')
        ->and($data->driver)->toBe('kubernetes')
        ->and($data->validForDays)->toBe(30)
        ->and($data->meta)->toBe(['tenant' => '7']);
});
