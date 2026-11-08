<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

/**
 * Tells the stock ledger where an outgoing document takes its stock from, for the duration of one call. The WMS
 * shipment confirmation uses it: the goods were picked into a shipping bin of a given warehouse, so the delivery note
 * it creates through the sales flow (which names no warehouse) must take them from that warehouse and that bin first,
 * not from the primary warehouse's default bin.
 *
 * Registered as a scoped singleton: the preference lives only inside `during()` and is always cleared afterwards.
 */
final class OutboundIssuePreference
{
    private ?string $warehouseId = null;

    /** @var list<string> */
    private array $preferredBinIds = [];

    /**
     * Runs the callback with the warehouse and bins preferred by every issue of stock that names no warehouse.
     *
     * @template TResult
     *
     * @param  list<string>  $binIds
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function during(?string $warehouseId, array $binIds, callable $callback): mixed
    {
        [$previousWarehouse, $previousBins] = [$this->warehouseId, $this->preferredBinIds];
        $this->warehouseId = $warehouseId;
        $this->preferredBinIds = array_values(array_unique($binIds));

        try {
            return $callback();
        } finally {
            $this->warehouseId = $previousWarehouse;
            $this->preferredBinIds = $previousBins;
        }
    }

    /**
     * The warehouse a document without a warehouse of its own uses inside `during()`; null outside.
     */
    public function warehouseId(): ?string
    {
        return $this->warehouseId;
    }

    /**
     * The bins to take stock from first, in order; empty outside `during()`.
     *
     * @return list<string>
     */
    public function preferredBinIds(): array
    {
        return $this->preferredBinIds;
    }
}
