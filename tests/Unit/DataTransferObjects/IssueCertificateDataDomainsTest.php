<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;

it('falls back to the primary domain when no SANs are set', function (): void {
    expect(IssueCertificateData::make('app.com')->allDomains())->toBe(['app.com']);
});

it('returns every domain when SANs are present', function (): void {
    $data = IssueCertificateData::makeForDomains(['app.com', '*.app.com']);

    expect($data->domain)->toBe('app.com')
        ->and($data->allDomains())->toBe(['app.com', '*.app.com']);
});
