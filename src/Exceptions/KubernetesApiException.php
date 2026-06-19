<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Exceptions;

use Illuminate\Http\Client\Response;

final class KubernetesApiException extends CertificateException
{
    public static function fromResponse(string $action, Response $response): self
    {
        return new self(sprintf(
            'Kubernetes API request failed while %s (HTTP %d): %s',
            $action,
            $response->status(),
            $response->body() !== '' ? $response->body() : '<empty response body>',
        ));
    }

    public static function misconfigured(string $message): self
    {
        return new self($message);
    }
}
