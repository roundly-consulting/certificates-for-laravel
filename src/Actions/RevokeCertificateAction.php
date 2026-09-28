<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Record a revocation in the registry: Issued/Renewed → Revoked, keeping the reason in
 * `last_error` and dispatching CertificateRevoked (which opens an alert when
 * `certificates.alerts.enabled` is on). It does not contact the CA or the cluster — no
 * bundled provider exposes an upstream revocation call.
 *
 * Reach it through `Certificates::revoke()` / `Certificates::for($domain)->revoke()`.
 */
final readonly class RevokeCertificateAction
{
    public function execute(Certificate $certificate, ?string $reason = null): Certificate
    {
        if (! $certificate->status->canTransitionTo(CertificateStatus::Revoked)) {
            throw CertificateException::illegalTransition($certificate->status, CertificateStatus::Revoked);
        }

        return $certificate->markRevoked($reason);
    }
}
