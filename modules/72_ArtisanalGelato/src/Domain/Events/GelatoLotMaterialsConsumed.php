<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Domain\Events;

/**
 * A pasteurization or churning lot consumed raw materials. Inventory listeners deduct the stock lots named in the consumptions.
 */
final readonly class GelatoLotMaterialsConsumed
{
    /**
     * @param  list<array{ingredient_id?: string|null, raw_lot_code?: string|null, source_lot_id?: string|null, grams: float|int|string}>  $consumptions
     */
    public function __construct(public string $lotId, public array $consumptions) {}
}
