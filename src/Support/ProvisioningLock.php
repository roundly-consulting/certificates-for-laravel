<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * The cache lock that keeps two processes from provisioning the SAME certificate at once.
 *
 * One lock per certificate name (`{certificates.lock.name}:{name}`), never one global lock:
 * a global one made every other domain's issuance return early, unprovisioned, while any
 * issuance was running. Each acquisition gets its own random owner, so a process whose
 * lock already timed out can never release the lock a later process now holds.
 *
 * @internal the issue action's and the manager's guard — not part of the public API
 */
final class ProvisioningLock
{
    /**
     * The default safety expiry: ten minutes, well above an ACME order that polls each
     * authorization and the order itself for up to attempts × seconds (30 × 2 s by default).
     */
    public const DEFAULT_SECONDS = 600;

    public static function for(string $certificateName): Lock
    {
        return Cache::lock(
            name: Settings::string('certificates.lock.name', config('certificates.lock.name'), 'certificates:generate').':'.$certificateName,
            seconds: self::seconds(),
        );
    }

    /**
     * `certificates.lock.locked_for_seconds`: how long a provisioning lock lives at most.
     */
    public static function seconds(): int
    {
        return Settings::integer('certificates.lock.locked_for_seconds', config('certificates.lock.locked_for_seconds'), self::DEFAULT_SECONDS, min: 1);
    }
}
