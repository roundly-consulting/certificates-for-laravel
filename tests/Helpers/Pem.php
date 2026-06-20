<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Helpers;

/**
 * Test-only helper that generates real self-signed PEM material via ext-openssl,
 * so the X.509 parser and stores can be exercised against genuine certificates.
 */
final class Pem
{
    /**
     * @param  list<string>  $domains
     * @return array{cert: string, key: string}
     */
    public static function selfSigned(array $domains, int $days = 90): array
    {
        $altNames = [];

        foreach ($domains as $index => $domain) {
            $altNames[] = 'DNS.'.($index + 1).' = '.$domain;
        }

        $configContents = implode("\n", [
            '[req]',
            'distinguished_name = dn',
            'req_extensions = v3_req',
            'x509_extensions = v3_req',
            'prompt = no',
            '[dn]',
            'CN = '.$domains[0],
            '[v3_req]',
            'basicConstraints = CA:FALSE',
            'subjectAltName = @alt',
            '[alt]',
            implode("\n", $altNames),
            '',
        ]);

        $configFile = (string) tempnam(sys_get_temp_dir(), 'pem');
        file_put_contents($configFile, $configContents);

        $config = [
            'config' => $configFile,
            'req_extensions' => 'v3_req',
            'x509_extensions' => 'v3_req',
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $key = openssl_pkey_new($config);
        $csr = openssl_csr_new(['commonName' => $domains[0]], $key, $config);
        $cert = openssl_csr_sign($csr, null, $key, $days, $config);

        $certPem = '';
        openssl_x509_export($cert, $certPem);

        $keyPem = '';
        openssl_pkey_export($key, $keyPem, null, $config);

        @unlink($configFile);

        return ['cert' => $certPem, 'key' => $keyPem];
    }
}
