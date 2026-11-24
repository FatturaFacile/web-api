<?php

declare(strict_types=1);

namespace Modules\ArtisanalGelato\Application\Listeners;

use Modules\ArtisanalGelato\Domain\Contracts\RawMaterialStockPort;
use Modules\ArtisanalGelato\Domain\Events\GelatoLotMaterialsConsumed;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoPasteurizationChurnLot;
use Modules\ArtisanalGelato\Infrastructure\Models\GelatoTechnicalIngredient;

/**
 * Deducts from inventory the raw materials a gelato lot consumed. Only ingredients linked to a stock item move stock; grams
 * are converted to stock units with the ingredient's `stock_unit_grams`. It runs synchronously inside the lot's transaction,
 * so a lot that consumes stock that does not exist is refused and nothing is recorded.
 */
final readonly class DeductGelatoRawMaterialsFromStock
{
    public function __construct(private RawMaterialStockPort $stock) {}

    public function handle(GelatoLotMaterialsConsumed $event): void
    {
        $lot = GelatoPasteurizationChurnLot::query()->find($event->lotId);

        if ($lot === null || $lot->tenant_id === null) {
            return;
        }

        /** @var array<string, array{item: string, lot: string|null, units: float}> $totals */
        $totals = [];
        /** @var array<string, GelatoTechnicalIngredient|null> $ingredients */
        $ingredients = [];

        foreach ($event->consumptions as $line) {
            $ingredientId = (string) ($line['ingredient_id'] ?? '');

            if ($ingredientId === '') {
                continue;
            }

            $ingredients[$ingredientId] ??= GelatoTechnicalIngredient::query()->find($ingredientId);
            $ingredient = $ingredients[$ingredientId];

            if ($ingredient === null || $ingredient->stock_item_id === null) {
                continue;
            }

            $rawLot = isset($line['raw_lot_code']) && $line['raw_lot_code'] !== '' ? (string) $line['raw_lot_code'] : null;
            $key = $ingredientId.'|'.($rawLot ?? '');
            $units = ((float) $line['grams']) / max((float) $ingredient->stock_unit_grams, 0.001);
            $totals[$key] = ['item' => (string) $ingredient->stock_item_id, 'lot' => $rawLot, 'units' => ($totals[$key]['units'] ?? 0.0) + $units];
        }

        foreach ($totals as $total) {
            if ($total['units'] > 0) {
                $this->stock->issue((string) $lot->tenant_id, $total['item'], $total['units'], $total['lot'], (string) $lot->id, $lot->operator_id !== null ? (string) $lot->operator_id : null);
            }
        }
    }
}
