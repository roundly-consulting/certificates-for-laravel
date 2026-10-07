<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Acme;

use OpenSSLAsymmetricKey;
use RoundlyConsulting\Certificates\Exceptions\AcmeException;

/**
 * Generates Certificate Signing Requests (and self-signed certificates) for
 * one or more domains using ext-openssl. The CN is the first domain that fits a
 * common name (RFC 5280: 64 characters at most) — none when no domain does; every
 * domain is added as a subjectAltName so SAN/wildcard certificates work.
 */
final class Csr
{
    /**
     * RFC 5280 ub-common-name: OpenSSL refuses a longer CN, while a hostname may run to 253.
     */
    private const MAX_COMMON_NAME = 64;

    /**
     * Generate a fresh private key for a leaf certificate.
     */
    public function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($key === false) {
            throw AcmeException::keyGenerationFailed();
        }

        return $key;
    }

    /**
     * Build a DER-encoded CSR covering every supplied domain.
     *
     * @param  list<string>  $domains  Primary first, then SANs.
     */
    public function forDomains(array $domains, OpenSSLAsymmetricKey $key): string
    {
        [$configFile, $config] = $this->opensslConfig($domains);

        try {
            $csr = openssl_csr_new($this->subject($domains), $key, $config);

            if (! $csr instanceof \OpenSSLCertificateSigningRequest) {
                throw AcmeException::finalizeFailed('could not create CSR');
            }

            $pem = '';

            if (openssl_csr_export($csr, $pem) === false) {
                throw AcmeException::finalizeFailed('could not export CSR');
            }

            return $this->pemToDer($pem, 'CERTIFICATE REQUEST');
        } finally {
            @unlink($configFile);
        }
    }

    /**
     * Generate a self-signed certificate covering every supplied domain.
     *
     * @param  list<string>  $domains  Primary first, then SANs.
     * @return array{0: string, 1: string} The certificate PEM and private key PEM.
     */
    public function selfSigned(array $domains, int $days): array
    {
        $key = $this->newKey();

        [$configFile, $config] = $this->opensslConfig($domains);

        try {
            $csr = openssl_csr_new($this->subject($domains), $key, $config);

            if (! $csr instanceof \OpenSSLCertificateSigningRequest) {
                throw AcmeException::finalizeFailed('could not create CSR for self-signed certificate');
            }

            $cert = openssl_csr_sign($csr, null, $key, $days, $config);

            if (! $cert instanceof \OpenSSLCertificate) {
                throw AcmeException::finalizeFailed('could not sign self-signed certificate');
            }

            $certPem = '';
            openssl_x509_export($cert, $certPem);

            $keyPem = '';
            openssl_pkey_export($key, $keyPem, null, $config);

            return [$certPem, $keyPem];
        } finally {
            @unlink($configFile);
        }
    }

    /**
     * Export a private key to PEM.
     */
    public function exportKey(OpenSSLAsymmetricKey $key): string
    {
        $pem = '';

        if (openssl_pkey_export($key, $pem) === false) {
            throw AcmeException::keyGenerationFailed();
        }

        return $pem;
    }

    /**
     * Write a temporary OpenSSL config enabling subjectAltName for the domains.
     *
     * @param  list<string>  $domains
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function opensslConfig(array $domains): array
    {
        $altNames = [];

        foreach (array_values(array_unique($domains)) as $index => $domain) {
            $altNames[] = 'DNS.'.($index + 1).' = '.$domain;
        }

        $contents = implode("\n", [
            '[req]',
            'distinguished_name = req_distinguished_name',
            'req_extensions = v3_req',
            'prompt = no',
            '[req_distinguished_name]',
            ...array_map(static fn (string $cn): string => 'commonName = '.$cn, array_values($this->subject($domains))),
            '[v3_req]',
            'basicConstraints = CA:FALSE',
            'keyUsage = nonRepudiation, digitalSignature, keyEncipherment',
            'subjectAltName = @alt_names',
            '[alt_names]',
            implode("\n", $altNames),
            '',
        ]);

        $file = (string) tempnam(sys_get_temp_dir(), 'csr');
        file_put_contents($file, $contents);

        return [$file, [
            'config' => $file,
            'req_extensions' => 'v3_req',
            'x509_extensions' => 'v3_req',
            'digest_alg' => 'sha256',
        ]];
    }

    /**
     * The subject: the first domain that fits a common name, or none — the subjectAltName
     * extension names every domain either way, and that is what clients and CAs check.
     *
     * @param  list<string>  $domains
     * @return array<string, string>
     */
    private function subject(array $domains): array
    {
        foreach ($domains as $domain) {
            if (strlen($domain) <= self::MAX_COMMON_NAME) {
                return ['commonName' => $domain];
            }
        }

        return [];
    }

    private function pemToDer(string $pem, string $label): string
    {
        $body = (string) preg_replace(
            '/-----BEGIN '.$label.'-----|-----END '.$label.'-----|\s+/',
            '',
            $pem,
        );

        $der = base64_decode($body, true);

        if ($der === false) {
            throw AcmeException::finalizeFailed('could not decode CSR DER');
        }

        return $der;
    }
}
