<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Illuminate\Support\Collection;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Models\StockReservation;

/**
 * The reservation API of the inventory module: other modules (Sales, Projects, Manufacturing) reserve, look up and
 * release stock only through it, never by querying the reservation tables themselves.
 */
interface StockReservationServiceInterface
{
    public function allocate(StockReservationDTO $dto): StockReservation;

    public function release(StockReservation $reservation): void;

    /**
     * Releases the active reservations whose expiry has passed, announcing each one with StockReservationExpiredEvent
     * so the module that made it can update its own document; returns how many expired.
     */
    public function expireStaleReservations(): int;

    /**
     * The active reservations made for the given documents (for example `sales_order_item` and the ids of the order
     * lines), oldest first.
     *
     * @param  list<string>  $reservedForIds
     * @return Collection<int, StockReservation>
     */
    public function activeFor(string $reservedForType, array $reservedForIds): Collection;
}
