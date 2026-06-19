<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Certificate;

it('holds a name and domain', function (): void {
    $certificate = new Certificate(name: 'generated-tls-example-com', domain: 'example.com');

    expect($certificate)
        ->name->toBe('generated-tls-example-com')
        ->domain->toBe('example.com');
});
