<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Domain\Enums;

/**
 * The projections that can be reconstructed at a bi-temporal coordinate.
 */
enum QueryDomain: string
{
    case TrialBalance = 'trial_balance';
    case AccountLedger = 'account_ledger';
    case Aging = 'aging';
    case Inventory = 'inventory';
    case FixedAssets = 'fixed_assets';

    public function aggregateType(): AggregateType
    {
        return match ($this) {
            self::TrialBalance, self::AccountLedger => AggregateType::GeneralLedger,
            self::Aging => AggregateType::OpenItems,
            self::Inventory => AggregateType::InventoryWms,
            self::FixedAssets => AggregateType::FixedAssets,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
