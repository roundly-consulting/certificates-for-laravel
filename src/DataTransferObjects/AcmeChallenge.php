<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Hash\Digest;

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
     * For dns-01: the TXT record value to publish — base64url(SHA-256(key
     * authorization)), per RFC 8555 §8.4. This is the exact string the CA
     * compares the published TXT record against, so it is pinned by a frozen
     * test vector.
     */
    public function dnsRecordValue(): string
    {
        return Base64Url::encode((new Digest)->raw($this->keyAuthorization));
    }
}
