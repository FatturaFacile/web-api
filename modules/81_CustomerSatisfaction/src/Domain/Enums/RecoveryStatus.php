<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Enums;

use Modules\CustomerSatisfaction\Domain\Enums\Concerns\EnumValues;

enum RecoveryStatus: string
{
    use EnumValues;

    case Open = 'open';
    case InProgress = 'in_progress';
    case PendingCustomer = 'pending_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function isFinal(): bool
    {
        return $this === self::Resolved || $this === self::Closed;
    }

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::PendingCustomer, self::Resolved, self::Closed],
            self::InProgress => [self::PendingCustomer, self::Resolved, self::Closed],
            self::PendingCustomer => [self::InProgress, self::Resolved, self::Closed],
            self::Resolved => [self::Closed, self::InProgress],
            self::Closed => [],
        };
    }
}
