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

    public static function noAlertNotifiable(string $domain): self
    {
        return new self(
            "No alert notifiable could be resolved for [{$domain}]. Pass one to monitorExpiry(), ".
            'set certificates.alerts.notifiable, or associate the certificate with a certifiable owner.',
        );
    }
}
