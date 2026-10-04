<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Exceptions;

use Exception;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;

class CertificateException extends Exception
{
    public static function unparseable(): self
    {
        return new self('The supplied PEM could not be parsed as an X.509 certificate.');
    }

    public static function notFound(string $domain): self
    {
        return new self((string) trans('certificates::messages.not_found', ['domain' => $domain]));
    }

    public static function illegalTransition(CertificateStatus $from, CertificateStatus $to): self
    {
        return new self((string) trans('certificates::messages.illegal_transition', [
            'from' => $from->value,
            'to' => $to->value,
        ]));
    }

    /**
     * The provider finished provisioning but reports the certificate as not usable
     * (failed, expired or revoked) — the issuance or renewal did not produce a live one.
     */
    public static function providerReported(string $driver, string $domain, CertificateStatus $status): self
    {
        return new self((string) trans('certificates::messages.provider_reported', [
            'driver' => $driver,
            'domain' => $domain,
            'status' => $status->label(),
        ]));
    }

    public static function noMaterial(string $name): self
    {
        return new self((string) trans('certificates::messages.no_material', ['name' => $name]));
    }

    public static function notRenewed(string $driver, string $domain): self
    {
        return new self((string) trans('certificates::messages.not_renewed', ['driver' => $driver, 'domain' => $domain]));
    }

    public static function noAlertNotifiable(string $domain): self
    {
        return new self((string) trans('certificates::messages.no_alert_notifiable', ['domain' => $domain]));
    }
}
