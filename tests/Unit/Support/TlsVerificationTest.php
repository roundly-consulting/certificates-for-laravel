<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Support\TlsVerification;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

it('maps a path-or-switch setting onto the verify option', function (mixed $setting, string|bool $verify): void {
    expect(TlsVerification::from($setting, 'certificates.drivers.acme.verify'))->toBe($verify);
})->with([
    'a CA bundle path' => ['/etc/ssl/ca.pem', '/etc/ssl/ca.pem'],
    'null: the system bundle' => [null, true],
    'empty: the system bundle' => ['', true],
    'blank: the system bundle' => ['  ', true],
    'true' => [true, true],
    'false' => [false, false],
    'int 1' => [1, true],
    'int 0' => [0, false],
    '"On"' => ['On', true],
    '"OFF"' => ['OFF', false],
    'a typo is a CA path, never a disabled check' => ['flase', 'flase'],
]);

it('refuses a setting that is neither a path nor a boolean (strict config)', function (mixed $setting): void {
    TlsVerification::from($setting, 'certificates.drivers.acme.verify');
})->with([
    'array' => [['x']],
    'float' => [0.0],
    'other int' => [2],
])->throws(InvalidConfigurationException::class, 'certificates.drivers.acme.verify');
