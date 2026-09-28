<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * The per-certificate outcome of `Certificates::renewDue()`. Every due certificate lands in
 * exactly one list, soonest-expiring first.
 */
final readonly class RenewalReport
{
    /**
     * @param  list<Certificate>  $renewed  renewed inline
     * @param  list<Certificate>  $queued  handed to RenewCertificateJob (`queue: true`)
     * @param  list<RenewalFailure>  $failed  could not be renewed or queued
     */
    public function __construct(
        public array $renewed = [],
        public array $queued = [],
        public array $failed = [],
    ) {}

    /**
     * Nothing was due.
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    /**
     * How many certificates were due.
     */
    public function count(): int
    {
        return count($this->renewed) + count($this->queued) + count($this->failed);
    }

    /**
     * @return list<string>
     */
    public function renewedDomains(): array
    {
        return array_map(static fn (Certificate $certificate): string => $certificate->domain, $this->renewed);
    }

    /**
     * @return list<string>
     */
    public function queuedDomains(): array
    {
        return array_map(static fn (Certificate $certificate): string => $certificate->domain, $this->queued);
    }

    /**
     * @return list<string>
     */
    public function failedDomains(): array
    {
        return array_map(static fn (RenewalFailure $failure): string => $failure->certificate->domain, $this->failed);
    }
}
