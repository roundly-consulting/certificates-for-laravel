<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Certificate;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;

it('holds a name and domain', function (): void {
    $certificate = new Certificate(name: 'generated-tls-example-com', domain: 'example.com');

    expect($certificate)
        ->name->toBe('generated-tls-example-com')
        ->domain->toBe('example.com');
});

it('converts to a RemoteCertificate', function (): void {
    $remote = (new Certificate(name: 'generated-tls-example-com', domain: 'example.com'))
        ->toRemoteCertificate();

    expect($remote)
        ->toBeInstanceOf(RemoteCertificate::class)
        ->name->toBe('generated-tls-example-com')
        ->domain->toBe('example.com');
});
