<?php

declare(strict_types=1);

namespace Modules\Workflow\Enums;

/**
 * Lifecycle of a workflow instance. `pending` and `in_progress` are the two legacy "running" values stored by the engine.
 */
enum InstanceStatus: string
{
    case Running = 'in_progress';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Withdrawn = 'withdrawn';
    case Blocked = 'blocked';
    case Expired = 'expired';

    /**
     * Statuses in which steps can still be decided.
     *
     * @return list<string>
     */
    public static function active(): array
    {
        return [self::Pending->value, self::Running->value];
    }

    /**
     * Whether the instance is finished for good.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Cancelled, self::Withdrawn, self::Expired], true);
    }
}
