<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Support\TlsVerification;

it('maps a path-or-switch setting onto the verify option', function (mixed $setting, string|bool $verify): void {
    expect(TlsVerification::from($setting))->toBe($verify);
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
    'an unrecognised non-string: the system bundle' => [['x'], true],
]);
