<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Certificates\CertificateService;

/**
 * @method static \Illuminate\Support\Collection<int, \RoundlyConsulting\Certificates\Certificate> get()
 * @method static bool exists(string $domain)
 * @method static bool generate(string $domain)
 * @method static string certificateName(string $domain)
 *
 * @see CertificateService
 */
final class Certificates extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CertificateService::class;
    }
}
