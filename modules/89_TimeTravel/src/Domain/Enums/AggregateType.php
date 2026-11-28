<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Domain\Enums;

/**
 * The four event streams of a tenant. A stream (tenant + aggregate type) has its own monotonic sequence and its own hash chain.
 */
enum AggregateType: string
{
    case GeneralLedger = 'general_ledger';
    case OpenItems = 'open_items';
    case InventoryWms = 'inventory_wms';
    case FixedAssets = 'fixed_assets';

    public function streamId(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
