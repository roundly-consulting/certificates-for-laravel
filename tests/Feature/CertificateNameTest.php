<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Regression: the secret name came from Str::kebab, which is case-sensitive and not
 * DNS-safe — `App.Example.com` became `generated-tls-app--example-com` (a second secret and
 * registry row for the same host) and a wildcard kept its `*`, which Kubernetes rejects.
 */
const DNS_1123_SUBDOMAIN = '/^[a-z0-9]([-a-z0-9]*[a-z0-9])?(\.[a-z0-9]([-a-z0-9]*[a-z0-9])?)*$/';

beforeEach(function (): void {
    config()->set('certificates.default', 'array');
});

it('derives a DNS-1123 safe, case-normalized name', function (string $domain, string $expected): void {
    expect(Certificates::certificateName($domain))
        ->toBe($expected)
        ->toMatch(DNS_1123_SUBDOMAIN);
})->with([
    'plain' => ['app.example.com', 'generated-tls-app-example-com'],
    'mixed case' => ['App.Example.COM', 'generated-tls-app-example-com'],
    'wildcard' => ['*.example.com', 'generated-tls-wildcard-example-com'],
    'wildcard, mixed case' => ['*.Example.com', 'generated-tls-wildcard-example-com'],
    'port' => ['app.example.com:8443', 'generated-tls-app-example-com-8443'],
    'punycode' => ['xn--bcher-kva.example', 'generated-tls-xn--bcher-kva-example'],
]);

it('keeps a name within the 253-character Kubernetes limit', function (): void {
    $domain = implode('.', array_fill(0, 4, str_repeat('a', 63)));

    $name = Certificates::certificateName($domain);

    expect(strlen($name))->toBeLessThanOrEqual(253)
        ->and($name)->toMatch(DNS_1123_SUBDOMAIN)
        ->and(Certificates::certificateName(Str::replaceLast('a', 'b', $domain)))->not->toBe($name);
});

/**
 * An exception to the fleet's "blank = not set" rule: an empty `name_prefix` is a meaningful
 * value — bare host names — so a blank `CERTIFICATES_NAME_PREFIX=` never falls back to the
 * `generated-tls-` default. Only a prefix that is not set (absent or null) takes the default.
 */
it('names certificates after the bare host for a blank prefix, never the default', function (string $blank): void {
    config()->set('certificates.name_prefix', $blank);

    expect(Certificates::certificateName('app.example.com'))->toBe('app-example-com')
        ->and(Certificates::certificateName('*.Example.com'))->toBe('wildcard-example-com');
})->with(['empty' => [''], 'whitespace' => ['  ']]);

it('takes the generated-tls- prefix only when the prefix is not set', function (Closure $leaveUnset): void {
    $leaveUnset();

    expect(Certificates::certificateName('app.example.com'))->toBe('generated-tls-app-example-com');
})->with([
    'the shipped config' => [static function (): void {}],
    'absent' => [static fn () => config()->set('certificates', Arr::except((array) config('certificates'), 'name_prefix'))],
    'null' => [static fn () => config()->set('certificates.name_prefix', null)],
]);

it('reads CERTIFICATES_NAME_PREFIX= as bare names and an unset one as the default', function (?string $env, string $expected): void {
    $previous = getenv('CERTIFICATES_NAME_PREFIX');
    putenv($env === null ? 'CERTIFICATES_NAME_PREFIX' : "CERTIFICATES_NAME_PREFIX={$env}");

    try {
        $shipped = (require __DIR__.'/../../config/certificates.php')['name_prefix'];
    } finally {
        putenv($previous === false ? 'CERTIFICATES_NAME_PREFIX' : "CERTIFICATES_NAME_PREFIX={$previous}");
    }

    config()->set('certificates.name_prefix', $shipped);

    expect(Certificates::certificateName('app.example.com'))->toBe($expected);
})->with([
    'unset' => [null, 'generated-tls-app-example-com'],
    'blank' => ['', 'app-example-com'],
]);

it('refuses a non-string prefix instead of reading it as either answer', function (mixed $junk): void {
    config()->set('certificates.name_prefix', $junk);

    expect(fn () => Certificates::certificateName('app.example.com'))
        ->toThrow(InvalidConfigurationException::class, '[certificates.name_prefix]');
})->with([
    'false' => [false], 'true' => [true], 'int' => [5], 'array' => [['generated-tls-']],
]);

it('normalizes a host prefix into the safe alphabet too', function (): void {
    config()->set('certificates.name_prefix', 'My_TLS.');

    expect(Certificates::certificateName('app.example.com'))->toBe('my-tls-app-example-com');
});

it('treats differently-cased domains as the same certificate', function (): void {
    $first = Certificates::issue(IssueCertificateData::make('App.Example.com'));
    $second = Certificates::issue(IssueCertificateData::make('app.example.com'));

    expect($second->id)->toBe($first->id)
        ->and($second->domain)->toBe('app.example.com')
        ->and(Certificate::query()->count())->toBe(1)
        ->and(Certificates::find('APP.example.com')?->is($first))->toBeTrue()
        ->and(Certificates::for('App.Example.com')->find()?->is($first))->toBeTrue();
});

it('records SAN domains lowercased and without duplicates', function (): void {
    $certificate = Certificates::for(['Example.com', 'WWW.example.com', 'www.example.com'])->issue();

    expect($certificate->domain)->toBe('example.com')
        ->and($certificate->domains)->toBe(['example.com', 'www.example.com'])
        ->and(Certificate::query()->coveringDomain('WWW.Example.com')->first()?->is($certificate))->toBeTrue();
});

it('normalizes domains the same way under the fake', function (): void {
    Certificates::fake();

    $certificate = Certificates::for('App.Example.com')->issue();

    expect($certificate->domain)->toBe('app.example.com')
        ->and($certificate->name)->toBe('generated-tls-app-example-com')
        ->and(Certificates::find('APP.example.com'))->toBe($certificate)
        ->and(Certificates::exists('app.EXAMPLE.com'))->toBeTrue();

    Certificates::assertIssued('app.example.com');
    Certificates::assertIssued('App.Example.com');
});
