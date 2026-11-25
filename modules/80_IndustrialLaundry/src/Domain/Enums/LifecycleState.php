<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

/**
 * Lifecycle of a tagged garment: clean in stock, delivered to the customer, soiled and returned, in the wash tunnel, in mending,
 * and finally condemned as a rag ("straccio").
 */
enum LifecycleState: string
{
    case CleanInStock = 'clean_in_stock';
    case DeliveredToCustomer = 'delivered_to_customer';
    case SoiledReturned = 'soiled_returned';
    case InWashTunnel = 'in_wash_tunnel';
    case InMending = 'in_mending';
    case CondemnedRag = 'condemned_rag';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::CleanInStock => [self::DeliveredToCustomer, self::SoiledReturned, self::InMending, self::CondemnedRag],
            self::DeliveredToCustomer => [self::SoiledReturned, self::InMending, self::CondemnedRag],
            self::SoiledReturned => [self::InWashTunnel, self::CleanInStock, self::DeliveredToCustomer, self::InMending, self::CondemnedRag],
            self::InWashTunnel => [self::CleanInStock, self::DeliveredToCustomer, self::SoiledReturned, self::InMending, self::CondemnedRag],
            self::InMending => [self::CleanInStock, self::CondemnedRag],
            self::CondemnedRag => [],
        };
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /**
     * A wash happened between the last delivery and the next one when the garment went through the soiled or tunnel state.
     */
    public function completesWash(): bool
    {
        return $this === self::SoiledReturned || $this === self::InWashTunnel;
    }

    public function isCirculating(): bool
    {
        return $this !== self::CondemnedRag;
    }
}
