<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Actions;

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Certificates\CertificateManager;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Events\CertificateRenewed;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Models\Certificate;

final class RenewCertificateAction
{
    public function __construct(
        private readonly CertificateManager $manager,
    ) {}

    public function execute(Certificate $certificate): Certificate
    {
        if (! $certificate->status->canTransitionTo(CertificateStatus::Renewing)) {
            throw new CertificateException((string) trans('certificates::messages.illegal_transition', [
                'from' => $certificate->status->value,
                'to' => CertificateStatus::Renewing->value,
            ]));
        }

        $certificate->forceFill(['status' => CertificateStatus::Renewing])->save();

        $provider = $this->manager->provider($certificate->driver);
        $provider->generate($certificate->name, $certificate->domain);

        $expiresAt = $provider instanceof ReportsCertificateStatus
            ? $provider->status($certificate->name, $certificate->domain)->expiresAt
            : null;

        $certificate->markRenewed($expiresAt ?? now()->addDays(90));

        Event::dispatch(new CertificateRenewed($certificate));

        return $certificate;
    }
}
