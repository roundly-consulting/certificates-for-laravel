<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Exceptions;

/**
 * Another process holds this certificate's provisioning lock. Nothing was provisioned and
 * the registry row was not touched — retry once the running issuance has finished.
 */
final class ProvisioningInProgressException extends CertificateException
{
    public static function forDomain(string $domain): self
    {
        return new self((string) trans('certificates::messages.provisioning_in_progress', ['domain' => $domain]));
    }
}
