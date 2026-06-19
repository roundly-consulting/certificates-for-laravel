<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;

it('carries status and optional certificate detail', function (): void {
    $expires = CarbonImmutable::parse('2030-01-01T00:00:00Z');

    $report = new CertificateStatusReport(
        status: CertificateStatus::Issued,
        expiresAt: $expires,
        issuer: 'letsencrypt',
        serial: 'ab:cd',
        fingerprint: '12:34',
    );

    expect($report->status)->toBe(CertificateStatus::Issued)
        ->and($report->expiresAt)->toEqual($expires)
        ->and($report->issuer)->toBe('letsencrypt')
        ->and($report->serial)->toBe('ab:cd')
        ->and($report->fingerprint)->toBe('12:34');
});

it('defaults optional fields to null', function (): void {
    $report = new CertificateStatusReport(status: CertificateStatus::Pending);

    expect($report->expiresAt)->toBeNull()
        ->and($report->issuer)->toBeNull();
});
