<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Exceptions;

use Exception;

class CertificateException extends Exception
{
    public static function unparseable(): self
    {
        return new self('The supplied PEM could not be parsed as an X.509 certificate.');
    }
}
