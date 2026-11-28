<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;
use Modules\DairyCheeseManufacturing\Models\MilkDelivery;
use Modules\DairyCheeseManufacturing\Models\MilkTank;
use Modules\DairyCheeseManufacturing\Models\VatBatchMilkDraw;
use Modules\DairyCheeseManufacturing\Models\VatBatchTankUsage;

/**
 * Stock of a milk tank. The milk in a tank is the remaining quantity of its deliveries; a vat draws it first-in first-out
 * (collection date, morning before evening, registration order) and every draw keeps the delivery and the breeder it came from,
 * which is what makes the full upstream traceability possible.
 */
final class TankInventoryService
{
    /**
     * @return Builder<MilkDelivery>
     */
    public function stockQuery(string $tankId): Builder
    {
        return MilkDelivery::query()->where('tank_id', $tankId)->where('remaining_liters', '>', 0)->where('status', '!=', 'rejected');
    }

    /**
     * @return array{liters: float, kg: float}
     */
    public function volume(string $tankId): array
    {
        $row = $this->stockQuery($tankId)->selectRaw('COALESCE(SUM(remaining_liters), 0) as liters, COALESCE(SUM(remaining_kg), 0) as kg')->first();

        return ['liters' => round((float) ($row->liters ?? 0), 3), 'kg' => round((float) ($row->kg ?? 0), 3)];
    }

    /**
     * Draws the milk of one tank into a vat, FIFO. Must run inside the transaction of the vat creation.
     *
     * @return array{liters: float, kg: float}
     */
    public function draw(CheeseVatBatch $batch, MilkTank $tank, float $liters): array
    {
        if ($liters <= 0) {
            throw new DairyCheeseException('The quantity drawn from a tank must be positive.', 'quantity_not_positive');
        }

        if ($this->stockQuery($tank->id)->where('status', 'quarantined')->exists()) {
            throw new DairyCheeseException('The tank holds milk in quarantine.', 'tank_contains_quarantined_milk');
        }

        $layers = $this->stockQuery($tank->id)
            ->orderBy('collection_date')
            ->orderByRaw("CASE session WHEN 'morning' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        if (round((float) $layers->sum('remaining_liters'), 3) + 0.0005 < $liters) {
            throw new DairyCheeseException('The tank does not hold enough milk.', 'insufficient_milk');
        }

        $left = $liters;
        $totalKg = 0.0;

        foreach ($layers as $layer) {
            if ($left <= 0.0005) {
                break;
            }

            $take = min($left, (float) $layer->remaining_liters);
            $density = (float) $layer->density;
            $takeKg = round($take * $density, 3);
            $remaining = round((float) $layer->remaining_liters - $take, 3);

            $layer->forceFill(['remaining_liters' => $remaining, 'remaining_kg' => round($remaining * $density, 3)])->save();

            VatBatchMilkDraw::query()->create([
                'vat_batch_id' => $batch->id, 'tank_id' => $tank->id, 'delivery_id' => $layer->id, 'breeder_id' => $layer->breeder_id,
                'liters' => round($take, 3), 'kg' => $takeKg,
            ]);

            $left = round($left - $take, 3);
            $totalKg += $takeKg;
        }

        $usage = VatBatchTankUsage::query()->create(['vat_batch_id' => $batch->id, 'tank_id' => $tank->id, 'liters' => round($liters, 3), 'kg' => round($totalKg, 3)]);

        return ['liters' => (float) $usage->liters, 'kg' => (float) $usage->kg];
    }

    /**
     * Empties a delivery that was rejected: it no longer counts as stock.
     */
    public function discard(MilkDelivery $delivery): void
    {
        DB::transaction(static function () use ($delivery): void {
            $delivery->forceFill(['remaining_liters' => 0, 'remaining_kg' => 0, 'status' => 'rejected'])->save();
        });
    }
}
