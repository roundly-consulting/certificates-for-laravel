<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Exceptions;

final class UnknownProviderException extends CertificateException
{
    public static function driver(string $name): self
    {
        return new self((string) trans('certificates::messages.unknown_provider', ['driver' => $name]));
    }
}
