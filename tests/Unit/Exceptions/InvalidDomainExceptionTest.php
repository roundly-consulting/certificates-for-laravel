<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Exceptions\InvalidDomainException;

it('builds a translatable message for a domain', function (): void {
    $exception = InvalidDomainException::forDomain('not a domain');

    expect($exception)
        ->toBeInstanceOf(CertificateException::class)
        ->getMessage()->toContain('not a domain');
});
