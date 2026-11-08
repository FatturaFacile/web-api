<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

/**
 * What a new count session covers: a warehouse, optionally one zone, some bins or some items, counted as of today or
 * as of a past moment (`effectiveAt`, a retroactive stocktake). With `freezesStock` no stock may move in the counted
 * area until the differences are approved or the session is cancelled.
 */
final readonly class InventoryCountSessionDTO
{
    /**
     * @param  array<int, string>  $binIds
     * @param  array<int, string>  $stockItemIds
     */
    public function __construct(
        public string $warehouseId,
        public string $countType,
        public string $scheduledDate,
        public string $userId,
        public array $binIds = [],
        public ?string $sessionNumber = null,
        public ?string $zoneId = null,
        public ?string $notes = null,
        public ?string $effectiveAt = null,
        public bool $freezesStock = false,
        public array $stockItemIds = [],
        public ?string $planId = null
    ) {}
}
