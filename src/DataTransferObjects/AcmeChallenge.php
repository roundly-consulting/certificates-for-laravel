<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

final readonly class AcmeChallenge
{
    public function __construct(
        public string $type,
        public string $domain,
        public string $token,
        public string $keyAuthorization,
        public string $authorizationUrl,
        public string $challengeUrl,
    ) {}

    /**
     * For http-01: the path the challenge file is served at.
     */
    public function httpPath(): string
    {
        return '/.well-known/acme-challenge/'.$this->token;
    }

    /**
     * For dns-01: the TXT record name to publish.
     */
    public function dnsRecordName(): string
    {
        return '_acme-challenge.'.ltrim($this->domain, '*.');
    }

    /**
     * For dns-01: the TXT record value to publish.
     */
    public function dnsRecordValue(): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $this->keyAuthorization, true)), '+/', '-_'), '=');
    }
}
