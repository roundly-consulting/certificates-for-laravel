<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Exceptions;

use Throwable;

final class AcmeException extends CertificateException
{
    public static function directoryUnavailable(string $url): self
    {
        return new self("Unable to fetch the ACME directory at [{$url}].");
    }

    public static function nonceUnavailable(): self
    {
        return new self('The ACME server did not return a replay nonce.');
    }

    public static function accountFailed(string $detail): self
    {
        return new self("ACME account registration failed: {$detail}");
    }

    public static function orderFailed(string $detail): self
    {
        return new self("ACME order failed: {$detail}");
    }

    public static function challengeFailed(string $domain, string $detail = ''): self
    {
        $suffix = $detail === '' ? '' : " ({$detail})";

        return new self("ACME challenge validation failed for [{$domain}]{$suffix}.");
    }

    public static function finalizeFailed(string $detail): self
    {
        return new self("ACME order finalization failed: {$detail}");
    }

    public static function downloadFailed(string $detail): self
    {
        return new self("Downloading the issued certificate failed: {$detail}");
    }

    /**
     * The boundary for every crypto failure raised while signing a request —
     * the underlying CryptoException is kept as the cause, never leaked.
     */
    public static function signingFailed(?Throwable $previous = null): self
    {
        return new self('Failed to sign the ACME request payload.', previous: $previous);
    }

    public static function keyGenerationFailed(): self
    {
        return new self('Failed to generate a cryptographic key via OpenSSL.');
    }

    public static function unexpectedKey(): self
    {
        return new self('The account key is of an unsupported type.');
    }
}
