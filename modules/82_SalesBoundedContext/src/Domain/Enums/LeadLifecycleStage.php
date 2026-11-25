<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum LeadLifecycleStage: string
{
    case Captured = 'captured';
    case Enriched = 'enriched';
    case Mql = 'mql';
    case Sql = 'sql';
    case Converted = 'converted';
    case Disqualified = 'disqualified';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
