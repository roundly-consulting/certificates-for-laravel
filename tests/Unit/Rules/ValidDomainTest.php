<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\Certificates\Rules\ValidDomain;

it('accepts valid hostnames', function (string $domain): void {
    expect((new ValidDomain)->passes($domain))->toBeTrue();
})->with([
    'app.example.com',
    'example.com',
    'a.b.c.example.com',
    'xn--80ak6aa92e.com',
    '*.example.com',
]);

it('rejects invalid hostnames', function (mixed $domain): void {
    expect((new ValidDomain)->passes($domain))->toBeFalse();
})->with([
    '',
    'example',
    'http://example.com',
    'has space.com',
    'example.com.',
    'example.com/path',
    '-bad.example.com',
    'bad-.example.com',
    'example.123',
    str_repeat('a', 300).'.com',
    123,
]);

it('rejects wildcards when disabled', function (): void {
    expect((new ValidDomain(allowWildcard: false))->passes('*.example.com'))->toBeFalse();
});

it('fails validation with a translatable message', function (): void {
    $validator = Validator::make(
        ['domain' => 'not a domain'],
        ['domain' => [new ValidDomain]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('domain'))->toContain('not a domain');
});

it('passes validation for a valid domain', function (): void {
    $validator = Validator::make(
        ['domain' => 'app.example.com'],
        ['domain' => [new ValidDomain]],
    );

    expect($validator->passes())->toBeTrue();
});
