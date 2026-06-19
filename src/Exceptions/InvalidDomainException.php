<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Exceptions;

final class InvalidDomainException extends CertificateException
{
    public static function forDomain(string $domain): self
    {
        return new self((string) trans('certificates::messages.invalid_domain', ['domain' => $domain]));
    }
}
