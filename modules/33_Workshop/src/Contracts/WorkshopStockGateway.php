<?php

declare(strict_types=1);

namespace Modules\Workshop\Contracts;

use App\Exceptions\BusinessRuleException;

/**
 * What the workshop needs from stock, without knowing which module keeps it. The inventory module answers through
 * InventoryStockGateway; another gateway can replace it. Implementations work on the application database only and call
 * no external system, so the workshop may call them inside its own transactions.
 */
interface WorkshopStockGateway
{
    /**
     * Whether the product is stock-tracked: untracked products (services, consumables) never move stock.
     */
    public function isTracked(string $productId): bool;

    /**
     * Quantity the product can still be promised from the default warehouse, as a decimal string with four digits.
     */
    public function availableQuantity(string $productId): string;

    /**
     * Reserves the quantity for a workshop consumption and returns the reservation reference.
     *
     * @throws BusinessRuleException when the stock does not cover the quantity
     */
    public function reserve(string $productId, string $quantity, string $consumptionId): string;

    /**
     * Releases a reservation; releasing one that is no longer active does nothing.
     */
    public function release(string $reservationReference): void;

    /**
     * Issues the quantity out of stock for a folder (the reservation, when given, is released first) and returns the
     * stock movement reference.
     *
     * @throws BusinessRuleException when the stock does not cover the quantity
     */
    public function issue(string $productId, string $quantity, string $folderId, ?string $reservationReference): string;
}
