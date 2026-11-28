<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;

/**
 * @mixin CheeseVatBatch
 */
final class VatBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['tankUsages.tank', 'draws.breeder']);
        $milk = (float) $this->milk_kg;
        $composition = [];

        foreach ($this->draws->groupBy('breeder_id') as $draws) {
            $kg = round((float) $draws->sum('kg'), 3);
            $composition[] = ['breeder_id' => $draws->first()->breeder_id, 'breeder_name' => $draws->first()->breeder->name, 'kg' => number_format($kg, 3, '.', ''), 'pct' => number_format($milk > 0 ? $kg / $milk * 100 : 0, 3, '.', '')];
        }

        usort($composition, static fn (array $a, array $b): int => (float) $b['kg'] <=> (float) $a['kg']);

        return [
            'id' => $this->id, 'code' => $this->code, 'cheese_type' => $this->cheese_type, 'status' => $this->status, 'production_date' => $this->production_date->toDateString(),
            'vat_number' => $this->vat_number, 'milk_kg' => $this->milk_kg, 'starter_culture' => $this->starter_culture, 'starter_amount' => $this->starter_amount,
            'rennet_type' => $this->rennet_type, 'rennet_ml' => $this->rennet_ml, 'ph_log' => array_values((array) $this->ph_log), 'curd_yield_kg' => $this->curd_yield_kg,
            'fresh_yield_24h_kg' => $this->fresh_yield_24h_kg, 'fresh_yield_24h_pct' => $this->fresh_yield_24h_pct, 'final_aged_yield_kg' => $this->final_aged_yield_kg,
            'final_aged_yield_pct' => $this->final_aged_yield_pct, 'plaque_prefix' => $this->plaque_prefix, 'plaque_from' => $this->plaque_from, 'plaque_to' => $this->plaque_to,
            'wheels_count' => (int) $this->wheels_count,
            'tank_usages' => $this->tankUsages->map(static fn ($u): array => ['tank_id' => $u->tank_id, 'tank_code' => $u->tank->code, 'liters' => $u->liters, 'kg' => $u->kg])->values()->all(),
            'composition' => $composition,
        ];
    }
}
