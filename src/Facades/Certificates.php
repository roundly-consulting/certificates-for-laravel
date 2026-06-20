<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Certificates\CertificateService;
use RoundlyConsulting\Certificates\Testing\CertificatesFake;

/**
 * @method static \Illuminate\Support\Collection<int, \RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate> get()
 * @method static bool exists(string $domain)
 * @method static bool generate(string $domain)
 * @method static string certificateName(string $domain)
 * @method static \RoundlyConsulting\Certificates\CertificateService on(?string $connection)
 * @method static \RoundlyConsulting\Certificates\Support\CertificateBuilder for(string|list<string> $domain)
 * @method static \RoundlyConsulting\Certificates\Models\Certificate issue(\RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData $data)
 * @method static \RoundlyConsulting\Certificates\Models\Certificate issueIfMissing(string $domain)
 * @method static \RoundlyConsulting\Certificates\Models\Certificate|null find(string $domain, ?string $driver = null)
 * @method static \RoundlyConsulting\Certificates\Enums\CertificateStatus|null status(string $domain)
 * @method static \RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport|null statusReport(string $domain, ?string $driver = null, bool $fresh = false)
 * @method static \RoundlyConsulting\Certificates\Contracts\CertificateProvider driver(?string $name = null)
 *
 * @see CertificateService
 */
final class Certificates extends Facade
{
    public static function fake(): CertificatesFake
    {
        $fake = new CertificatesFake;

        self::swap($fake);

        app()->instance(CertificateService::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return CertificateService::class;
    }
}
