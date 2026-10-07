<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Certificates\DataTransferObjects\StoredCertificate;
use RoundlyConsulting\Certificates\Facades\Certificates;
use RoundlyConsulting\Certificates\Stores\FilesystemCertificateStore;
use RoundlyConsulting\Certificates\Tests\Fixtures\AcmeTestCa;

/**
 * Regression (chat review C-19): the acme and filesystem drivers named a stored certificate
 * by its common name only, and a certificate without one (allowed: the SAN extension carries
 * the names, and long hostnames have no CN) was synced into the registry with an empty domain.
 */
beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * A self-signed certificate with no subject at all, valid for `$host` through its SAN only.
 *
 * @return array{0: string, 1: string} certificate PEM, private key PEM
 */
function cnLessCertificate(string $host): array
{
    $config = (string) tempnam(sys_get_temp_dir(), 'cnless');
    file_put_contents($config, implode("\n", [
        '[req]', 'distinguished_name = dn', 'prompt = no', '[dn]',
        '[ext]', 'subjectAltName = DNS:'.$host, '',
    ]));

    try {
        $options = ['config' => $config, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = openssl_csr_new([], $key, $options);
        $certificate = openssl_csr_sign($csr, null, $key, 30, $options);

        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem, null, $options);

        return [$certificatePem, $keyPem];
    } finally {
        @unlink($config);
    }
}

function storeCnLess(string $name, string $host): void
{
    [$certificate, $key] = cnLessCertificate($host);

    expect(openssl_x509_parse($certificate)['subject'] ?? null)->toBe([]);

    (new FilesystemCertificateStore(disk: 'local', path: 'certificates'))
        ->put($name, new StoredCertificate($certificate, $key));
}

it('syncs a certificate without a common name under its first SAN domain', function (): void {
    storeCnLess('generated-tls-cnless-example-com', 'cnless.example.com');

    expect(Certificates::sync('filesystem'))->toBe(1)
        ->and(Certificates::find('cnless.example.com')?->name)->toBe('generated-tls-cnless-example-com');
});

it('lists a stored acme certificate without a common name under its first SAN domain', function (): void {
    storeCnLess('generated-tls-cnless-example-com', 'cnless.example.com');

    expect(AcmeTestCa::provider()->get()->sole()->domain)->toBe('cnless.example.com');
});
