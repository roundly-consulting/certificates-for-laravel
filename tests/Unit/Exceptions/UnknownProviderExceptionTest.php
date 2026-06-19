<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Exceptions\UnknownProviderException;

it('builds a translatable message for a driver', function (): void {
    $exception = UnknownProviderException::driver('acme');

    expect($exception)
        ->toBeInstanceOf(CertificateException::class)
        ->getMessage()->toContain('acme');
});
