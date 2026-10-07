<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use RoundlyConsulting\Certificates\DataTransferObjects\ParsedCertificate;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Crypto\Exceptions\CryptoException;
use RoundlyConsulting\Crypto\X509\Certificate;

/**
 * Reads a PEM-encoded X.509 certificate into the package's own
 * {@see ParsedCertificate}, so every provider and store sees certificate
 * metadata identically.
 *
 * The parsing is crypto-for-laravel's — this is the boundary that maps its
 * facts onto our DTO and re-raises its exceptions as ours. Two mappings are
 * wire data and must not drift:
 *
 *  - the fingerprint is persisted UPPER-case (OpenSSL, and therefore crypto,
 *    emits lower-case), and it is only ever compared as a string;
 *  - an issuer with no CN falls back to its organization.
 *
 * Whether the certificate is self-signed is read from the whole issuer and subject
 * names plus the signature, never from the common names: a certificate may carry
 * no CN at all.
 *
 * Crypto reports the facts; what to do about an expired or unexpected
 * certificate is decided here and in the providers, never there.
 */
final class CertificateMapper
{
    /**
     * @throws CertificateException When the PEM cannot be parsed.
     */
    public function parse(string $pem): ParsedCertificate
    {
        try {
            $certificate = Certificate::fromPem($this->firstCertificate($pem));
        } catch (CryptoException) {
            throw CertificateException::unparseable();
        }

        $issuer = $certificate->issuer();

        return new ParsedCertificate(
            notBefore: $certificate->notBefore(),
            notAfter: $certificate->notAfter(),
            commonName: $certificate->commonName() ?? '',
            subjectAltNames: $certificate->dnsNames(),
            issuer: $issuer->commonName ?? $issuer->organization,
            serial: $certificate->serialNumber(),
            fingerprint: strtoupper($certificate->fingerprint()),
            selfSigned: $this->selfSigned($certificate),
        );
    }

    /**
     * RFC 5280 §3.2: self-issued (the issuer name equals the subject name) and verifiable
     * with the certificate's own public key.
     */
    private function selfSigned(Certificate $certificate): bool
    {
        return $certificate->issuer()->toString() === $certificate->subject()->toString()
            && $certificate->isSelfSigned();
    }

    /**
     * A PEM bundle carries a leaf plus its chain; only the leaf describes the
     * certificate we issued, so only the first block is read.
     */
    private function firstCertificate(string $pem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $matches) === 1) {
            return $matches[0];
        }

        return $pem;
    }
}
