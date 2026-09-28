<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\CertificatesManager;

if (! function_exists('certificates')) {
    /**
     * Resolve the `Certificates` facade root — the same manager the facade and
     * constructor injection give you (and the fake, once `Certificates::fake()` ran).
     */
    function certificates(): CertificatesManager
    {
        return app(CertificatesManager::class);
    }
}
