<?php

declare(strict_types=1);

namespace Modules\DownPayments\Domain\Enums;

/** The kind of document a down-payment chain is built from. */
enum SourceType: string
{
    case SalesOrder = 'sales_order';
    case Quote = 'quote';
    case Contract = 'contract';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
