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
    public static function for(string $certificateName): Lock
    {
        return Cache::lock(
            name: Settings::string('certificates.lock.name', config('certificates.lock.name'), 'certificates:generate').':'.$certificateName,
            seconds: Settings::integer('certificates.lock.locked_for_seconds', config('certificates.lock.locked_for_seconds'), 5, min: 1),
        );
    }
}
