<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\Testing\CertificatesFake;

/**
 * @method static \RoundlyConsulting\Certificates\CertificatesManager on(?string $connection)
 * @method static \Illuminate\Support\Collection<int, \RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate> get()
 * @method static bool exists(string $domain)
 * @method static bool generate(string $domain)
 * @method static \RoundlyConsulting\Certificates\Models\Certificate issue(\RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData $data)
 * @method static \RoundlyConsulting\Certificates\Models\Certificate issueIfMissing(string $domain)
 * @method static \RoundlyConsulting\Certificates\Support\CertificateBuilder for(string|list<string> $domain)
 * @method static \RoundlyConsulting\Certificates\Models\Certificate|null find(string $domain, ?string $driver = null)
 * @method static \RoundlyConsulting\Certificates\Enums\CertificateStatus|null status(string $domain)
 * @method static \RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport|null statusReport(string $domain, ?string $driver = null, bool $fresh = false)
 * @method static \Illuminate\Database\Eloquent\Collection<int, \RoundlyConsulting\Certificates\Models\Certificate> expiring(?int $days = null, ?string $driver = null)
 * @method static \RoundlyConsulting\Certificates\Models\Certificate renew(\RoundlyConsulting\Certificates\Models\Certificate|string $certificate)
 * @method static \RoundlyConsulting\Certificates\Models\Certificate renewLater(\RoundlyConsulting\Certificates\Models\Certificate|string $certificate)
 * @method static \Illuminate\Database\Eloquent\Collection<int, \RoundlyConsulting\Certificates\Models\Certificate> renewDue(?int $thresholdDays = null, bool $queue = false)
 * @method static \RoundlyConsulting\Certificates\Models\Certificate revoke(\RoundlyConsulting\Certificates\Models\Certificate|string $certificate, ?string $reason = null)
 * @method static \RoundlyConsulting\Certificates\Models\Certificate expire(\RoundlyConsulting\Certificates\Models\Certificate|string $certificate)
 * @method static int sync(?string $driver = null)
 * @method static int prune(int $days = 30, \RoundlyConsulting\Certificates\Enums\CertificateStatus|string|null $status = null)
 * @method static \RoundlyConsulting\Alerts\Support\PendingScheduledCheck monitorExpiry(\RoundlyConsulting\Certificates\Models\Certificate $certificate, ?\Illuminate\Database\Eloquent\Model $notifiable = null)
 * @method static \RoundlyConsulting\Certificates\Contracts\CertificateProvider driver(?string $name = null)
 * @method static \RoundlyConsulting\Certificates\CertificatesManager extend(string $driver, \Closure $callback)
 * @method static string certificateName(string $domain)
 * @method static \RoundlyConsulting\Certificates\Testing\CertificatesFake seed(\RoundlyConsulting\Certificates\Models\Certificate ...$certificates)
 * @method static void assertIssued(string $domain)
 * @method static void assertNotIssued(string $domain)
 * @method static void assertIssuedCount(int $count)
 * @method static void assertNothingIssued()
 * @method static void assertRequested(string $domain)
 * @method static void assertFailed(string $domain)
 * @method static void assertRenewed(string $domain)
 * @method static void assertNotRenewed(string $domain)
 * @method static void assertNothingRenewed()
 * @method static void assertRenewedLater(string $domain)
 * @method static void assertNothingRenewedLater()
 * @method static void assertRenewedDue(?int $thresholdDays = null)
 * @method static void assertNothingRenewedDue()
 * @method static void assertRevoked(string $domain, ?string $reason = null)
 * @method static void assertNotRevoked(string $domain)
 * @method static void assertNothingRevoked()
 * @method static void assertExpired(string $domain)
 * @method static void assertNothingExpired()
 * @method static void assertSynced(?string $driver = null)
 * @method static void assertNothingSynced()
 * @method static void assertPruned(?int $days = null)
 * @method static void assertNothingPruned()
 *
 * @see CertificatesManager
 * @see CertificatesFake
 */
final class Certificates extends Facade
{
    /**
     * Swap the manager for an in-memory recording fake (facade and container alike).
     */
    public static function fake(): CertificatesFake
    {
        $fake = app(CertificatesFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return CertificatesManager::class;
    }
}
