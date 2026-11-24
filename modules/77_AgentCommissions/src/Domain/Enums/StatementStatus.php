<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Domain\Enums;

enum StatementStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Void = 'void';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
