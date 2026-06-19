<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\CertificateManager;

if (! function_exists('certificates')) {
    /**
     * Resolve the certificate provider manager.
     */
    function certificates(): CertificateManager
    {
        return app(CertificateManager::class);
    }
}
