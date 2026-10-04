<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Enums;

use Illuminate\Support\Facades\Lang;
use RoundlyConsulting\Enums\Helpers;

enum CertificateStatus: string
{
    use Helpers {
        readable as private headlineLabel;
    }

    case Pending = 'pending';
    case Requested = 'requested';
    case Issued = 'issued';
    case Renewing = 'renewing';
    case Renewed = 'renewed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /**
     * The status label in the current locale, from the package's `statuses` lines, so
     * label(), labels(), options() and tryFromLabel() all agree. A locale the package
     * ships no lines for keeps the trait's headline, where a host's JSON translation of
     * "Issued" etc. still applies.
     */
    public function readable(): string
    {
        $key = 'certificates::messages.statuses.'.$this->value;

        return Lang::has($key, null, false) ? (string) trans($key) : $this->headlineLabel();
    }

    /**
     * A colour hint for admin UIs (Tailwind-ish palette names).
     */
    public function color(): string
    {
        return match ($this) {
            self::Issued, self::Renewed => 'green',
            self::Pending, self::Requested, self::Renewing => 'amber',
            self::Failed, self::Revoked, self::Expired => 'red',
        };
    }

    /**
     * Whether the certificate is currently usable.
     */
    public function isActive(): bool
    {
        return $this === self::Issued || $this === self::Renewed;
    }

    /**
     * Whether the status is a dead end (no further lifecycle; only a fresh issue() revives
     * the row). Failed is not: a failed issuance or renewal may be renewed again.
     */
    public function isTerminal(): bool
    {
        return $this === self::Revoked || $this === self::Expired;
    }

    /**
     * Native state-machine guard: whether a transition to $to is allowed.
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /**
     * The set of statuses this status may legally move to.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Requested],
            self::Requested => [self::Issued, self::Failed],
            self::Issued => [self::Renewing, self::Expired, self::Revoked],
            self::Renewing => [self::Renewed, self::Failed],
            self::Renewed => [self::Renewing, self::Expired, self::Revoked],
            // A failure is usually transient (a CA hiccup, a DNS delay): the live
            // certificate still runs out, so the renewal must stay retryable.
            self::Failed => [self::Renewing],
            self::Expired, self::Revoked => [],
        };
    }
}
