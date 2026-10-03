<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Providers\KubernetesProvider;

it('reads the bearer token from a mounted file when no inline token is set', function (): void {
    $tokenFile = tempnam(sys_get_temp_dir(), 'k8s-token');
    file_put_contents($tokenFile, "file-token\n");

    config()->set('certificates.drivers.kubernetes.token', null);
    config()->set('certificates.drivers.kubernetes.token_path', $tokenFile);

    // Re-resolve the singleton with the new config.
    app()->forgetInstance(CertificateProvider::class);

    expect(app(CertificateProvider::class))->toBeInstanceOf(KubernetesProvider::class);

    unlink($tokenFile);
});

it('resolves with an empty token when none is configured', function (): void {
    config()->set('certificates.drivers.kubernetes.token', null);
    config()->set('certificates.drivers.kubernetes.token_path', null);

    app()->forgetInstance(CertificateProvider::class);

    expect(app(CertificateProvider::class))->toBeInstanceOf(KubernetesProvider::class);
});
