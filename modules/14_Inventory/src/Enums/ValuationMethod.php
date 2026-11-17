<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * Inventory valuation methods supported for statutory valuation (OIC 13 / IFRS IAS 2 / Swiss CO).
 * LIFO is intentionally not supported (see FatturaFacile/FatturaFacile#144 decisions).
 */
enum ValuationMethod: string
{
    case Fifo = 'fifo';
    case WeightedAverage = 'weighted_average';
    case StandardCost = 'standard_cost';

    /**
     * Resolves stored or legacy values: `moving_average`, `AVCO`, `FIFO`, `STANDARD`.
     */
    public static function fromStorage(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (strtolower(trim($value))) {
            'fifo' => self::Fifo,
            'weighted_average', 'moving_average', 'avco', 'wac' => self::WeightedAverage,
            'standard_cost', 'standard' => self::StandardCost,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $method): string => $method->value, self::cases());
    }

    /**
     * Accepted request values including legacy aliases.
     *
     * @return list<string>
     */
    public static function acceptedInputValues(): array
    {
        return [...self::values(), 'moving_average', 'FIFO', 'AVCO', 'STANDARD'];
    }
}
