<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\DataTransferObjects\ParsedCertificate;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;

/**
 * Parses PEM-encoded X.509 certificates using ext-openssl, so every provider
 * and store reads certificate metadata identically.
 */
final class X509Parser
{
    /**
     * @throws CertificateException When the PEM cannot be parsed.
     */
    public function parse(string $pem): ParsedCertificate
    {
        $leaf = $this->firstCertificate($pem);

        $parsed = openssl_x509_parse($leaf, true);

        if ($parsed === false) {
            throw CertificateException::unparseable();
        }

        $commonName = '';

        if (isset($parsed['subject']['CN'])) {
            $cn = $parsed['subject']['CN'];
            $commonName = is_array($cn) ? (string) ($cn[0] ?? '') : (string) $cn;
        }

        $issuer = null;

        if (isset($parsed['issuer']['CN'])) {
            $issuerCn = $parsed['issuer']['CN'];
            $issuer = is_array($issuerCn) ? (string) ($issuerCn[0] ?? '') : (string) $issuerCn;
        } elseif (isset($parsed['issuer']['O'])) {
            $issuerO = $parsed['issuer']['O'];
            $issuer = is_array($issuerO) ? (string) ($issuerO[0] ?? '') : (string) $issuerO;
        }

        $serial = isset($parsed['serialNumberHex'])
            ? strtoupper((string) $parsed['serialNumberHex'])
            : (isset($parsed['serialNumber']) ? (string) $parsed['serialNumber'] : null);

        $fingerprint = openssl_x509_fingerprint($leaf, 'sha256');

        $notBefore = (int) ($parsed['validFrom_time_t'] ?? 0);
        $notAfter = (int) ($parsed['validTo_time_t'] ?? 0);

        return new ParsedCertificate(
            notBefore: CarbonImmutable::createFromTimestampUTC($notBefore),
            notAfter: CarbonImmutable::createFromTimestampUTC($notAfter),
            commonName: $commonName,
            subjectAltNames: $this->subjectAltNames($parsed),
            issuer: $issuer,
            serial: $serial,
            fingerprint: $fingerprint === false ? null : strtoupper($fingerprint),
        );
    }

    /**
     * Extract DNS SANs from the parsed subjectAltName extension string
     * ("DNS:a.com, DNS:b.com").
     *
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function subjectAltNames(array $parsed): array
    {
        $raw = $parsed['extensions']['subjectAltName'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $names = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = substr($entry, 4);
            }
        }

        return $names;
    }

    /**
     * A PEM bundle may contain a leaf plus its chain; parse only the first
     * certificate block.
     */
    private function firstCertificate(string $pem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $matches) === 1) {
            return $matches[0];
        }

        return $pem;
    }
}
