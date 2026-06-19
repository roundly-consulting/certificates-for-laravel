<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Contracts;

use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;

/**
 * Opt-in capability interface for providers that can report a certificate's
 * live status and expiry (e.g. cert-manager exposes status.notAfter).
 */
interface ReportsCertificateStatus
{
    public function status(string $name, string $domain): CertificateStatusReport;
}
