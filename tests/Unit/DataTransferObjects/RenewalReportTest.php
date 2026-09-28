<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\DataTransferObjects\RenewalFailure;
use RoundlyConsulting\Certificates\DataTransferObjects\RenewalReport;
use RoundlyConsulting\Certificates\Models\Certificate;

it('is empty when nothing was due', function (): void {
    $report = new RenewalReport;

    expect($report->isEmpty())->toBeTrue()
        ->and($report->hasFailures())->toBeFalse()
        ->and($report->count())->toBe(0)
        ->and($report->renewedDomains())->toBe([])
        ->and($report->queuedDomains())->toBe([])
        ->and($report->failedDomains())->toBe([]);
});

it('counts every outcome and names each domain', function (): void {
    $renewed = new Certificate(['domain' => 'renewed.com']);
    $queued = new Certificate(['domain' => 'queued.com']);
    $failed = new Certificate(['domain' => 'failed.com']);
    $exception = new RuntimeException('CA down.');

    $report = new RenewalReport([$renewed], [$queued], [new RenewalFailure($failed, $exception)]);

    expect($report->isEmpty())->toBeFalse()
        ->and($report->hasFailures())->toBeTrue()
        ->and($report->count())->toBe(3)
        ->and($report->renewedDomains())->toBe(['renewed.com'])
        ->and($report->queuedDomains())->toBe(['queued.com'])
        ->and($report->failedDomains())->toBe(['failed.com'])
        ->and($report->failed[0]->exception)->toBe($exception)
        ->and($report->failed[0]->reason())->toBe('CA down.');
});
