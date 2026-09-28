<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Mark a registry certificate expired: Issued/Renewed → Expired, dispatching
 * CertificateExpired (which opens an alert when `certificates.alerts.enabled` is on).
 *
 * Reach it through `Certificates::expire()` / `Certificates::for($domain)->expire()`.
 */
final readonly class ExpireCertificateAction
{
    public function execute(Certificate $certificate): Certificate
    {
        if (! $certificate->status->canTransitionTo(CertificateStatus::Expired)) {
            throw CertificateException::illegalTransition($certificate->status, CertificateStatus::Expired);
        }

        return $certificate->markExpired();
    }
}
