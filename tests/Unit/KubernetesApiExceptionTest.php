<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Exceptions\KubernetesApiException;

function responseWith(string $body, int $status)
{
    Http::fake(['https://k8s.exception.test/*' => Http::response($body, $status)]);

    return Http::get('https://k8s.exception.test/probe');
}

it('builds a message from a failed response', function (): void {
    $exception = KubernetesApiException::fromResponse('listing certificates', responseWith('server error', 500));

    expect($exception)
        ->toBeInstanceOf(CertificateException::class)
        ->getMessage()->toContain('listing certificates')
        ->getMessage()->toContain('500')
        ->getMessage()->toContain('server error');
});

it('falls back when the response body is empty', function (): void {
    $exception = KubernetesApiException::fromResponse('fetching ingress', responseWith('', 404));

    expect($exception->getMessage())->toContain('<empty response body>');
});

it('builds a misconfiguration message', function (): void {
    expect(KubernetesApiException::misconfigured('bad config')->getMessage())->toBe('bad config');
});
