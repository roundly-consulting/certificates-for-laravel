<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Acme;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\DataTransferObjects\AcmeChallenge;
use RoundlyConsulting\Certificates\DataTransferObjects\AcmeOrder;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Crypto\Codec\Base64Url;

/**
 * Low-level ACME v2 (RFC 8555) protocol client over Laravel's HTTP client.
 * Handles the directory, nonce management, JWS-signed POSTs (and POST-as-GET),
 * orders, authorizations, finalization, and certificate download.
 */
final class AcmeClient
{
    /** @var array<string, string>|null */
    private ?array $directory = null;

    private ?string $nonce = null;

    public function __construct(
        private readonly Jws $jws,
        private readonly AcmeAccount $account,
        private readonly string $directoryUrl,
        private readonly ?string $contact = null,
        private readonly string|bool $verify = true,
        private readonly string $challengeType = 'http-01',
    ) {}

    /**
     * @return array<string, string>
     */
    public function directory(): array
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        $response = $this->request()->get($this->directoryUrl);

        if ($response->failed()) {
            throw AcmeException::directoryUnavailable($this->directoryUrl);
        }

        /** @var array<string, string> $body */
        $body = $response->json();

        return $this->directory = $body;
    }

    public function newNonce(): string
    {
        $response = $this->request()->head($this->endpoint('newNonce'));

        $nonce = $response->header('Replay-Nonce');

        if ($nonce === '') {
            throw AcmeException::nonceUnavailable();
        }

        return $this->nonce = $nonce;
    }

    /**
     * Register (or look up) this key's account at THIS directory, persisting the returned
     * kid under the directory — a kid from another CA is never reused here.
     */
    public function registerAccount(): string
    {
        if (($kid = $this->account->kid($this->directoryUrl)) !== null) {
            return $kid;
        }

        $payload = ['termsOfServiceAgreed' => true];

        if ($this->contact !== null && $this->contact !== '') {
            $payload['contact'] = ['mailto:'.$this->contact];
        }

        $response = $this->signedRequest($this->endpoint('newAccount'), $payload, useJwk: true);

        if ($response->failed()) {
            throw AcmeException::accountFailed((string) $response->body());
        }

        $kid = $response->header('Location');

        if ($kid === '') {
            throw AcmeException::accountFailed('the server did not return an account URL');
        }

        $this->account->setKid($this->directoryUrl, $kid);

        return $kid;
    }

    /**
     * @param  list<string>  $domains
     */
    public function newOrder(array $domains): AcmeOrder
    {
        $identifiers = array_map(
            static fn (string $domain): array => ['type' => 'dns', 'value' => $domain],
            $domains,
        );

        $response = $this->signedRequest($this->endpoint('newOrder'), ['identifiers' => $identifiers]);

        if ($response->failed()) {
            throw AcmeException::orderFailed((string) $response->body());
        }

        return $this->orderFromResponse($response->header('Location'), $response);
    }

    /**
     * Build the AcmeChallenge for a single authorization URL.
     */
    public function challengeFor(string $authorizationUrl): AcmeChallenge
    {
        $response = $this->signedRequest($authorizationUrl, '');

        if ($response->failed()) {
            throw AcmeException::orderFailed('could not read authorization: '.$response->body());
        }

        /** @var array<string, mixed> $body */
        $body = $response->json();

        $domain = (string) ($body['identifier']['value'] ?? '');

        /** @var list<array<string, mixed>> $challenges */
        $challenges = $body['challenges'] ?? [];

        foreach ($challenges as $challenge) {
            if (($challenge['type'] ?? null) === $this->challengeType) {
                $token = (string) ($challenge['token'] ?? '');

                return new AcmeChallenge(
                    type: $this->challengeType,
                    domain: $domain,
                    token: $token,
                    keyAuthorization: $token.'.'.$this->account->thumbprint(),
                    authorizationUrl: $authorizationUrl,
                    challengeUrl: (string) ($challenge['url'] ?? ''),
                );
            }
        }

        throw AcmeException::challengeFailed($domain, 'no '.$this->challengeType.' challenge offered');
    }

    public function respondToChallenge(AcmeChallenge $challenge): void
    {
        // RFC 8555 §7.5.1: the response carries an empty JSON object payload —
        // not POST-as-GET's empty string. Jws encodes `[]` as `{}` for exactly this.
        $response = $this->signedRequest($challenge->challengeUrl, []);

        if ($response->failed()) {
            throw AcmeException::challengeFailed($challenge->domain, (string) $response->body());
        }
    }

    public function pollAuthorization(string $authorizationUrl, string $domain, int $attempts, int $seconds): void
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $response = $this->signedRequest($authorizationUrl, '');

            /** @var array<string, mixed> $body */
            $body = $response->json();
            $status = (string) ($body['status'] ?? 'pending');

            if ($status === 'valid') {
                return;
            }

            if ($status === 'invalid') {
                throw AcmeException::challengeFailed($domain, 'authorization invalid');
            }

            if ($seconds > 0) {
                sleep($seconds);
            }
        }

        throw AcmeException::challengeFailed($domain, 'timed out waiting for validation');
    }

    public function finalize(string $finalizeUrl, string $csrDer): AcmeOrder
    {
        $response = $this->signedRequest($finalizeUrl, ['csr' => Base64Url::encode($csrDer)]);

        if ($response->failed()) {
            throw AcmeException::finalizeFailed((string) $response->body());
        }

        return $this->orderFromResponse($response->header('Location'), $response);
    }

    public function pollOrder(string $orderUrl, int $attempts, int $seconds): AcmeOrder
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $response = $this->signedRequest($orderUrl, '');
            $order = $this->orderFromResponse($orderUrl, $response);

            if ($order->status === 'valid') {
                return $order;
            }

            if ($order->status === 'invalid') {
                throw AcmeException::finalizeFailed('order became invalid');
            }

            if ($seconds > 0) {
                sleep($seconds);
            }
        }

        throw AcmeException::finalizeFailed('timed out waiting for the order to complete');
    }

    public function downloadCertificate(string $certificateUrl): string
    {
        $response = $this->signedRequest($certificateUrl, '');

        if ($response->failed()) {
            throw AcmeException::downloadFailed((string) $response->body());
        }

        return $response->body();
    }

    /**
     * Sign and send a JWS POST (empty payload string => POST-as-GET), with one
     * automatic retry on a badNonce error.
     *
     * @param  array<string, mixed>|string  $payload
     */
    private function signedRequest(string $url, array|string $payload, bool $useJwk = false): Response
    {
        $response = $this->sendSigned($url, $payload, $useJwk);

        $this->captureNonce($response);

        if ($response->status() === 400 && str_contains((string) $response->body(), 'badNonce')) {
            $this->nonce = null;
            $response = $this->sendSigned($url, $payload, $useJwk);
            $this->captureNonce($response);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>|string  $payload
     */
    private function sendSigned(string $url, array|string $payload, bool $useJwk): Response
    {
        $protected = [
            'nonce' => $this->nonce ?? $this->newNonce(),
            'url' => $url,
        ];

        $key = $this->account->load();

        // ACME embeds the account JWK only for newAccount; every later request
        // authenticates with the account kid the server handed back.
        $body = $useJwk
            ? $this->jws->signWithJwk($protected, $payload, $key, $this->account->jwk())
            : $this->jws->signWithKid($protected, $payload, $key, (string) $this->account->kid($this->directoryUrl));

        return $this->request()
            ->withBody((string) json_encode($body), 'application/jose+json')
            ->post($url);
    }

    private function captureNonce(Response $response): void
    {
        $nonce = $response->header('Replay-Nonce');

        if ($nonce !== '') {
            $this->nonce = $nonce;
        }
    }

    private function orderFromResponse(string $orderUrl, Response $response): AcmeOrder
    {
        /** @var array<string, mixed> $body */
        $body = $response->json();

        /** @var list<array<string, mixed>> $identifiers */
        $identifiers = $body['identifiers'] ?? [];

        $domains = array_map(
            static fn (array $identifier): string => (string) ($identifier['value'] ?? ''),
            $identifiers,
        );

        /** @var list<string> $authorizations */
        $authorizations = $body['authorizations'] ?? [];

        return new AcmeOrder(
            orderUrl: $orderUrl,
            finalizeUrl: (string) ($body['finalize'] ?? ''),
            domains: $domains,
            authorizationUrls: $authorizations,
            status: (string) ($body['status'] ?? 'pending'),
            certificateUrl: isset($body['certificate']) ? (string) $body['certificate'] : null,
        );
    }

    private function endpoint(string $key): string
    {
        return $this->directory()[$key] ?? throw AcmeException::directoryUnavailable($this->directoryUrl);
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->withOptions(['verify' => $this->verify]);
    }
}
