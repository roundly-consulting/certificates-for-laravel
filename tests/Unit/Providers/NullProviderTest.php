<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Providers\NullProvider;

it('is an inert no-op provider', function (): void {
    $provider = new NullProvider;

    expect($provider->get())->toBeEmpty()
        ->and($provider->exists('name', 'example.com'))->toBeFalse();

    $provider->generate('name', 'example.com');

    expect($provider->get())->toBeEmpty();
});
