<?php

declare(strict_types=1);

namespace Modules\Contracts\Enums;

/**
 * Lifecycle of a contract of the register: draft, review, active, renewal (still in force while the renewal is
 * negotiated), then expired or terminated. Customer and supplier contracts had their own spellings before the register
 * (`pending_approval`, `cancelled`); they are read as the status they mean.
 */
enum ContractStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Active = 'active';
    case Renewal = 'renewal';
    case Expired = 'expired';
    case Terminated = 'terminated';

    /**
     * The status a stored value stands for, or null for a value the lifecycle does not know (such as `suspended`).
     */
    public static function fromStored(?string $stored): ?self
    {
        return match ($stored) {
            'pending_approval' => self::Review,
            'cancelled' => self::Terminated,
            default => self::tryFrom((string) $stored),
        };
    }

    /**
     * Statuses reachable from this one in one step.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Review, self::Active],
            self::Review => [self::Draft, self::Active],
            self::Active => [self::Renewal, self::Expired, self::Terminated],
            self::Renewal => [self::Active, self::Expired, self::Terminated],
            self::Expired => [self::Renewal, self::Active],
            self::Terminated => [],
        };
    }

    /**
     * Whether the contract is in force: its deadlines are watched and it can be renewed.
     */
    public function isInForce(): bool
    {
        return in_array($this, [self::Active, self::Renewal], true);
    }
}
