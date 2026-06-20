<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Support\X509Parser;
use RoundlyConsulting\Certificates\Tests\Helpers\Pem;

it('parses a self-signed certificate', function (): void {
    $material = Pem::selfSigned(['example.com', 'www.example.com'], days: 30);

    $parsed = (new X509Parser)->parse($material['cert']);

    expect($parsed->commonName)->toBe('example.com')
        ->and($parsed->subjectAltNames)->toContain('example.com', 'www.example.com')
        ->and($parsed->notAfter->isFuture())->toBeTrue()
        ->and($parsed->notBefore->isPast())->toBeTrue()
        ->and($parsed->fingerprint)->not->toBeNull()
        ->and($parsed->serial)->not->toBeNull()
        ->and($parsed->isExpired())->toBeFalse();
});

it('parses only the first certificate in a bundle', function (): void {
    $leaf = Pem::selfSigned(['leaf.com']);
    $chain = Pem::selfSigned(['chain.com']);

    $parsed = (new X509Parser)->parse($leaf['cert']."\n".$chain['cert']);

    expect($parsed->commonName)->toBe('leaf.com');
});

it('throws on unparseable pem', function (): void {
    (new X509Parser)->parse('not a certificate');
})->throws(CertificateException::class);
