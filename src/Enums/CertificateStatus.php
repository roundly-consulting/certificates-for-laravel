<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Enums;

enum CertificateStatus: string
{
    case Pending = 'pending';
    case Requested = 'requested';
    case Issued = 'issued';
    case Renewing = 'renewing';
    case Renewed = 'renewed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /**
     * Human-readable, translatable label for the status.
     */
    public function label(): string
    {
        return (string) trans('certificates::messages.status.'.$this->value);
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
     * Whether the status is a dead end (no further lifecycle).
     */
    public function isTerminal(): bool
    {
        return $this === self::Failed || $this === self::Revoked || $this === self::Expired;
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
            self::Failed, self::Expired, self::Revoked => [],
        };
    }
}
